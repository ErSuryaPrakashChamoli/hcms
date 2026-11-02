<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformanceWindowExtension;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity GO-2 (HROne "Extend goal", "Extend review", "Extend 360"; matrix 27 §27.2.2 #6–8, §27.3.3 #4, §27.4.1 #6):
 * the windows of a running performance cycle, per stage, with their extensions.
 *
 * A stage's own dates are frozen once the cycle is scheduled (PerformanceCycle::booted). An extension gives more time for
 * one stage — goal setting, self / manager / approver / peer review — to everyone in the cycle or to chosen employees, with
 * a new end date and a reason (append-only rows, audited; the latest end date wins). The effective end of a stage for an
 * employee is the latest of the stage's own end, an extension for everyone and an extension for that employee. It is what
 * the goal-period checks (Goals::assertGoalPeriodOpen), the review reminders (PerformanceReminders), the due dates on
 * review requests and in My tasks (the GO-9 task sources) read.
 *
 * 360 feedback rounds (HP-8h) share the service: "everyone" moves the round's own window (FeedbackRounds::extend, as
 * before); chosen employees get an extension of stage `feedback_360` on the round, which keeps answering and nominating
 * about them open until their date (FeedbackNominations), keeps the round open until the last extension passes
 * (FeedbackRounds::runSchedule) and moves their clients' link expiry.
 */
final class PerformanceWindows
{
    /** The stages whose window can be extended (calibration and the final rating are HR's own stages, not deadlines for others). */
    public const STAGES = ['goal_setting', 'self_review', 'manager_review', 'approver_review', 'peer_review'];

    /** The stage key of a 360 feedback round's window. */
    public const ROUND = 'feedback_360';

    public function __construct(private readonly AuditRecorder $audit, private readonly AccessScopes $scopes) {}

    /** The stage a review of this type is written in. */
    public static function stageOfReview(string $type): string
    {
        return match ($type) {
            'self' => 'self_review', 'manager' => 'manager_review', 'approver' => 'approver_review', default => 'peer_review'
        };
    }

    public static function stageLabel(string $stage): string
    {
        return $stage === self::ROUND ? '360 feedback' : (string) config("peopleos.performance.stages.{$stage}", $stage);
    }

    /** The stage's own end date (the cycle configuration), null when the stage has none or the cycle has no such stage. */
    public static function baseEnd(PerformanceCycle $cycle, string $stage): ?Carbon
    {
        $ends = collect($cycle->stages ?? [])->firstWhere('key', $stage)['ends_on'] ?? null;

        return filled($ends) ? Carbon::parse($ends)->startOfDay() : null;
    }

    private static function later(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->gte($b) ? $a : $b;
    }

    private static function date(mixed $value): ?Carbon
    {
        return filled($value) ? Carbon::parse(substr((string) $value, 0, 10))->startOfDay() : null;
    }

    /** The latest extension of a cycle's stage for everyone, or for everyone and one employee ($employeeId). */
    public function extensionEnd(PerformanceCycle $cycle, string $stage, ?int $employeeId = null): ?Carbon
    {
        return self::date(PerformanceWindowExtension::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->where('stage', $stage)
            ->where(fn ($q) => $q->whereNull('employee_id')->when($employeeId !== null, fn ($w) => $w->orWhere('employee_id', $employeeId)))
            ->max('ends_on'));
    }

    /**
     * The stage's end for an employee (null employee: for everyone): its own end, or a later extension. Null when the stage
     * has no end date.
     */
    public function stageEnd(PerformanceCycle $cycle, string $stage, ?int $employeeId = null): ?Carbon
    {
        $base = self::baseEnd($cycle, $stage);

        return $base === null ? null : self::later($base, $this->extensionEnd($cycle, $stage, $employeeId));
    }

    /**
     * The stage's end for many employees at once (one query): employee id => end (null when the stage has no end date).
     *
     * @param  iterable<int|string>  $employeeIds
     * @return array<int, ?Carbon>
     */
    public function stageEnds(PerformanceCycle $cycle, string $stage, iterable $employeeIds): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        $base = self::baseEnd($cycle, $stage);
        if ($base === null) {
            return $ids->mapWithKeys(fn (int $id) => [$id => null])->all();
        }
        $rows = PerformanceWindowExtension::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $cycle->id)->where('stage', $stage)
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhereIn('employee_id', $ids->all() ?: [0]))
            ->get(['employee_id', 'ends_on']);
        $everyone = self::later($base, self::date($rows->whereNull('employee_id')->max(fn ($r) => $r->ends_on?->toDateString())));
        $personal = $rows->whereNotNull('employee_id')->groupBy('employee_id')->map(fn (Collection $g) => self::date($g->max(fn ($r) => $r->ends_on?->toDateString())));

        return $ids->mapWithKeys(fn (int $id) => [$id => self::later($everyone, $personal[$id] ?? null)])->all();
    }

    /** The end of the review window of an appraisal's review of this type (the review's stage, as extended for the employee). */
    public function reviewDue(Appraisal $appraisal, string $type): ?Carbon
    {
        $appraisal->loadMissing('cycle');

        return $appraisal->cycle === null ? null : $this->stageEnd($appraisal->cycle, self::stageOfReview($type), (int) $appraisal->employee_id);
    }

    /**
     * Extend a stage of a running cycle until $endsOn, for everyone ($employeeIds null) or for the chosen employees of the
     * cycle (performance.manage, within the actor's access scope). Each chosen employee whose window already runs until
     * that date or later, who is not in the cycle, withdrawn or finalized, is skipped with the reason.
     *
     * @param  list<int|string>|null  $employeeIds
     * @return array{extended: int, skipped: array<int, string>, ends_on: string}
     */
    public function extend(PerformanceCycle $cycle, string $stage, ?array $employeeIds, string $endsOn, string $reason, User $actor): array
    {
        if (! $actor->hasPermission('performance.manage')) {
            throw new RuntimeException('You cannot extend performance windows.');
        }
        $reason = $this->reason($reason);
        if (! in_array($stage, self::STAGES, true)) {
            throw new RuntimeException('Only the goal-setting and review stages have windows to extend.');
        }
        $new = $this->newDate($endsOn);

        return DB::transaction(function () use ($cycle, $stage, $employeeIds, $new, $reason, $actor) {
            // One extension of a cycle at a time: the cycle row is the lock.
            $locked = PerformanceCycle::query()->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new RuntimeException('Only the windows of a running cycle can be extended.');
            }
            if (! $locked->hasStage($stage)) {
                throw new RuntimeException("{$locked->name} has no ".mb_strtolower(self::stageLabel($stage)).' stage.');
            }
            if (self::baseEnd($locked, $stage) === null) {
                throw new RuntimeException('The '.mb_strtolower(self::stageLabel($stage)).' stage has no end date to extend.');
            }

            if ($employeeIds === null) {
                $current = $this->stageEnd($locked, $stage);
                if ($new->lte($current)) {
                    throw new RuntimeException('Choose a date after '.$current->format('d M Y').', when the '.mb_strtolower(self::stageLabel($stage)).' window now ends.');
                }
                $this->audit->operation('performance', 'Extend window', function () use ($locked, $stage, $current, $new, $reason, $actor) {
                    PerformanceWindowExtension::create(['performance_cycle_id' => $locked->id, 'stage' => $stage, 'employee_id' => null,
                        'previous_ends_on' => $current->toDateString(), 'ends_on' => $new->toDateString(), 'reason' => $reason, 'created_by' => $actor->id]);

                    return 1;
                }, $reason, PerformanceWindowExtension::class);

                return ['extended' => 1, 'skipped' => [], 'ends_on' => $new->toDateString()];
            }

            $ids = $this->ids($employeeIds);
            $appraisals = Appraisal::query()->withoutGlobalScope(AccessScope::class)->with('employee.person')->where('performance_cycle_id', $locked->id)
                ->whereIn('employee_id', $ids)->get()->keyBy(fn (Appraisal $a) => (int) $a->employee_id);
            $ends = $this->stageEnds($locked, $stage, $ids);
            $skipped = [];
            $extend = [];
            foreach ($ids as $id) {
                $appraisal = $appraisals->get($id);
                $refusal = match (true) {
                    $appraisal === null => 'Not in this cycle.',
                    ! $this->scopes->allows($actor, $appraisal) => 'Outside your access scope.',
                    $appraisal->isWithdrawn() => 'Withdrawn from this cycle.',
                    $appraisal->isFinal() || $appraisal->isLocked() => 'The appraisal is finalized.',
                    $ends[$id] !== null && $new->lte($ends[$id]) => 'Already has until '.$ends[$id]->format('d M Y').'.',
                    default => null,
                };
                if ($refusal !== null) {
                    $skipped[$id] = $refusal;

                    continue;
                }
                $extend[$id] = $ends[$id];
            }
            if ($extend !== []) {
                $this->audit->operation('performance', 'Extend window', function () use ($locked, $stage, $extend, $new, $reason, $actor) {
                    foreach ($extend as $id => $previous) {
                        PerformanceWindowExtension::create(['performance_cycle_id' => $locked->id, 'stage' => $stage, 'employee_id' => $id,
                            'previous_ends_on' => $previous?->toDateString(), 'ends_on' => $new->toDateString(), 'reason' => $reason, 'created_by' => $actor->id]);
                    }

                    return ['succeeded' => count($extend), 'ids' => array_keys($extend)];
                }, $reason, PerformanceWindowExtension::class);
            }

            return ['extended' => count($extend), 'skipped' => $skipped, 'ends_on' => $new->toDateString()];
        });
    }

    // --- 360 feedback rounds (HP-8h) ---------------------------------------------------------------------------------

    /** The latest extension of a round for one employee (rounds' own "everyone" extensions move window_closes_on). */
    public function roundExtensionEnd(FeedbackRound $round, ?int $employeeId): ?Carbon
    {
        return $employeeId === null ? null : self::date(PerformanceWindowExtension::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->where('stage', self::ROUND)
            ->where('employee_id', $employeeId)->max('ends_on'));
    }

    /** When the round's window closes for answers and nominations about this employee. */
    public function roundClosesOn(FeedbackRound $round, ?int $employeeId): Carbon
    {
        return self::later($round->window_closes_on->copy()->startOfDay(), $this->roundExtensionEnd($round, $employeeId));
    }

    /** Whether answers about this employee are taken now: the round is open, its window has started and has not closed for them. */
    public function roundOpenFor(FeedbackRound $round, ?int $employeeId): bool
    {
        $today = today()->toDateString();

        return $round->isOpen() && $round->window_opens_on->toDateString() <= $today && $this->roundClosesOn($round, $employeeId)->toDateString() >= $today;
    }

    /** The last day anyone may still answer in the round: its window, or the latest extension for an employee. */
    public function roundLastDay(FeedbackRound $round): Carbon
    {
        return self::later($round->window_closes_on->copy()->startOfDay(), self::date(PerformanceWindowExtension::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->max('ends_on')));
    }

    /**
     * Extend a 360 round for everyone (its own window, FeedbackRounds::extend — as before) or for chosen employees of the
     * round (performance.feedback_360, within the actor's access scope): their reviewers and nominators get until $closesOn,
     * and their clients' links follow.
     *
     * @param  list<int|string>|null  $employeeIds
     * @return array{extended: int, skipped: array<int, string>, ends_on: string}
     */
    public function extendRound(FeedbackRound $round, ?array $employeeIds, string $closesOn, string $reason, User $actor): array
    {
        if ($employeeIds === null) {
            $extended = app(FeedbackRounds::class)->extend($round, $closesOn, $reason, $actor);

            return ['extended' => 1, 'skipped' => [], 'ends_on' => $extended->window_closes_on->toDateString()];
        }
        if (! FeedbackRounds::mayManage($actor)) {
            throw new RuntimeException('You cannot extend 360 feedback rounds.');
        }
        $reason = $this->reason($reason);
        $new = $this->newDate($closesOn);
        $ids = $this->ids($employeeIds);

        return DB::transaction(function () use ($round, $ids, $new, $reason, $actor) {
            $locked = FeedbackRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('Only an open round can be extended.');
            }
            $participants = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with('employee.person')->where('feedback_round_id', $locked->id)
                ->whereIn('employee_id', $ids)->get()->keyBy(fn (FeedbackParticipant $p) => (int) $p->employee_id);
            $skipped = [];
            $extend = [];
            foreach ($ids as $id) {
                $participant = $participants->get($id);
                $current = $participant === null ? null : $this->roundClosesOn($locked, $id);
                $refusal = match (true) {
                    $participant === null => 'Not in this 360 round.',
                    ! $this->scopes->allows($actor, $participant) => 'Outside your access scope.',
                    $new->lte($current) => 'Already has until '.$current->format('d M Y').'.',
                    default => null,
                };
                if ($refusal !== null) {
                    $skipped[$id] = $refusal;

                    continue;
                }
                $extend[$id] = $current;
            }
            if ($extend !== []) {
                $this->audit->operation('performance', 'Extend 360 window', function () use ($locked, $extend, $new, $reason, $actor) {
                    foreach ($extend as $id => $previous) {
                        PerformanceWindowExtension::create(['feedback_round_id' => $locked->id, 'performance_cycle_id' => $locked->performance_cycle_id, 'stage' => self::ROUND,
                            'employee_id' => $id, 'previous_ends_on' => $previous->toDateString(), 'ends_on' => $new->toDateString(), 'reason' => $reason, 'created_by' => $actor->id]);
                    }
                    // Their clients' links follow the new date.
                    FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $locked->id)->whereIn('employee_id', array_keys($extend))
                        ->where('status', 'requested')->whereNotNull('token_hash')->get()
                        ->each(fn (FeedbackNomination $n) => $n->update(['token_expires_at' => $new->copy()->endOfDay()]));

                    return ['succeeded' => count($extend), 'ids' => array_keys($extend)];
                }, $reason, PerformanceWindowExtension::class);
            }

            return ['extended' => count($extend), 'skipped' => $skipped, 'ends_on' => $new->toDateString()];
        });
    }

    // --- Shared -------------------------------------------------------------------------------------------------------

    /** @return Collection<int, PerformanceWindowExtension> a cycle's (or a round's) extensions, latest first */
    public function extensionsOf(PerformanceCycle|FeedbackRound $subject): Collection
    {
        return PerformanceWindowExtension::query()->with(['employee.person', 'creator'])
            ->where($subject instanceof FeedbackRound ? 'feedback_round_id' : 'performance_cycle_id', $subject->id)
            ->orderByDesc('id')->get();
    }

    /** A short summary for notifications: "3 extended; 1 skipped (E004: Already has until 30 Nov 2026)". */
    public static function summary(array $result): string
    {
        $skipped = $result['skipped'] ?? [];
        $names = Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', array_keys($skipped) ?: [0])->pluck('employee_code', 'id');
        $lines = collect($skipped)->take(5)->map(fn (string $why, int $id) => ($names[$id] ?? '#'.$id).': '.$why)->values()->implode('; ');

        return ($result['extended'] ?? 0).' extended until '.Carbon::parse($result['ends_on'])->format('d M Y')
            .($skipped === [] ? '' : '; '.count($skipped).' skipped ('.$lines.(count($skipped) > 5 ? '; …' : '').')');
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Say why the window is extended.');
        }
        if (mb_strlen($reason) > 500) {
            throw new RuntimeException('Keep the reason under 500 characters.');
        }

        return $reason;
    }

    private function newDate(string $date): Carbon
    {
        try {
            $new = Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException('Choose the new end date.');
        }
        if ($new->lt(today())) {
            throw new RuntimeException('The new end date cannot be in the past.');
        }

        return $new;
    }

    /**
     * @param  list<int|string>  $employeeIds
     * @return list<int>
     */
    private function ids(array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn (int $id) => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('Choose at least one employee.');
        }
        $max = max(1, (int) config('peopleos.bulk.max_records', 5000));
        if (count($ids) > $max) {
            throw new RuntimeException("Choose at most {$max} employees at a time.");
        }

        return $ids;
    }
}
