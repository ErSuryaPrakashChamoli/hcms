<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\PerformanceCycle;
use App\Domain\Performance\Models\PerformancePeriodReview;
use App\Domain\Performance\Support\AppraisalVisibility;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-8i (PF-7; HR Desk → Performance → "Initiate review"): periodic reviews inside an appraisal cycle.
 *
 * - plan: when a cycle is launched, each appraisal whose employee's performance policy runs a review cycle shorter than the
 *   appraisal cycle gets one review per period (scheduled, with its window, rated by the appraisal's manager). No policy,
 *   or a yearly review cycle: nothing (as before).
 * - the schedule (peopleos:performance:periodic-reviews, daily) opens reviews whose window has started (unless the policy
 *   asks for manual initiation) and marks those whose window has passed unrated as missed; initiate opens them by hand
 *   (performance administrators, or holders of the policy's initiating roles).
 * - submit: the reviewer (or a performance administrator) rates an open review inside its window, on the cycle's scale
 *   (above its maximum only when the policy allows); the appraisal is scored again, so the policy's roll-up applies.
 * - normalise (HROne parity GO-3, "Normalize review rating"): HR (performance.calibrate) sets the normalised rating of a
 *   submitted review of one review period, with a reason — one at a time or as a batch — within their access scope and
 *   never about themselves. The reviewer's rating stays as submitted; the normalised one is what the roll-up reads.
 */
final class PeriodicReviews
{
    public function __construct(
        private readonly PerformancePolicyRules $rules,
        private readonly PerformanceRelationships $relationships,
        private readonly AccessScopes $scopes,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return int the reviews planned for the cycle's appraisals */
    public function plan(PerformanceCycle $cycle): int
    {
        $planned = 0;
        Appraisal::query()->withoutGlobalScope(AccessScope::class)->with('employee')->where('performance_cycle_id', $cycle->id)->where('status', '!=', 'withdrawn')->orderBy('id')->get()
            ->each(function (Appraisal $appraisal) use ($cycle, &$planned) {
                $planned += $this->planFor($cycle, $appraisal);
            });

        return $planned;
    }

    /** HROne parity GO-2: the periodic reviews of one appraisal (an employee added to the running cycle). */
    public function planFor(PerformanceCycle $cycle, Appraisal $appraisal): int
    {
        $planned = 0;
        $appraisal->loadMissing('employee');
        foreach ($this->rules->periods($cycle, $this->rules->policyFor($appraisal->employee)) as $period) {
            $review = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->firstOrCreate(
                ['appraisal_id' => $appraisal->id, 'sequence' => $period['sequence']],
                ['performance_cycle_id' => $cycle->id, 'employee_id' => $appraisal->employee_id, 'reviewer_id' => $appraisal->manager_id, 'status' => 'scheduled'] + $period,
            );
            $planned += $review->wasRecentlyCreated ? 1 : 0;
        }

        return $planned;
    }

    /** @return array{opened: int, missed: int} */
    public function runSchedule(): array
    {
        $today = today()->toDateString();
        $active = PerformanceCycle::query()->where('status', 'active')->pluck('id')->all();
        $base = fn () => PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->whereIn('performance_cycle_id', $active ?: [0])
            ->whereHas('appraisal', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->whereNull('locked_at')->whereNotIn('status', ['finalized', 'acknowledged', 'withdrawn']));
        $missed = 0;
        $base()->whereIn('status', ['scheduled', 'open'])->where('window_closes_on', '<', $today)->get()
            ->each(function (PerformancePeriodReview $review) use (&$missed) {
                $review->update(['status' => 'missed']);
                $missed++;
            });
        $opened = 0;
        $base()->where('status', 'scheduled')->where('manual', false)->where('window_opens_on', '<=', $today.' 23:59:59')->where('window_closes_on', '>=', $today)->get()
            ->each(function (PerformancePeriodReview $review) use (&$opened) {
                $this->open($review, null);
                $opened++;
            });

        return ['opened' => $opened, 'missed' => $missed];
    }

    /**
     * Open the scheduled reviews of a period by hand (all employees of the cycle, or the given ones). A review whose window
     * has already passed gets a new window from today for the policy's window days.
     *
     * @param  list<int>|null  $employeeIds
     */
    public function initiate(PerformanceCycle $cycle, int $sequence, ?array $employeeIds, User $actor): int
    {
        if ($cycle->status !== 'active') {
            throw new RuntimeException('Periodic reviews open in an active cycle.');
        }
        $opened = 0;
        PerformancePeriodReview::query()->with('employee')->where('performance_cycle_id', $cycle->id)->where('sequence', $sequence)->where('status', 'scheduled')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))->orderBy('id')->get()
            ->each(function (PerformancePeriodReview $review) use ($actor, &$opened) {
                if (! $this->rules->mayInitiate($actor, $review->employee)) {
                    return;
                }
                $this->open($review, $actor);
                $opened++;
            });
        if ($opened === 0) {
            throw new RuntimeException('No scheduled periodic review that you may open was found.');
        }

        return $opened;
    }

    public function submit(PerformancePeriodReview $review, float $rating, ?string $comment, User $actor): PerformancePeriodReview
    {
        $submitted = DB::transaction(function () use ($review, $rating, $comment, $actor) {
            $locked = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->with(['appraisal.cycle.templateVersion', 'appraisal.employee'])->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if (! $this->mayRate($actor, $locked)) {
                throw new RuntimeException('This periodic review is not yours to rate.');
            }
            $appraisal = $locked->appraisal;
            if ($appraisal->isFinal() || $appraisal->isLocked()) {
                throw new RuntimeException('The appraisal is finalized.');
            }
            if ($locked->status !== 'open') {
                throw new RuntimeException($locked->status === 'submitted' ? 'This periodic review was already submitted.' : 'This periodic review is not open.');
            }
            if ($locked->window_closes_on->toDateString() < today()->toDateString()) {
                throw new RuntimeException('The window of this periodic review has closed.');
            }
            $scale = $appraisal->cycle->ratingScale();
            $max = $this->rules->maxRating($appraisal, $scale);
            if ($rating < $scale->min() || $rating > $max) {
                throw new RuntimeException("Ratings must be between {$scale->min()} and {$max}.");
            }
            $locked->update(['rating' => $rating, 'comment' => $comment === null ? null : mb_strimwidth($comment, 0, 2000, '…'), 'status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);

            return $locked;
        });
        app(Appraisals::class)->score($submitted->appraisal->refresh());

        return $submitted->refresh();
    }

    /** The reviewer (the appraisal's manager when planned) or a performance administrator. */
    public function mayRate(User $user, PerformancePeriodReview $review): bool
    {
        if ($user->hasPermission('performance.manage')) {
            return true;
        }
        $me = $this->relationships->forUser($user);

        return $me !== null && $review->reviewer_id !== null && (int) $review->reviewer_id === (int) $me->id && (int) $review->employee_id !== (int) $me->id;
    }

    /**
     * Who sees a periodic review's rating and comment: those who see the appraisal's reviews (HR, managers, the approver);
     * the employee once it is submitted, unless the performance policy keeps ratings and comments from them.
     */
    public function visibleTo(User $user, PerformancePeriodReview $review): bool
    {
        $appraisal = $review->appraisal;
        if (AppraisalVisibility::isPrivileged($appraisal, $user)) {
            return true;
        }

        return AppraisalVisibility::isOwn($appraisal, $user) && $review->status === 'submitted' && $this->rules->visibility($appraisal, 'ratings') === 'all';
    }

    // --- Normalisation (HROne parity GO-3) --------------------------------------------------------------------------

    /** Why the person may not normalise this periodic review's rating; null = they may. */
    public function normaliseRefusal(PerformancePeriodReview $review, User $actor): ?string
    {
        if (! $actor->hasPermission('performance.calibrate')) {
            return 'You cannot normalise review ratings.';
        }
        if (! $this->scopes->allows($actor, $review)) {
            return 'Outside your access scope.';
        }
        $me = $this->relationships->forUser($actor);
        if ($me !== null && (int) $me->id === (int) $review->employee_id) {
            return 'You cannot normalise your own rating.';
        }
        $appraisal = $review->relationLoaded('appraisal') ? $review->appraisal
            : Appraisal::query()->withoutGlobalScope(AccessScope::class)->find($review->appraisal_id);
        if ($appraisal === null || $appraisal->isWithdrawn() || $review->status === 'withdrawn') {
            return Appraisals::WITHDRAWN;
        }
        if ($appraisal->isFinal() || $appraisal->isLocked()) {
            return 'The appraisal is finalized.';
        }
        if ($review->status !== 'submitted') {
            return 'Only a submitted periodic review can be normalised.';
        }

        return null;
    }

    /**
     * Normalise one submitted periodic review's rating, with a reason (at most 500 characters). Under the appraisal row lock
     * (then the review's), the appraisal must still be open and the review submitted; $expected is the rating the person saw
     * (the normalised one, else the reviewer's) — a mismatch means someone changed it first. The rating lies on the cycle's
     * scale (above its maximum only when the performance policy allows). The change is audited with the reason (sensitive)
     * and the appraisal is scored again, so the performance policy's roll-up reads the normalised rating.
     */
    public function normalise(PerformancePeriodReview $review, float $rating, string $reason, User $actor, ?float $expected = null): PerformancePeriodReview
    {
        $reason = RatingNormalisation::reason($reason);
        if (($refusal = $this->normaliseRefusal($review, $actor)) !== null) {
            throw new RuntimeException($refusal);
        }
        $normalised = DB::transaction(function () use ($review, $rating, $reason, $actor, $expected) {
            $appraisal = Appraisal::query()->withoutGlobalScope(AccessScope::class)->with(['cycle.templateVersion', 'employee'])->whereKey($review->appraisal_id)->lockForUpdate()->firstOrFail();
            if ($appraisal->isWithdrawn()) {
                throw new RuntimeException(Appraisals::WITHDRAWN);
            }
            if ($appraisal->isFinal() || $appraisal->isLocked()) {
                throw new RuntimeException('The appraisal is finalized.');
            }
            $locked = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'submitted') {
                throw new RuntimeException('Only a submitted periodic review can be normalised.');
            }
            $previous = $locked->effectiveRating();
            if ($expected !== null && ($previous === null || abs($previous - $expected) > 0.001)) {
                throw new RuntimeException('The rating was changed by someone else. Reload and try again.');
            }
            $scale = $appraisal->cycle->ratingScale();
            $max = $this->rules->maxRating($appraisal, $scale);
            if ($rating < $scale->min() || $rating > $max) {
                throw new RuntimeException("Ratings must be between {$scale->min()} and {$max}.");
            }
            $locked->withAuditReason($reason)->update(['normalised_rating' => $rating, 'normalisation_note' => $reason, 'normalised_by' => $actor->id, 'normalised_at' => now()]);

            return $locked;
        });
        app(Appraisals::class)->score(Appraisal::query()->withoutGlobalScope(AccessScope::class)->findOrFail($normalised->appraisal_id));

        return $normalised->refresh();
    }

    /**
     * A batch of one review period of the cycle: each change [review id => ['rating' => …, 'expected' => …]] on its own,
     * refusals reported with their reason; one audit operation for the batch (the reason on it).
     *
     * @param  array<int|string, array{rating: float|int|string, expected?: float|int|string|null}>  $changes
     * @return array{done: list<int>, refused: array<int, string>}
     */
    public function normaliseBatch(PerformanceCycle $cycle, int $sequence, array $changes, string $reason, User $actor): array
    {
        $reason = RatingNormalisation::reason($reason);
        if ($changes === []) {
            throw new RuntimeException('Enter at least one normalised rating.');
        }
        $done = [];
        $refused = [];
        $this->audit->operation('performance', "Normalise review ratings: {$cycle->name} #{$sequence}", function () use ($cycle, $sequence, $changes, $reason, $actor, &$done, &$refused) {
            $reviews = PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->with('appraisal')
                ->where('performance_cycle_id', $cycle->id)->where('sequence', $sequence)->whereKey(array_map('intval', array_keys($changes)))->get()->keyBy('id');
            foreach ($changes as $id => $change) {
                $review = $reviews->get((int) $id);
                if ($review === null) {
                    $refused[(int) $id] = 'Not a periodic review of this period.';

                    continue;
                }
                try {
                    $this->normalise($review, RatingNormalisation::number($change['rating'] ?? null) ?? throw new RuntimeException('Enter a rating.'), $reason, $actor, RatingNormalisation::number($change['expected'] ?? null));
                    $done[] = (int) $id;
                } catch (RuntimeException $e) {
                    $refused[(int) $id] = $e->getMessage();
                }
            }

            return ['succeeded' => count($done), 'failed' => count($refused), 'ids' => $done];
        }, $reason, PerformancePeriodReview::class);

        return ['done' => $done, 'refused' => $refused];
    }

    /**
     * The review periods of a cycle with submitted reviews to normalise (sequence => "#2 · Jul – Sep 2026 (12 rated)").
     *
     * @return array<int, string>
     */
    public function periodsToNormalise(PerformanceCycle $cycle): array
    {
        return PerformancePeriodReview::query()->where('performance_cycle_id', $cycle->id)->where('status', 'submitted')
            ->whereHas('appraisal', fn ($q) => $q->where('status', '!=', 'withdrawn'))
            ->orderBy('sequence')->get(['id', 'sequence', 'period_start', 'period_end', 'employee_id', 'appraisal_id'])->groupBy('sequence')
            ->map(fn ($reviews, $sequence) => '#'.$sequence.' · '.$reviews->first()->label().' ('.$reviews->count().' rated)')->all();
    }

    /**
     * The grid of one review period for the chosen employees: one row per submitted review the person may normalise —
     * employee, code, the reviewer's rating, the normalised rating so far, the rating the person sees — alphabetically;
     * and how many were left out, by reason.
     *
     * @param  list<int>  $employeeIds
     * @return array{rows: list<array{review_id: int, employee: ?string, code: ?string, system: ?float, normalised: ?float, expected: ?float}>, skipped: array<string, int>}
     */
    public function normaliseGrid(PerformanceCycle $cycle, int $sequence, array $employeeIds, User $actor): array
    {
        $rows = [];
        $skipped = [];
        PerformancePeriodReview::query()->withoutGlobalScope(AccessScope::class)->with(['employee.person', 'appraisal'])
            ->where('performance_cycle_id', $cycle->id)->where('sequence', $sequence)->where('status', 'submitted')
            ->whereIn('employee_id', $employeeIds ?: [0])->get()
            ->reject(fn (PerformancePeriodReview $r) => $r->appraisal === null || $r->appraisal->isWithdrawn())
            ->sortBy(fn (PerformancePeriodReview $r) => mb_strtolower(($r->employee?->person?->full_name ?? '').' '.$r->employee?->employee_code))
            ->each(function (PerformancePeriodReview $r) use ($actor, &$rows, &$skipped) {
                if (($refusal = $this->normaliseRefusal($r, $actor)) !== null) {
                    $skipped[$refusal] = ($skipped[$refusal] ?? 0) + 1;

                    return;
                }
                $system = RatingNormalisation::number($r->rating);
                $normalised = RatingNormalisation::number($r->normalised_rating);
                $rows[] = ['review_id' => (int) $r->id, 'employee' => $r->employee?->person?->full_name, 'code' => $r->employee?->employee_code,
                    'system' => $system, 'normalised' => $normalised, 'expected' => $normalised ?? $system];
            });

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function open(PerformancePeriodReview $review, ?User $actor): void
    {
        $changes = ['status' => 'open', 'opened_by' => $actor?->id, 'opened_at' => now()];
        if ($review->window_closes_on->toDateString() < today()->toDateString() || $review->window_opens_on->toDateString() > today()->toDateString()) {
            $days = max(1, $review->window_opens_on->diffInDays($review->window_closes_on) + 1);
            $changes += ['window_opens_on' => today()->toDateString(), 'window_closes_on' => today()->copy()->addDays((int) $days - 1)->toDateString()];
        }
        $review->update($changes);
        try {
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->find($review->employee_id);
            PerformanceEvent::dispatch('performance.period_review.opened', $employee, $review, ['for' => $employee?->person?->full_name, 'period' => $review->label()], array_filter([(int) $review->reviewer_id]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
