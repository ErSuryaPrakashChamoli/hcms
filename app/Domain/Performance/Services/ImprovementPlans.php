<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Services\Timeline;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\ImprovementPlanCheckpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PIPs (§34, Phase 7 §28): draft → active → extended → successfully completed / unsuccessful →
 * closed, or cancelled. Checkpoints, row locks, audit and timeline. People decide every outcome;
 * nothing here recommends or triggers a PIP, termination or compensation change.
 */
final class ImprovementPlans
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly PerformanceRelationships $relationships,
    ) {}

    /** @param  array<int, array{objective: string, measure?: string, due?: string}>  $objectives */
    public function open(Employee $employee, Employee|int|null $manager, string $reason, array $objectives, Carbon|string $start, Carbon|string $end, ?int $appraisalId = null, ?User $actor = null, bool $draft = false): ImprovementPlan
    {
        $this->assertMayManage($employee->id, $actor ?? auth()->user());
        if ($objectives === []) {
            throw new RuntimeException('A plan needs at least one objective.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A plan needs a reason.');
        }

        return DB::transaction(function () use ($employee, $manager, $reason, $objectives, $start, $end, $appraisalId, $actor, $draft) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            if (ImprovementPlan::query()->where('employee_id', $employee->id)->whereIn('status', ['draft', 'active', 'extended'])->exists()) {
                throw new RuntimeException('An improvement plan is already active for this employee.');
            }

            $plan = ImprovementPlan::create([
                'employee_id' => $employee->id,
                'manager_id' => $manager instanceof Employee ? $manager->id : $manager,
                'appraisal_id' => $appraisalId,
                'start_date' => Carbon::parse($start),
                'end_date' => Carbon::parse($end),
                'reason' => $reason,
                'objectives' => array_values($objectives),
                'status' => $draft ? 'draft' : 'active',
                'created_by' => $actor?->id ?? auth()->id(),
            ]);

            $this->audit->record(AuditAction::Create, 'performance', $plan, [], $reason, actor: $actor);
            if (! $draft) {
                $this->started($plan);
            }

            return $plan;
        });
    }

    public function activate(ImprovementPlan $plan, ?User $actor = null): ImprovementPlan
    {
        $this->transition($plan, 'active', null, $actor, fn () => $this->started($plan));

        return $plan->refresh();
    }

    public function extend(ImprovementPlan $plan, Carbon|string $newEnd, string $reason, ?User $actor = null): ImprovementPlan
    {
        if (! $plan->isOpen()) {
            throw new RuntimeException('The plan is closed.');
        }
        $newEnd = Carbon::parse($newEnd);
        if ($newEnd->lte($plan->end_date)) {
            throw new RuntimeException('An extension must end after the current end date.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('An extension needs a reason.');
        }

        return $this->transition($plan, 'extended', $reason, $actor, attributes: ['end_date' => $newEnd]);
    }

    /** Record the outcome: completed (successfully), unsuccessful, or cancelled (withdrawn is legacy). */
    public function close(ImprovementPlan $plan, string $status, string $outcome, ?User $actor = null): ImprovementPlan
    {
        $status = $status === 'withdrawn' ? 'cancelled' : $status;
        if (! in_array($status, ['completed', 'unsuccessful', 'cancelled'], true)) {
            throw new RuntimeException('Close as completed, unsuccessful or cancelled.');
        }
        if (! $plan->isOpen()) {
            throw new RuntimeException('The plan is already closed.');
        }
        if (trim($outcome) === '') {
            throw new RuntimeException('Record the outcome.');
        }

        $this->transition($plan, $status, $outcome, $actor, function () use ($plan, $status) {
            $this->timeline->record($plan->employee, 'performance', 'Improvement plan '.config("peopleos.performance.pip_statuses.{$status}"), now(), null, $plan);
            if ($status !== 'cancelled') {
                PerformanceEvent::dispatch('performance.pip.completed', $plan->employee, $plan, ['outcome' => config("peopleos.performance.pip_statuses.{$status}")], array_filter([$plan->employee_id, $plan->manager_id]));
            }
        }, ['outcome' => $outcome, 'closed_at' => now()]);

        return $plan->refresh();
    }

    /** Final closure after the outcome is recorded; the plan becomes read-only. */
    public function closeOut(ImprovementPlan $plan, string $reason, ?User $actor = null): ImprovementPlan
    {
        return $this->transition($plan, 'closed', $reason, $actor, attributes: ['closure_reason' => $reason]);
    }

    public function addCheckpoint(ImprovementPlan $plan, string $title, Carbon|string $due, ?string $notes = null, ?User $actor = null): ImprovementPlanCheckpoint
    {
        $this->assertMayManage($plan->employee_id, $actor ?? auth()->user());
        if (! $plan->isOpen()) {
            throw new RuntimeException('The plan is closed.');
        }
        $due = Carbon::parse($due);
        if ($due->lt($plan->start_date) || $due->gt($plan->end_date)) {
            throw new RuntimeException('A checkpoint must fall within the plan period.');
        }

        return $plan->checkpoints()->create(['title' => $title, 'due_date' => $due, 'notes' => $notes]);
    }

    public function reviewCheckpoint(ImprovementPlanCheckpoint $checkpoint, string $status, string $outcome, ?User $actor = null): ImprovementPlanCheckpoint
    {
        $actor ??= auth()->user();
        $this->assertMayManage($checkpoint->plan->employee_id, $actor);
        if (! in_array($status, ['met', 'partially_met', 'not_met'], true)) {
            throw new RuntimeException('A checkpoint is met, partially met or not met.');
        }
        if (! $checkpoint->plan->isOpen()) {
            throw new RuntimeException('The plan is closed.');
        }

        return DB::transaction(function () use ($checkpoint, $status, $outcome, $actor) {
            $current = ImprovementPlanCheckpoint::query()->whereKey($checkpoint->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'pending') {
                throw new RuntimeException('This checkpoint was already reviewed.');
            }
            $checkpoint->update(['status' => $status, 'outcome' => $outcome, 'reviewed_by' => $actor?->id, 'reviewed_at' => now()]);

            return $checkpoint->refresh();
        });
    }

    private function started(ImprovementPlan $plan): void
    {
        $this->timeline->record($plan->employee, 'performance', 'Improvement plan opened', $plan->start_date, null, $plan);
        PerformanceEvent::dispatch('performance.pip.opened', $plan->employee, $plan, ['until' => $plan->end_date->toDateString()], array_filter([$plan->employee_id, $plan->manager_id]));
    }

    private function transition(ImprovementPlan $plan, string $to, ?string $reason, ?User $actor, ?callable $after = null, array $attributes = []): ImprovementPlan
    {
        $this->assertMayManage($plan->employee_id, $actor ?? auth()->user());

        return DB::transaction(function () use ($plan, $to, $reason, $actor, $after, $attributes) {
            $current = ImprovementPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $plan->lock_version || $current->status !== $plan->status) {
                throw new RuntimeException('This plan was changed by someone else. Reload and try again.');
            }
            $from = $current->status;
            if (! in_array($to, ImprovementPlan::TRANSITIONS[$from] ?? [], true)) {
                throw new RuntimeException("An improvement plan cannot move from {$from} to {$to}.");
            }
            $plan->withAuditReason($reason)->update(['status' => $to, ...$attributes]);
            $this->audit->record(AuditAction::StatusChange, 'performance', $plan, [['field' => 'status', 'before' => $from, 'after' => $to]], $reason, actor: $actor);
            if ($after) {
                $after();
            }

            return $plan;
        });
    }

    private function assertMayManage(int $employeeId, ?User $actor): void
    {
        if ($actor === null || $actor->hasPermission('performance.manage') || $actor->hasPermission('performance.pip')) {
            return;
        }
        if (! ($actor->hasPermission('performance.team') && $this->relationships->manages($this->relationships->forUser($actor), $employeeId))) {
            throw new RuntimeException('Only HR or a manager of this employee manages the improvement plan.');
        }
    }
}
