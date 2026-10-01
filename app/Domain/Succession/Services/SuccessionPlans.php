<?php

namespace App\Domain\Succession\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Development\Services\DevelopmentPlans;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Succession\Events\SuccessionEvent;
use App\Domain\Succession\Models\CriticalPosition;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Talent\Models\TalentDevelopmentAction;
use App\Domain\Talent\Services\TalentAccess;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 9 succession plans around critical positions. One open plan per position; successors are
 * added and removed by people (succession.manage within scope) with reasons, never automatically;
 * the plan never appoints anyone. Development actions become items of the employee's Phase 8
 * development plan (worded neutrally — the plan item never discloses candidacy).
 */
final class SuccessionPlans
{
    public function __construct(
        private readonly TalentAccess $access,
        private readonly CriticalPositions $positions,
        private readonly DevelopmentPlans $development,
        private readonly AuditRecorder $audit,
        private readonly WorkflowEngine $workflows,
    ) {}

    /** @param  array{vacancy_risk?: ?string, review_date?: ?string, confidential_notes?: ?string}  $data */
    public function create(CriticalPosition $position, array $data, User $actor): SuccessionPlan
    {
        $this->assertManager($actor);
        if ($position->status !== 'active') {
            throw new RuntimeException('Succession plans are made for active critical positions.');
        }

        try {
            $plan = SuccessionPlan::query()->create([
                'critical_position_id' => $position->id, 'owner_user_id' => $actor->id, 'created_by' => $actor->id,
                ...array_intersect_key($data, array_flip(['vacancy_risk', 'review_date', 'confidential_notes'])),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This critical position already has an open succession plan.');
        }
        SuccessionEvent::dispatch('succession.plan.created', null, $plan, ['position' => $position->title], [], [$actor->id]);

        return $plan;
    }

    /** Move the plan through its lifecycle; activation waits for the approval workflow when one is configured. */
    public function transition(SuccessionPlan $plan, string $to, ?string $reason, User $actor): SuccessionPlan
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($plan, $to, $reason, $actor) {
            $current = SuccessionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $plan->lock_version) {
                throw new RuntimeException('The succession plan was changed meanwhile. Reload and try again.');
            }
            if (! in_array($to, SuccessionPlan::TRANSITIONS[$current->status] ?? [], true)) {
                throw new RuntimeException("A succession plan cannot move from {$current->status} to {$to}.");
            }
            if ($to === 'closed' && trim((string) $reason) === '') {
                throw new RuntimeException('Closing a succession plan needs a reason.');
            }
            $plan->setRawAttributes($current->getAttributes(), true);

            $key = config('peopleos.talent.succession_plan_workflow_key');
            $workflow = $to === 'active' && $current->status === 'draft' && $key ? Workflow::query()->where('key', $key)->where('status', 'active')->first() : null;
            if ($workflow && $workflow->published()->exists() && ! $current->workflow_instance_id) {
                $instance = $this->workflows->start($workflow, $plan, ['succession_plan' => ['position' => $plan->position?->title]], $actor);
                $plan->update(['workflow_instance_id' => $instance->id]);

                return $plan;
            }

            $from = $current->status;
            $plan->withAuditReason($reason)->update(['status' => $to, ...($to === 'closed' ? ['closed_by' => $actor->id, 'closed_at' => now(), 'closure_reason' => $reason] : [])]);
            $this->audit->record(AuditAction::StatusChange, 'succession', $plan, [['field' => 'status', 'before' => $from, 'after' => $to]], $reason, actor: $actor);

            return $plan;
        });
    }

    public function addSuccessor(SuccessionPlan $plan, Employee $employee, ?string $strengths, ?string $gaps, ?string $confidentialNotes, User $actor): Successor
    {
        if (! $this->access->mayManageSuccession($actor, $employee->id)) {
            throw new RuntimeException('Successors are added with succession.manage, within your organisation scope, never yourself.');
        }
        if ($this->positions->incumbents($plan->position()->firstOrFail())->contains('id', $employee->id)) {
            throw new RuntimeException('The incumbent is not a successor to their own position.');
        }

        try {
            return DB::transaction(function () use ($plan, $employee, $strengths, $gaps, $confidentialNotes, $actor) {
                $locked = SuccessionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isOpen()) {
                    throw new RuntimeException('The succession plan is closed.');
                }
                $successor = Successor::query()->create([
                    'succession_plan_id' => $locked->id, 'employee_id' => $employee->id, 'strengths' => $strengths, 'development_gaps' => $gaps,
                    'confidential_notes' => $confidentialNotes, 'added_by' => $actor->id, 'added_at' => now(),
                ]);
                // Recipients: the plan owner only. The successor is not told (candidacy is confidential).
                SuccessionEvent::dispatch('succession.successor.added', $employee, $successor, [], [], array_filter([$locked->owner_user_id]));

                return $successor;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This employee is already a successor on the plan.');
        }
    }

    public function removeSuccessor(Successor $successor, string $reason, User $actor): Successor
    {
        if (! $this->access->mayManageSuccession($actor, $successor->employee_id) || trim($reason) === '') {
            throw new RuntimeException('Removing a successor needs succession.manage within scope and a reason.');
        }

        return DB::transaction(function () use ($successor, $reason, $actor) {
            $current = Successor::query()->withoutGlobalScope(AccessScope::class)->whereKey($successor->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'removed') {
                throw new RuntimeException('This successor was already removed.');
            }
            $successor->setRawAttributes($current->getAttributes(), true);
            $successor->update(['status' => 'removed', 'removed_by' => $actor->id, 'removed_at' => now(), 'removal_reason' => $reason]);
            SuccessionEvent::dispatch('succession.successor.removed', null, $successor, [], [], array_filter([$successor->plan?->owner_user_id]));

            return $successor;
        });
    }

    /**
     * A development action for a successor: an item in the employee's Phase 8 development plan (an
     * active or draft plan is reused, else one is created), linked confidentially to the successor.
     *
     * @param  array{skill_id?: ?int, target_level?: ?float, course_id?: ?int, learning_path_id?: ?int, due_on?: ?string}  $data
     */
    public function addDevelopmentAction(Successor $successor, string $type, string $title, array $data, User $actor): TalentDevelopmentAction
    {
        if (! $this->access->mayManageSuccession($actor, $successor->employee_id)) {
            throw new RuntimeException('Development actions for successors need succession.manage within scope.');
        }
        if (! array_key_exists($type, config('peopleos.talent.development_action_types'))) {
            throw new RuntimeException("Unknown development action type '{$type}'.");
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($successor->employee_id);

        return DB::transaction(function () use ($successor, $type, $title, $data, $actor, $employee) {
            $plan = DevelopmentPlan::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->whereIn('status', ['draft', 'active'])->latest('id')->first()
                ?? $this->development->create($employee, 'Career development', [], $actor);
            $itemType = match ($type) {
                'learning_path', 'certification' => 'learning', 'skill' => 'skill_gap', default => 'goal'
            };
            $item = $this->development->addItem($plan, $itemType, $title, array_intersect_key($data, array_flip(['skill_id', 'target_level', 'course_id', 'learning_path_id', 'due_on'])), $actor);
            $action = TalentDevelopmentAction::query()->create(['employee_id' => $employee->id, 'successor_id' => $successor->id, 'development_plan_item_id' => $item->id, 'action_type' => $type, 'created_by' => $actor->id]);
            SuccessionEvent::dispatch('succession.development_action.created', $employee, $action, ['type' => $type], [], [$actor->id]);

            return $action;
        });
    }

    private function assertManager(User $actor): void
    {
        if (! $actor->hasPermission('succession.manage')) {
            throw new RuntimeException('Succession plans are managed with succession.manage.');
        }
    }
}
