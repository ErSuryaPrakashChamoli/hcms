<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Support\PolicyLetters;
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
 * nothing here recommends or triggers a PIP, termination or compensation change. HROne setup parity HP-8f: the
 * employee's PIP policy (ImprovementPlanRules) may ask for approval before a plan starts (pending_approval, trigger
 * `performance.pip.requested`) and before its outcome is recorded (pending_outcome,
 * `performance.pip.evaluation_requested`), limit extensions, add start / close checklist items and draft letters.
 */
final class ImprovementPlans
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Timeline $timeline,
        private readonly PerformanceRelationships $relationships,
        private readonly ImprovementPlanRules $rules,
    ) {}

    /**
     * @param  array<int, array{objective: string, measure?: string, due?: string}>  $objectives
     * @param  list<string>  $reasonCodes  HP-8a: PIP reason codes (performance masters); $reason stays the details
     */
    public function open(Employee $employee, Employee|int|null $manager, string $reason, array $objectives, Carbon|string $start, Carbon|string $end, ?int $appraisalId = null, ?User $actor = null, bool $draft = false, array $reasonCodes = []): ImprovementPlan
    {
        $reasonCodes = app(PerformanceMasters::class)->pipReasonCodes($reasonCodes);
        $this->assertMayManage($employee->id, $actor ?? auth()->user());
        if ($objectives === []) {
            throw new RuntimeException('A plan needs at least one objective.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A plan needs a reason.');
        }

        // HP-8f: under a PIP policy that asks for approval the plan waits for it before it starts.
        $needsApproval = ! $draft && $this->rules->needsApproval($employee);

        return DB::transaction(function () use ($employee, $manager, $reason, $objectives, $start, $end, $appraisalId, $actor, $draft, $reasonCodes, $needsApproval) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            if (ImprovementPlan::query()->where('employee_id', $employee->id)->whereIn('status', ImprovementPlan::UNDECIDED)->exists()) {
                throw new RuntimeException('An improvement plan is already active for this employee.');
            }

            $plan = ImprovementPlan::create([
                'employee_id' => $employee->id,
                'manager_id' => $manager instanceof Employee ? $manager->id : $manager,
                'appraisal_id' => $appraisalId,
                'start_date' => Carbon::parse($start),
                'end_date' => Carbon::parse($end),
                'reason' => $reason,
                'reason_codes' => $reasonCodes === [] ? null : $reasonCodes,
                'objectives' => array_values($objectives),
                'status' => $draft ? 'draft' : ($needsApproval ? 'pending_approval' : 'active'),
                'created_by' => $actor?->id ?? auth()->id(),
            ]);

            $this->audit->record(AuditAction::Create, 'performance', $plan, [], $reason, actor: $actor);
            if ($needsApproval) {
                $this->requested($plan);
            } elseif (! $draft) {
                $this->started($plan, $actor);
            }

            return $plan;
        });
    }

    public function activate(ImprovementPlan $plan, ?User $actor = null): ImprovementPlan
    {
        $plan->loadMissing('employee');
        if ($this->rules->needsApproval($plan->employee)) {
            $this->transition($plan, 'pending_approval', null, $actor, fn () => $this->requested($plan));

            return $plan->refresh();
        }
        $this->transition($plan, 'active', null, $actor, fn () => $this->started($plan, $actor));

        return $plan->refresh();
    }

    /** HP-8f: whether the user may approve a plan or its outcome: HR who manage PIPs — never their own request or plan. */
    public function mayDecide(User $user, ImprovementPlan $plan): bool
    {
        $plan->loadMissing('employee');

        return in_array($plan->status, ['pending_approval', 'pending_outcome'], true)
            && ($user->hasPermission('performance.pip') || $user->hasPermission('performance.manage'))
            && (int) $plan->created_by !== (int) $user->id && (int) $plan->employee?->user_id !== (int) $user->id;
    }

    /** HP-8f: approve a plan waiting for approval: it starts (checklist, letter, timeline, the employee is told). */
    public function approve(ImprovementPlan $plan, ?string $note, User $actor): ImprovementPlan
    {
        $this->assertDecides($actor, $plan, 'pending_approval');
        $this->transition($plan, 'active', $note, $actor, fn () => $this->started($plan, $actor), ['approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note]);

        return $plan->refresh();
    }

    /** HP-8f: reject a plan waiting for approval: it is cancelled with the reason. */
    public function reject(ImprovementPlan $plan, string $note, User $actor): ImprovementPlan
    {
        if (trim($note) === '') {
            throw new RuntimeException('Say why the plan is rejected.');
        }
        $this->assertDecides($actor, $plan, 'pending_approval');
        $this->transition($plan, 'cancelled', $note, $actor, fn () => PerformanceEvent::dispatch('performance.pip.rejected', $plan->employee, $plan, ['note' => $note], array_filter([$plan->manager_id])), ['decision_note' => $note]);

        return $plan->refresh();
    }

    /** HP-8f: approve the outcome waiting for approval: it is recorded (timeline, close checklist, letter). */
    public function approveEvaluation(ImprovementPlan $plan, ?string $note, User $actor): ImprovementPlan
    {
        $this->assertDecides($actor, $plan, 'pending_outcome');
        $status = (string) $plan->requested_outcome;
        $this->transition($plan, $status, $note, $actor, fn () => $this->outcomeRecorded($plan, $status, $actor), ['closed_at' => now(), 'decision_note' => $note, 'requested_outcome' => null, 'evaluation_from' => null]);

        return $plan->refresh();
    }

    /** HP-8f: send the outcome back: the plan returns to where it was (active or extended) to be evaluated again. */
    public function rejectEvaluation(ImprovementPlan $plan, string $note, User $actor): ImprovementPlan
    {
        if (trim($note) === '') {
            throw new RuntimeException('Say why the outcome is sent back.');
        }
        $this->assertDecides($actor, $plan, 'pending_outcome');
        $this->transition($plan, $plan->evaluation_from === 'extended' ? 'extended' : 'active', $note, $actor, attributes: ['decision_note' => $note, 'requested_outcome' => null, 'evaluation_from' => null]);

        return $plan->refresh();
    }

    private function assertDecides(User $actor, ImprovementPlan $plan, string $status): void
    {
        if ($plan->status !== $status) {
            throw new RuntimeException('This plan is not waiting for that decision.');
        }
        if (! $this->mayDecide($actor, $plan)) {
            throw new RuntimeException('You cannot decide this improvement plan.');
        }
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
        // HP-8f: the PIP policy's number of extensions (0 = any).
        $max = $this->rules->maxExtensions($plan->loadMissing('employee')->employee);
        if ($max > 0 && (int) $plan->extension_count >= $max) {
            throw new RuntimeException("This plan was already extended the most times allowed ({$max}).");
        }

        return $this->transition($plan, 'extended', $reason, $actor, attributes: ['end_date' => $newEnd, 'extension_count' => (int) $plan->extension_count + 1]);
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

        // HP-8f: under a PIP policy that approves the evaluation, the outcome waits for approval before it is recorded.
        if ($status !== 'cancelled' && $this->rules->needsEvaluationApproval($plan->loadMissing('employee')->employee)) {
            $this->transition($plan, 'pending_outcome', $outcome, $actor, fn () => PerformanceEvent::dispatch('performance.pip.evaluation_requested', $plan->employee, $plan, ['outcome' => config("peopleos.performance.pip_statuses.{$status}")], []),
                ['outcome' => $outcome, 'requested_outcome' => $status, 'evaluation_from' => $plan->status]);

            return $plan->refresh();
        }

        $this->transition($plan, $status, $outcome, $actor, fn () => $this->outcomeRecorded($plan, $status, $actor), ['outcome' => $outcome, 'closed_at' => now()]);

        return $plan->refresh();
    }

    /** The outcome is on record: timeline, the people told; HP-8f: the close checklist and the PIP evaluation letter. */
    private function outcomeRecorded(ImprovementPlan $plan, string $status, ?User $actor): void
    {
        $this->timeline->record($plan->employee, 'performance', 'Improvement plan '.config("peopleos.performance.pip_statuses.{$status}"), now(), null, $plan);
        if ($status === 'cancelled') {
            return;
        }
        PerformanceEvent::dispatch('performance.pip.completed', $plan->employee, $plan, ['outcome' => config("peopleos.performance.pip_statuses.{$status}")], array_filter([$plan->employee_id, $plan->manager_id]));
        foreach ($this->rules->checklist($plan->employee, 'close') as $item) {
            $plan->checkpoints()->create(['title' => $item['title'], 'due_date' => today()->addDays($item['due_offset_days']), 'kind' => 'close', 'owner' => $item['owner']]);
        }
        PolicyLetters::draft($this->rules->letterTemplate($plan->employee, 'close'), $plan->employee, $actor, $plan);
    }

    /** Final closure after the outcome is recorded; the plan becomes read-only. HP-8f: once the close checklist is reviewed. */
    public function closeOut(ImprovementPlan $plan, string $reason, ?User $actor = null): ImprovementPlan
    {
        if ($plan->checkpoints()->where('kind', 'close')->where('status', 'pending')->exists()) {
            throw new RuntimeException('Review the close checklist before closing the plan.');
        }

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
        $checkpoint->loadMissing('plan');
        $this->assertMayManage($checkpoint->plan->employee_id, $actor);
        if (! in_array($status, ['met', 'partially_met', 'not_met'], true)) {
            throw new RuntimeException('A checkpoint is met, partially met or not met.');
        }
        // HP-8f: close checklist items are reviewed after the outcome, before the plan is closed.
        $closeItem = $checkpoint->kind === 'close' && in_array($checkpoint->plan->status, ['completed', 'unsuccessful'], true);
        if (! $checkpoint->plan->isOpen() && ! $closeItem) {
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

    private function started(ImprovementPlan $plan, ?User $actor = null): void
    {
        $this->timeline->record($plan->employee, 'performance', 'Improvement plan opened', $plan->start_date, null, $plan);
        PerformanceEvent::dispatch('performance.pip.opened', $plan->employee, $plan, ['until' => $plan->end_date->toDateString()], array_filter([$plan->employee_id, $plan->manager_id]));
        // HP-8f: the PIP policy's start checklist (within the plan period) and the PIP letter.
        foreach ($this->rules->checklist($plan->employee, 'start') as $item) {
            $due = $plan->start_date->copy()->addDays($item['due_offset_days']);
            $plan->checkpoints()->create(['title' => $item['title'], 'due_date' => $due->gt($plan->end_date) ? $plan->end_date : $due, 'kind' => 'start', 'owner' => $item['owner']]);
        }
        PolicyLetters::draft($this->rules->letterTemplate($plan->employee, 'activate'), $plan->employee, $actor, $plan);
    }

    /** HP-8f: a plan waits for approval (a workflow on performance.pip.requested, else the Approval Center). */
    private function requested(ImprovementPlan $plan): void
    {
        PerformanceEvent::dispatch('performance.pip.requested', $plan->employee, $plan, ['until' => $plan->end_date->toDateString()], []);
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
