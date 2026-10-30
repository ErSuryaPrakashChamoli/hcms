<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Models\Employee;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\FeedbackNomination;
use App\Domain\Performance\Models\FeedbackParticipant;
use App\Domain\Performance\Models\FeedbackRound;
use App\Domain\Performance\Models\PerformanceCycle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * HROne setup parity HP-8h (PF-1; HR Desk → Performance → "Initiate 360 feedback", "Extend 360"): 360 feedback rounds.
 *
 * - launch: a round of a 360 policy for a period of its frequency (the one containing the given day, unless dates are
 *   given), for the policy's population (every employed employee the policy applies to) or for chosen employees under
 *   it; the window comes from the policy unless given. The policy's answers and its sources' feedback templates are
 *   copied onto the round. An employee already in an open round of the same policy and period is left out.
 * - extend: a later closing date (reason required; clients' links follow); close (HR, or the schedule once the window has
 *   passed): answers stop, results become visible as the policy says, linked appraisals are scored again; cancel (reason).
 * - the schedule (peopleos:performance:feedback-rounds, daily): closes rounds whose window has passed, and launches the
 *   round of the current period for policies that ask for automatic launch, once its window has opened.
 *
 * Needs performance.feedback_360 (HR), within the access scope; the schedule acts without a user.
 */
final class FeedbackRounds
{
    public function __construct(
        private readonly Feedback360Rules $rules,
        private readonly FeedbackNominations $nominations,
        private readonly AccessScopes $scopes,
        private readonly AuditRecorder $audit,
    ) {}

    public static function mayManage(?User $user): bool
    {
        return $user !== null && $user->hasPermission('performance.feedback_360');
    }

    /** @return Collection<int, Employee> employed employees the 360 policy applies to on the date */
    public function population(Policy $policy, CarbonInterface $on): Collection
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->with(['person', 'currentManager.manager'])->employed()->orderBy('employee_code')->get()
            ->filter(fn (Employee $e) => (int) $this->rules->policyFor($e, $on)?->policy_id === (int) $policy->id)->values();
    }

    /**
     * @param  array{name?: ?string, day?: ?string, period_start?: ?string, period_end?: ?string, window_opens_on?: ?string, window_closes_on?: ?string, employee_ids?: ?list<int|string>, performance_cycle_id?: int|string|null}  $data
     */
    public function launch(Policy $policy, array $data, ?User $actor, bool $auto = false): FeedbackRound
    {
        if ($actor === null ? ! $auto : ! self::mayManage($actor)) {
            throw new RuntimeException('You cannot launch 360 feedback rounds.');
        }
        $policy = Policy::query()->whereKey($policy->getKey())->where('type', 'feedback_360')->where('status', 'active')->first();
        if ($policy === null) {
            throw new RuntimeException('Choose an active 360 feedback policy.');
        }
        $version = $policy->versionEffectiveOn(today()) ?? throw new RuntimeException('The 360 policy has no published version in force.');
        $settings = (array) $version->settings;
        $templates = $this->templates($settings);

        $day = Carbon::parse($data['day'] ?? today()->toDateString());
        [$periodStart, $periodEnd] = filled($data['period_start'] ?? null) && filled($data['period_end'] ?? null)
            ? [Carbon::parse($data['period_start']), Carbon::parse($data['period_end'])]
            : Feedback360Rules::periodContaining($settings, $day);
        if ($periodEnd->lt($periodStart)) {
            throw new RuntimeException('The period ends before it starts.');
        }
        [$opens, $closes] = Feedback360Rules::window($settings, $periodStart, $periodEnd);
        $opens = filled($data['window_opens_on'] ?? null) ? Carbon::parse($data['window_opens_on']) : $opens;
        $closes = filled($data['window_closes_on'] ?? null) ? Carbon::parse($data['window_closes_on']) : $closes;
        if ($closes->lt($opens) || $closes->lt(today())) {
            throw new RuntimeException('The window must close on or after it opens, and not in the past.');
        }
        $cycleId = filled($data['performance_cycle_id'] ?? null) ? (int) $data['performance_cycle_id'] : null;
        if ($cycleId !== null && ! PerformanceCycle::query()->whereKey($cycleId)->whereIn('status', ['draft', 'scheduled', 'active'])->exists()) {
            throw new RuntimeException('Link the round to a performance cycle that is not closed.');
        }

        $employees = $this->population($policy, today());
        if (! empty($data['employee_ids'])) {
            $wanted = array_map('intval', (array) $data['employee_ids']);
            $outside = array_diff($wanted, $employees->pluck('id')->all());
            if ($outside !== []) {
                throw new RuntimeException('Some chosen employees are not under this 360 policy.');
            }
            $employees = $employees->whereIn('id', $wanted)->values();
        }
        if ($actor !== null && $this->scopes->isScoped($actor)) {
            $inScope = array_map('intval', $this->scopes->employeeKeys($actor));
            $employees = $employees->filter(fn (Employee $e) => in_array((int) $e->id, $inScope, true))->values();
        }
        $busy = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $employees->pluck('id')->all() ?: [0])
            ->whereHas('round', fn ($q) => $q->where('policy_id', $policy->id)->where('status', 'open')->where('period_start', '<=', $periodEnd->toDateString().' 23:59:59')->where('period_end', '>=', $periodStart->toDateString()))
            ->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $employees = $employees->reject(fn (Employee $e) => in_array((int) $e->id, $busy, true))->values();
        if ($employees->isEmpty()) {
            throw new RuntimeException('Nobody to include: no employee under this policy is free for this period.');
        }

        $round = DB::transaction(function () use ($policy, $data, $settings, $templates, $periodStart, $periodEnd, $opens, $closes, $cycleId, $employees, $actor, $auto) {
            $round = FeedbackRound::query()->create([
                'policy_id' => $policy->id, 'performance_cycle_id' => $cycleId,
                'name' => mb_strimwidth(trim((string) ($data['name'] ?? '')) ?: $policy->name.' · '.$periodStart->format('M Y').' – '.$periodEnd->format('M Y'), 0, 160, '…'),
                'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString(),
                'window_opens_on' => $opens->toDateString(), 'window_closes_on' => $closes->toDateString(),
                'status' => 'open', 'auto' => $auto, 'settings' => $settings, 'templates' => $templates, 'launched_by' => $actor?->id,
            ]);
            foreach ($employees as $employee) {
                $this->nominations->seedParticipant($round, $employee, $actor);
            }
            $this->audit->record(AuditAction::StatusChange, 'performance', $round, [], null, actor: $actor, metadata: ['event' => '360_round_launched', 'participants' => $employees->count(), 'auto' => $auto]);

            return $round;
        });
        $this->askNominators($round);

        return $round->refresh();
    }

    public function extend(FeedbackRound $round, string $closesOn, string $reason, User $actor): FeedbackRound
    {
        if (! self::mayManage($actor)) {
            throw new RuntimeException('You cannot extend 360 feedback rounds.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for extending the window.');
        }
        $extended = DB::transaction(function () use ($round, $closesOn, $reason) {
            $locked = FeedbackRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            $new = Carbon::parse($closesOn)->startOfDay();
            if (! $locked->isOpen()) {
                throw new RuntimeException('Only an open round can be extended.');
            }
            if ($new->lte($locked->window_closes_on) || $new->lt(today())) {
                throw new RuntimeException('Choose a closing date after '.$locked->window_closes_on->format('d M Y').' and not in the past.');
            }
            $locked->withAuditReason($reason)->update(['window_closes_on' => $new->toDateString(), 'extensions' => $locked->extensions + 1]);

            return $locked;
        });
        $this->nominations->refreshLinkExpiry($extended);

        return $extended->refresh();
    }

    /** Close the round (HR, or the schedule with $actor null once the window has passed). */
    public function close(FeedbackRound $round, ?User $actor, ?string $reason = null): FeedbackRound
    {
        if ($actor !== null && ! self::mayManage($actor)) {
            throw new RuntimeException('You cannot close 360 feedback rounds.');
        }
        $closed = DB::transaction(function () use ($round, $reason) {
            $locked = FeedbackRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('The round is not open.');
            }
            $locked->withAuditReason($reason ?? 'Window closed')->update(['status' => 'closed', 'closed_at' => now()]);
            // Clients' links stop working; nothing more is asked.
            FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $locked->id)->whereNotNull('token_hash')
                ->get()->each(fn (FeedbackNomination $n) => $n->update(['token_hash' => null, 'token_expires_at' => null]));

            return $locked;
        });
        $this->afterClose($closed);

        return $closed->refresh();
    }

    public function cancel(FeedbackRound $round, string $reason, User $actor): FeedbackRound
    {
        if (! self::mayManage($actor)) {
            throw new RuntimeException('You cannot cancel 360 feedback rounds.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the round is cancelled.');
        }

        return DB::transaction(function () use ($round, $reason) {
            $locked = FeedbackRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('Only an open round can be cancelled.');
            }
            $locked->withAuditReason($reason)->update(['status' => 'cancelled', 'cancel_reason' => mb_strimwidth($reason, 0, 500), 'closed_at' => now()]);
            FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $locked->id)->whereIn('status', ['draft', 'requested'])
                ->get()->each(fn (FeedbackNomination $n) => $n->update(['status' => 'cancelled', 'token_hash' => null, 'token_expires_at' => null]));

            return $locked->refresh();
        });
    }

    /**
     * The daily schedule: close rounds whose window has passed, then launch the current period's round of every 360 policy
     * that asks for automatic launch, once its window has opened (once per policy and period).
     *
     * @return array{closed: int, launched: int}
     */
    public function runSchedule(): array
    {
        $closed = 0;
        $launched = 0;
        FeedbackRound::query()->where('status', 'open')->where('window_closes_on', '<', today()->toDateString())->orderBy('id')->get()
            ->each(function (FeedbackRound $round) use (&$closed) {
                $this->close($round, null, 'The window has passed');
                $closed++;
            });
        foreach (Policy::query()->where('type', 'feedback_360')->where('status', 'active')->orderBy('id')->get() as $policy) {
            try {
                $settings = (array) ($policy->versionEffectiveOn(today())?->settings ?? []);
                if (! in_array($settings['auto_launch'] ?? null, [true, 1, '1', 'yes'], true)) {
                    continue;
                }
                [$start, $end] = Feedback360Rules::periodContaining($settings, today());
                [$opens, $closes] = Feedback360Rules::window($settings, $start, $end);
                if (today()->lt($opens) || today()->gt($closes)
                    || FeedbackRound::query()->where('policy_id', $policy->id)->where('auto', true)->whereDate('period_start', $start->toDateString())->exists()) {
                    continue;
                }
                $this->launch($policy, ['day' => today()->toDateString()], null, true);
                $launched++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['closed' => $closed, 'launched' => $launched];
    }

    /** @return array<string, array{id: int, name: string, questions: list<array<string, mixed>>}> */
    private function templates(array $settings): array
    {
        $relations = Feedback360Rules::relations($settings);
        if ($relations === []) {
            throw new RuntimeException('The 360 policy collects feedback from no source.');
        }
        $out = [];
        foreach ($relations as $relation) {
            $template = FeedbackTemplate::query()->active()->where('process', 'feedback_360')->find(Feedback360Rules::templateId($settings, $relation));
            if ($template === null) {
                throw new RuntimeException('The feedback template for '.config("peopleos.performance.feedback_360.relations.{$relation}").' feedback is missing or inactive.');
            }
            $out[$relation] = ['id' => $template->id, 'name' => $template->name, 'questions' => $template->questions];
        }

        return $out;
    }

    /** Nominators who are employees are told which sets wait for them (a role's holders find them on their 360 screen). */
    private function askNominators(FeedbackRound $round): void
    {
        FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with('employee.person')->where('feedback_round_id', $round->id)
            ->where('nomination_status', 'draft')->whereNotNull('nominator_user_id')->get()
            ->each(function (FeedbackParticipant $p) use ($round) {
                $nominator = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $p->nominator_user_id)->value('id');
                if ($nominator !== null) {
                    PerformanceEvent::dispatch('performance.feedback360.nominate', $p->employee, $p, ['for' => $p->employee?->person?->full_name, 'round' => $round->name], [(int) $nominator]);
                }
            });
    }

    /** After closing: linked appraisals are scored again; employees who may now read their results are told. */
    private function afterClose(FeedbackRound $round): void
    {
        $participants = FeedbackParticipant::query()->withoutGlobalScope(AccessScope::class)->with('employee')->where('feedback_round_id', $round->id)->get();
        if ($round->performance_cycle_id !== null) {
            Appraisal::query()->withoutGlobalScope(AccessScope::class)->where('performance_cycle_id', $round->performance_cycle_id)
                ->whereIn('employee_id', $participants->pluck('employee_id')->all() ?: [0])->whereNotIn('status', ['finalized', 'acknowledged'])->whereNull('locked_at')
                ->get()->each(function (Appraisal $appraisal) {
                    try {
                        app(Appraisals::class)->score($appraisal);
                    } catch (Throwable $e) {
                        report($e);
                    }
                });
        }
        if (Feedback360Rules::employeeSees((array) $round->settings)) {
            $answered = FeedbackNomination::query()->withoutGlobalScope(AccessScope::class)->where('feedback_round_id', $round->id)->where('status', 'submitted')->distinct()->pluck('employee_id')->all();
            $participants->whereIn('employee_id', $answered)->each(fn (FeedbackParticipant $p) => PerformanceEvent::dispatch('performance.feedback360.results_ready', $p->employee, $p, ['round' => $round->name], [(int) $p->employee_id]));
        }
    }
}
