<?php

namespace App\Domain\Compensation\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Events\CompensationEvent;
use App\Domain\Compensation\Exceptions\CompensationRuleViolation;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Compensation\Models\CompensationCycle;
use App\Domain\Compensation\Models\CompensationCycleProposal;
use App\Domain\Compensation\Models\CompensationCycleShare;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HROne parity PO-17 (matrix 25 §25.9.2 #9, §25.14 #5): an increment plan (compensation cycle) shared with managers.
 *
 * - Sharing (`compensation.cycles`, the plan in the preparer's scope, not rejected or cancelled): with people who hold
 *   `compensation.team` and manage someone in the plan through a configured manager relationship
 *   (CompensationAccess::teamOf — mentors, buddies and project leads are not managers unless configured). Revoked, never
 *   deleted. The manager is told in the app.
 * - What a manager sees (`teamLines`): only the plan's lines of the people they manage, inside their own access scope,
 *   never their own line — the current and the planned annual CTC and the rating. Nothing else of the plan.
 * - Proposals (`propose`, only while the plan is a draft): an annual CTC for one of those lines, within the grid (the
 *   lowest to the highest value the plan gives the employee's CTC band, IncrementCalculator::range) and within the
 *   team's budget (the proposals of the manager's team add up to no more than the increase the plan gives the team, so
 *   the plan's total and its budget use never grow from proposals). Never for oneself.
 * - HR finalises (`accept` by the plan's preparer, who alone edits its draft lines; `decline` with a note): accepting
 *   adjusts the line through CompensationChanges::update, so it is still reviewed, approved and executed by other
 *   people. Recalculating or submitting the plan lapses the proposals still open.
 */
final class IncrementPlanSharing
{
    public function __construct(
        private readonly CompensationAccess $access,
        private readonly CompensationChanges $changes,
        private readonly AccessScopes $scopes,
        private readonly AuditRecorder $audit,
        private readonly CompensationOutput $output,
        private readonly IncrementCalculator $calculator,
    ) {}

    // --- Sharing --------------------------------------------------------------------------------------------------------

    /**
     * @param  list<int>  $userIds
     * @return array<int, true|string> per user id: shared, or why not
     */
    public function share(CompensationCycle $cycle, array $userIds, User $actor): array
    {
        $this->assertPlanner($cycle, $actor);
        if (in_array($cycle->status, ['rejected', 'cancelled'], true)) {
            throw new CompensationRuleViolation('A '.$cycle->status.' plan is not shared.');
        }
        $lineEmployees = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->pluck('employee_id')->map(fn ($id) => (int) $id);
        $results = [];
        $notify = [];
        DB::transaction(function () use ($cycle, $userIds, $actor, $lineEmployees, &$results, &$notify) {
            foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
                $user = User::query()->forCurrentTenant()->find($userId);
                $results[$userId] = match (true) {
                    $user === null || ! $user->isActive() => 'Not an active user of this organisation.',
                    (int) $user->id === (int) $actor->id => 'A plan is not shared with its own preparer.',
                    ! $user->hasPermission('compensation.team') => 'Needs compensation.team to see their team\'s compensation.',
                    $this->access->teamOf($user)->intersect($lineEmployees)->isEmpty() => 'Manages no one in this plan.',
                    default => true,
                };
                if ($results[$userId] !== true) {
                    continue;
                }
                $share = CompensationCycleShare::query()->where('compensation_cycle_id', $cycle->id)->where('user_id', $userId)->first();
                if ($share !== null && $share->isActive()) {
                    $results[$userId] = 'Already shared.';

                    continue;
                }
                $share === null
                    ? CompensationCycleShare::query()->create(['compensation_cycle_id' => $cycle->id, 'user_id' => $userId, 'shared_by' => $actor->id, 'shared_at' => now()])
                    : $share->update(['shared_by' => $actor->id, 'shared_at' => now(), 'revoked_by' => null, 'revoked_at' => null]);
                $notify[] = $userId;
            }
        });
        if ($notify !== []) {
            CompensationEvent::dispatch('compensation.cycle.shared', null, $cycle, ['cycle' => $cycle->code], $notify);
        }

        return $results;
    }

    /**
     * Who the plan can be shared with: active users holding compensation.team who manage someone in it through a
     * configured manager relationship (the preparer left out).
     *
     * @return Collection<int, User>
     */
    public function managersFor(CompensationCycle $cycle): Collection
    {
        $employees = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)->select('employee_id');
        $managers = ReportingRelationship::query()->whereIn('employee_id', $employees)->whereIn('type', app(PerformanceRelationships::class)->managerTypes())
            ->currentlyEffective()->select('manager_id');
        $userIds = Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', $managers)->whereNotNull('user_id')->limit(1000)->pluck('user_id');

        return User::query()->forCurrentTenant()->whereIn('id', $userIds)->whereKeyNot((int) $cycle->prepared_by)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->isActive() && $u->hasPermission('compensation.team'))->values();
    }

    public function revoke(CompensationCycle $cycle, int $userId, User $actor): void
    {
        $this->assertPlanner($cycle, $actor);
        $share = CompensationCycleShare::query()->where('compensation_cycle_id', $cycle->id)->where('user_id', $userId)->whereNull('revoked_at')->first();
        if ($share === null) {
            throw new CompensationRuleViolation('The plan is not shared with that person.');
        }
        DB::transaction(function () use ($cycle, $share, $userId, $actor) {
            $share->update(['revoked_by' => $actor->id, 'revoked_at' => now()]);
            $this->lapse($cycle, 'The plan is no longer shared with this manager.', $userId);
        });
    }

    /** @return Collection<int, CompensationCycleShare> the plan's active shares, with their users */
    public function sharesOf(CompensationCycle $cycle): Collection
    {
        return CompensationCycleShare::query()->with('user')->where('compensation_cycle_id', $cycle->id)->whereNull('revoked_at')->orderBy('id')->get();
    }

    /** Cycles shared with the user (active shares), for a manager who holds compensation.team. */
    public function sharedWith(User $user): Builder
    {
        $ids = $user->hasPermission('compensation.team')
            ? CompensationCycleShare::query()->where('user_id', $user->id)->whereNull('revoked_at')->select('compensation_cycle_id')
            : null;

        return CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->when($ids === null, fn (Builder $q) => $q->whereRaw('1 = 0'), fn (Builder $q) => $q->whereIn('id', $ids))
            ->whereNotIn('status', ['rejected', 'cancelled']);
    }

    public function isSharedWith(CompensationCycle $cycle, User $user): bool
    {
        return $user->hasPermission('compensation.team')
            && CompensationCycleShare::query()->where('compensation_cycle_id', $cycle->id)->where('user_id', $user->id)->whereNull('revoked_at')->exists();
    }

    /**
     * The plan's lines a manager may see: the people they manage (configured relationships), inside their access scope,
     * never their own line. Empty when the plan is not shared with them.
     *
     * @return Collection<int, CompensationChange>
     */
    public function teamLines(CompensationCycle $cycle, User $manager): Collection
    {
        if (! $this->isSharedWith($cycle, $manager) || in_array($cycle->status, ['rejected', 'cancelled'], true)) {
            return collect();
        }

        return $this->teamLinesQuery($cycle, $manager)->with('employee.person')->orderBy('id')->get()
            ->filter(fn (CompensationChange $line) => $line->employee !== null && (int) $line->employee->user_id !== (int) $manager->id && $this->scopes->allows($manager, $line->employee))
            ->values();
    }

    /** The lines of the manager's team in the plan (no share or scope check: callers check). */
    public function teamLinesQuery(CompensationCycle $cycle, User $manager): Builder
    {
        return CompensationChange::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)
            ->whereIn('employee_id', $this->access->teamOf($manager)->all() ?: [0]);
    }

    // --- Proposals ------------------------------------------------------------------------------------------------------

    public function propose(CompensationChange $line, float $ctcAnnual, ?string $note, User $manager): CompensationCycleProposal
    {
        return DB::transaction(function () use ($line, $ctcAnnual, $note, $manager) {
            $cycle = $this->lockedCycle((int) $line->compensation_cycle_id);
            if ($cycle->status !== 'draft') {
                throw new CompensationRuleViolation('Proposals are made while the plan is a draft; this one is '.str_replace('_', ' ', $cycle->status).'.');
            }
            if (! $this->isSharedWith($cycle, $manager)) {
                throw new CompensationRuleViolation('This plan is not shared with you.');
            }
            $team = $this->teamLines($cycle, $manager)->keyBy('id');
            $line = $team->get((int) $line->id);
            if ($line === null) {
                throw new CompensationRuleViolation('You propose only for the people you manage in this plan (never for yourself).');
            }
            if ($line->status !== 'draft') {
                throw new CompensationRuleViolation('That line is no longer a draft.');
            }
            if ($ctcAnnual <= 0 || $ctcAnnual >= 1e12) {
                throw new CompensationRuleViolation('Annual CTC must be positive.');
            }
            $employee = $line->employee;
            $current = $this->output->on($employee, $cycle->effective_from);
            if ($current === null) {
                throw new CompensationRuleViolation('The employee has no approved compensation in force on the plan\'s effective date.');
            }
            $bases = $this->bases($team, $cycle);
            $base = $bases[(int) $line->id];
            $increase = round($ctcAnnual - $base, 2);

            // Within the grid: the band's lowest to highest value for this employee.
            $range = $this->calculator->range($cycle, $employee, $current);
            if ($increase < $range['low'] - 0.005 || $increase > $range['high'] + 0.005) {
                throw new CompensationRuleViolation('The increase must stay within the plan\'s grid for this employee: between '.number_format($range['low'], 2).' and '.number_format($range['high'], 2).' a year.');
            }

            // Within the team's budget: the team's proposals add up to no more than what the plan gives the team.
            $open = CompensationCycleProposal::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id)
                ->where('proposed_by', $manager->id)->where('status', 'proposed')->get()->keyBy('compensation_change_id');
            $pool = 0.0;
            $proposed = 0.0;
            foreach ($team as $id => $teamLine) {
                $planned = round((float) $teamLine->ctc_annual - $bases[$id], 2);
                $pool += $planned;
                $proposed += $id === (int) $line->id ? $increase : ($open->has($id) ? round((float) $open[$id]->ctc_annual - $bases[$id], 2) : $planned);
            }
            if (round($proposed, 2) > round($pool, 2) + 0.005) {
                throw new CompensationRuleViolation('Your team\'s proposals would add '.number_format($proposed, 2).' a year, more than the plan gives your team ('.number_format($pool, 2).'). Lower another proposal first.');
            }

            $values = ['ctc_annual' => round($ctcAnnual, 2), 'base_ctc_annual' => round($base, 2), 'increase_percent' => $base > 0 ? round($increase / $base * 100, 2) : null,
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 2000) : null];
            $proposal = $open->get((int) $line->id);
            $proposal === null
                ? $proposal = CompensationCycleProposal::query()->create($values + ['compensation_cycle_id' => $cycle->id, 'compensation_change_id' => $line->id, 'employee_id' => $line->employee_id, 'proposed_by' => $manager->id, 'status' => 'proposed'])
                : $proposal->update($values);
            CompensationEvent::dispatch('compensation.cycle.proposal', null, $cycle, ['cycle' => $cycle->code], array_values(array_filter([(int) $cycle->prepared_by])));

            return $proposal;
        });
    }

    /** HR finalises: the preparer adjusts the line to the proposal (still a draft, reviewed and approved by others). */
    public function accept(CompensationCycleProposal $proposal, User $actor): CompensationChange
    {
        return DB::transaction(function () use ($proposal, $actor) {
            $cycle = $this->lockedCycle((int) $proposal->compensation_cycle_id);
            $proposal = $this->openProposal($proposal, $cycle, $actor);
            if ((int) $cycle->prepared_by !== (int) $actor->id) {
                throw new CompensationRuleViolation('Only the plan\'s preparer accepts a proposal (the preparer alone adjusts its lines).');
            }
            $line = CompensationChange::query()->withoutGlobalScope(AccessScope::class)->find($proposal->compensation_change_id);
            if ($line === null) {
                throw new CompensationRuleViolation('The line this proposal was made on no longer exists.');
            }
            $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($line->employee_id);
            if ($employee === null || (int) $employee->user_id === (int) $actor->id) {
                throw new CompensationRuleViolation('No one proposes, reviews, approves or executes their own compensation.');
            }
            $reason = mb_substr(rtrim((string) $line->reason).' · manager\'s proposal accepted', 0, 2000);
            $line = $this->changes->update($line, ['ctc_annual' => (float) $proposal->ctc_annual, 'reason' => $reason], $actor);
            $line->update(['increase_percent' => $proposal->increase_percent]);
            $proposal->update(['status' => 'accepted', 'decided_by' => $actor->id, 'decided_at' => now()]);
            CompensationCycleProposal::query()->withoutGlobalScope(AccessScope::class)->where('compensation_change_id', $line->id)->where('status', 'proposed')
                ->whereKeyNot($proposal->id)->get()->each(fn (CompensationCycleProposal $p) => $p->update(['status' => 'declined', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => 'Another proposal was accepted.']));

            return $line;
        });
    }

    public function decline(CompensationCycleProposal $proposal, string $note, User $actor): CompensationCycleProposal
    {
        if (trim($note) === '') {
            throw new CompensationRuleViolation('Declining a proposal needs a note for the manager.');
        }

        return DB::transaction(function () use ($proposal, $note, $actor) {
            $cycle = $this->lockedCycle((int) $proposal->compensation_cycle_id);
            $proposal = $this->openProposal($proposal, $cycle, $actor);
            $proposal->update(['status' => 'declined', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => mb_substr(trim($note), 0, 2000)]);

            return $proposal;
        });
    }

    /**
     * Open proposals of the plan (or of one manager) lapse: the plan moved on or was calculated again. With $detach (the
     * plan's lines are about to be replaced) every proposal keeps its employee, not the line.
     */
    public function lapse(CompensationCycle $cycle, string $why, ?int $managerId = null, bool $detach = false): int
    {
        $proposals = fn () => CompensationCycleProposal::query()->withoutGlobalScope(AccessScope::class)->where('compensation_cycle_id', $cycle->id);
        $open = $proposals()->where('status', 'proposed')->when($managerId !== null, fn (Builder $q) => $q->where('proposed_by', $managerId))->get();
        $open->each(fn (CompensationCycleProposal $p) => $p->update(['status' => 'lapsed', 'decided_at' => now(), 'decision_note' => $why]));
        if ($detach) {
            $proposals()->whereNotNull('compensation_change_id')->get()->each(fn (CompensationCycleProposal $p) => $p->update(['compensation_change_id' => null]));
        }

        return $open->count();
    }

    /** @return Collection<int, CompensationCycleProposal> keyed by line id: the latest proposal per line of the plan */
    public function latestProposals(CompensationCycle $cycle, ?User $manager = null): Collection
    {
        return CompensationCycleProposal::query()->withoutGlobalScope(AccessScope::class)->with('proposer')->where('compensation_cycle_id', $cycle->id)->whereNotNull('compensation_change_id')
            ->when($manager !== null, fn (Builder $q) => $q->where('proposed_by', $manager->id))
            ->orderBy('id')->get()->keyBy('compensation_change_id');
    }

    /**
     * The annual CTC each line was calculated from (the base kept on the line, the CTC before it once submitted, else the
     * compensation in force on the effective date).
     *
     * @param  Collection<int, CompensationChange>  $lines  keyed by id
     * @return array<int, float>
     */
    public function bases(Collection $lines, CompensationCycle $cycle): array
    {
        $missing = $lines->filter(fn (CompensationChange $l) => $l->base_ctc_annual === null && $l->previous_ctc_annual === null)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $inForce = $missing === [] ? [] : $this->output->inForceOn($missing, $cycle->effective_from);
        $out = [];
        foreach ($lines as $line) {
            $out[(int) $line->id] = (float) ($line->base_ctc_annual ?? $line->previous_ctc_annual ?? ($inForce[(int) $line->employee_id] ?? null)?->ctcAnnual ?? $line->ctc_annual);
        }

        return $out;
    }

    private function openProposal(CompensationCycleProposal $proposal, CompensationCycle $cycle, User $actor): CompensationCycleProposal
    {
        $this->assertPlanner($cycle, $actor);
        if ($cycle->status !== 'draft') {
            throw new CompensationRuleViolation('Proposals are decided while the plan is a draft.');
        }
        $current = CompensationCycleProposal::query()->withoutGlobalScope(AccessScope::class)->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
        if ($current->status !== 'proposed') {
            throw new CompensationRuleViolation('This proposal is already '.$current->status.'.');
        }
        if ((int) $current->proposed_by === (int) $actor->id) {
            throw new CompensationRuleViolation('A manager does not decide their own proposal.');
        }

        return $current;
    }

    private function lockedCycle(int $id): CompensationCycle
    {
        return CompensationCycle::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertPlanner(CompensationCycle $cycle, User $actor): void
    {
        if (! $actor->hasPermission('compensation.cycles')) {
            throw new CompensationRuleViolation('This needs compensation.cycles.');
        }
        if (! $this->scopes->allows($actor, $cycle)) {
            throw new CompensationRuleViolation('That plan is outside your organisation scope.');
        }
    }
}
