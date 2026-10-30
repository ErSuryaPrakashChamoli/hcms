<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalPlan;
use App\Domain\Performance\Models\PerformanceCycle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity HP-8d (goal sheet policy → plan approval): a goal sheet submitted for approval. Its draft goals wait
 * in `pending_approval` until the reporting manager or HR (performance.manage) approves (goals become active) or sends
 * it back (goals return to draft) — in the Approval Center, or through a workflow on `performance.goal_plan.submitted`
 * when the policy says so. Nobody decides a goal sheet they submitted or their own.
 *
 * Owner decision 2026-10-29 (system mapping): on a goal sheet mapped by the system the reporting manager owns the final
 * version. The employee's additions and changes are proposals (propose): they wait in `pending_approval` on the sheet's
 * pending plan — opened for the manager (or the policy's workflow) even when the policy asks for no approval, joined while
 * one is pending — until the manager confirms (approves) it; a confirmed mapped sheet must meet the policy's weightage and
 * number of KRAs, as a submitted one does.
 */
final class GoalPlans
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PerformanceRelationships $relationships) {}

    /** Called by Goals::submitPlan when the employee's goal sheet policy asks for approval ($approval manager | workflow). */
    public function submit(Employee $employee, int $cycleId, string $approval, array $status, ?User $actor): GoalPlan
    {
        return DB::transaction(function () use ($employee, $cycleId, $approval, $status, $actor) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->first();
            if (GoalPlan::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->where('status', 'pending')->exists()) {
                throw new RuntimeException('This goal sheet is already waiting for approval.');
            }
            $drafts = Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->where('status', 'draft')->get();
            if ($drafts->isEmpty()) {
                throw new RuntimeException('There are no draft goals to submit for approval.');
            }
            $plan = GoalPlan::create([
                'employee_id' => $employee->id, 'performance_cycle_id' => $cycleId, 'approval' => $approval, 'status' => 'pending',
                'goals' => $status['goals'], 'total_weight' => $status['total'], 'submitted_by' => $actor?->id ?? auth()->id(), 'submitted_at' => now(),
            ]);
            $drafts->each(fn (Goal $g) => $g->update(['status' => 'pending_approval']));
            $this->audit->record(AuditAction::Submitted, 'performance', $plan, [], null, actor: $actor, metadata: ['goal_plan_cycle_id' => $cycleId, 'goals' => $drafts->count(), 'approval' => $approval]);
            $cycle = PerformanceCycle::query()->find($cycleId);
            PerformanceEvent::dispatch('performance.goal_plan.submitted', $employee, $plan, ['cycle' => $cycle?->name, 'goals' => $drafts->count(), 'approval' => $approval], $this->managerIds($employee));

            return $plan;
        });
    }

    /**
     * Owner decision 2026-10-29: the employee proposes additions and changes to their system-mapped goal sheet. The sheet's
     * drafts, and $goal when it was active, wait in `pending_approval` on the sheet's pending plan: a new plan (approval by
     * the manager, or the workflow when the policy asks for one; the managers are told) when none is pending, else the
     * pending one (its counts updated, nobody told again). Called inside the caller's transaction, under the employee lock.
     */
    public function propose(Employee $employee, int $cycleId, ?Goal $goal, ?User $actor): GoalPlan
    {
        return DB::transaction(function () use ($employee, $cycleId, $goal, $actor) {
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($employee->id)->lockForUpdate()->first();
            $plan = GoalPlan::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)
                ->where('status', 'pending')->lockForUpdate()->first();
            $moving = Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->where('level', 'employee')
                ->where(fn ($q) => $q->where('status', 'draft')->when($goal !== null, fn ($q) => $q->orWhere(fn ($q) => $q->whereKey($goal->id)->where('status', 'active'))))
                ->orderBy('id')->get();
            if ($goal === null && $moving->isEmpty()) {
                // The goal sheet submitted by the employee: nothing new to send.
                throw new RuntimeException($plan !== null ? 'This goal sheet is already waiting for approval.' : 'There are no draft goals to submit for approval.');
            }
            $moving->each(fn (Goal $g) => $g->withAuditReason('Proposed to the reporting manager')->update(['status' => 'pending_approval']));
            $sheet = app(GoalSheetRules::class)->openGoals($employee->id, $cycleId);
            if ($plan === null && $sheet->where('status', 'pending_approval')->isEmpty()) {
                throw new RuntimeException('There are no draft goals to submit for approval.');
            }
            $totals = ['goals' => $sheet->count(), 'total_weight' => round((float) $sheet->sum('weight'), 2)];
            if ($plan !== null) {
                $plan->update($totals);

                return $plan;
            }
            $approval = app(GoalSheetRules::class)->planApproval($employee) === 'workflow' ? 'workflow' : 'manager';
            $plan = GoalPlan::create(['employee_id' => $employee->id, 'performance_cycle_id' => $cycleId, 'approval' => $approval, 'status' => 'pending',
                'submitted_by' => $actor?->id ?? auth()->id(), 'submitted_at' => now()] + $totals);
            $this->audit->record(AuditAction::Submitted, 'performance', $plan, [], 'Proposed to the reporting manager', actor: $actor,
                metadata: ['goal_plan_cycle_id' => $cycleId, 'goals' => $moving->count(), 'approval' => $approval, 'proposal' => true]);
            $cycle = PerformanceCycle::query()->find($cycleId);
            PerformanceEvent::dispatch('performance.goal_plan.submitted', $employee, $plan, ['cycle' => $cycle?->name, 'goals' => $totals['goals'], 'approval' => $approval, 'proposal' => true], $this->managerIds($employee));

            return $plan;
        });
    }

    /** Whether the user may decide this employee's goal sheet: HR, or a performance manager of the employee — never their own. */
    public function mayDecideFor(User $user, Employee $employee): bool
    {
        if ((int) $employee->user_id === (int) $user->id) {
            return false;
        }

        return $user->hasPermission('performance.manage')
            || ($user->hasPermission('performance.team') && $this->relationships->manages($this->relationships->forUser($user), $employee->id));
    }

    public function mayDecide(User $user, GoalPlan $plan): bool
    {
        $plan->loadMissing('employee');

        return $plan->isPending() && $plan->employee !== null && (int) $plan->submitted_by !== (int) $user->id && $this->mayDecideFor($user, $plan->employee);
    }

    public function approve(GoalPlan $plan, ?string $note, User $actor): GoalPlan
    {
        return $this->decide($plan, 'approved', $note, $actor);
    }

    /** Send the goal sheet back: its goals return to draft for the employee to change and submit again. */
    public function reject(GoalPlan $plan, string $note, User $actor): GoalPlan
    {
        if (trim($note) === '') {
            throw new RuntimeException('Say why the goal sheet is sent back.');
        }

        return $this->decide($plan, 'rejected', $note, $actor);
    }

    private function decide(GoalPlan $plan, string $status, ?string $note, User $actor): GoalPlan
    {
        if (! $this->mayDecide($actor, $plan)) {
            throw new RuntimeException('You cannot decide this goal sheet.');
        }

        return DB::transaction(function () use ($plan, $status, $note, $actor) {
            // The employee first, as every change to the goal sheet takes it (Goals, propose), then the plan.
            Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($plan->employee_id)->lockForUpdate()->first();
            $locked = GoalPlan::query()->withoutGlobalScope(AccessScope::class)->with('employee', 'cycle')->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw new RuntimeException('This goal sheet was already decided.');
            }
            if ($status === 'approved' && $locked->employee !== null && app(GoalSheetRules::class)->managerOwned($locked->employee, $locked->performance_cycle_id)) {
                $this->assertConfirmable($locked->employee, (int) $locked->performance_cycle_id);
            }
            $locked->withAuditReason($note)->update(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $locked->employee_id)->where('performance_cycle_id', $locked->performance_cycle_id)
                ->where('status', 'pending_approval')->get()
                ->each(fn (Goal $g) => $g->withAuditReason($note)->update(['status' => $status === 'approved' ? 'active' : 'draft']));
            $this->audit->record($status === 'approved' ? AuditAction::Approved : AuditAction::Rejected, 'performance', $locked, [['field' => 'status', 'before' => 'pending', 'after' => $status]], $note, actor: $actor);
            PerformanceEvent::dispatch("performance.goal_plan.{$status}", $locked->employee, $locked, ['cycle' => $locked->cycle?->name, 'note' => $note], [$locked->employee_id]);

            return $locked;
        });
    }

    /**
     * Owner decision 2026-10-29: proposals skip the checks of submitting a goal sheet, so the mapped sheet the manager
     * confirms must meet them: the weightage total (Goals::requiredWeight) and the policy's number of KRAs.
     */
    private function assertConfirmable(Employee $employee, int $cycleId): void
    {
        $sheet = app(GoalSheetRules::class)->openGoals($employee->id, $cycleId);
        $total = round((float) $sheet->sum('weight'), 2);
        $required = app(Goals::class)->requiredWeight($cycleId, $employee);
        if ($required !== null && abs($total - $required) >= 0.001) {
            throw new RuntimeException("The goal sheet's weightage adds up to {$total}; it must add up to {$required}. Change the goals before confirming.");
        }
        app(GoalSheetRules::class)->assertPlan($employee, $cycleId);
    }

    /** @return list<int> the employee's performance managers (told that a goal sheet waits) */
    private function managerIds(Employee $employee): array
    {
        return ReportingRelationship::query()->where('employee_id', $employee->id)->whereIn('type', $this->relationships->managerTypes())
            ->currentlyEffective()->pluck('manager_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
