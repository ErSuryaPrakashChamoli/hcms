<?php

namespace App\Domain\Development\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Development\Events\DevelopmentEvent;
use App\Domain\Development\Models\DevelopmentPlan;
use App\Domain\Development\Models\DevelopmentPlanItem;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LearningEnrolment;
use App\Domain\Learning\Services\Learning;
use App\Domain\Performance\Contracts\DevelopmentNeedsReader;
use App\Domain\Performance\Models\DevelopmentNeed;
use App\Domain\Performance\Services\PerformanceRelationships;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 8 development plans: Performance → development need → plan item → learning. Needs are read
 * through the Phase 7 boundary; learning is recommended from courses whose declared skill outcomes
 * match the need, and enrolled only when a person chooses to (never automatically from a rating).
 * Completed, cancelled and archived plans are read-only history.
 */
final class DevelopmentPlans
{
    public function __construct(
        private readonly PerformanceRelationships $relationships,
        private readonly DevelopmentNeedsReader $needs,
        private readonly Learning $learning,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(Employee $employee, string $title, array $attributes = [], ?User $actor = null): DevelopmentPlan
    {
        $actor ??= auth()->user();
        $this->assertMayManage($employee, $actor);
        if (trim($title) === '') {
            throw new RuntimeException('A development plan needs a title.');
        }

        $plan = DevelopmentPlan::query()->create([
            'employee_id' => $employee->id, 'title' => $title, 'status' => 'draft', 'created_by' => $actor?->id,
            ...array_intersect_key($attributes, array_flip(['summary', 'owner_employee_id', 'starts_on', 'target_date', 'private_notes'])),
        ]);
        DevelopmentEvent::dispatch('development.plan.created', $employee, $plan, ['title' => $title], [$employee->id]);

        return $plan;
    }

    /** @param  array<string, mixed>  $data */
    public function addItem(DevelopmentPlan $plan, string $type, string $title, array $data = [], ?User $actor = null): DevelopmentPlanItem
    {
        $actor ??= auth()->user();
        $this->assertMayManage($plan->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), $actor);
        if (! empty($data['development_need_id'])) {
            $need = DevelopmentNeed::query()->withoutGlobalScope(AccessScope::class)->findOrFail($data['development_need_id']);
            if ((int) $need->employee_id !== (int) $plan->employee_id) {
                throw new RuntimeException('That development need belongs to another employee.');
            }
            $data += ['skill_id' => $need->skill_id, 'current_level' => $need->current_level, 'target_level' => $need->target_level, 'due_on' => $need->target_date];
        }
        if (isset($data['current_level'], $data['target_level']) && $data['target_level'] !== null && (float) $data['target_level'] < (float) $data['current_level']) {
            throw new RuntimeException('A target level cannot be below the current level.');
        }

        return DevelopmentPlanItem::query()->create([
            'development_plan_id' => $plan->id, 'item_type' => $type, 'title' => $title,
            'sort_order' => (int) $plan->items()->max('sort_order') + 10,
            ...array_intersect_key($data, array_flip(['development_need_id', 'skill_id', 'current_level', 'target_level', 'course_id', 'learning_path_id', 'due_on', 'notes'])),
        ]);
    }

    /** Create plan items from the employee's open development needs (Phase 7 boundary). */
    public function addOpenNeeds(DevelopmentPlan $plan, ?User $actor = null): Collection
    {
        $linked = $plan->items()->whereNotNull('development_need_id')->pluck('development_need_id')->map(fn ($id) => (int) $id)->all();

        return collect($this->needs->openNeedsFor($plan->employee_id))
            ->reject(fn ($need) => in_array($need['id'], $linked, true))
            ->map(fn ($need) => $this->addItem($plan, $need['skill_id'] ? 'skill_gap' : 'goal', $need['title'], ['development_need_id' => $need['id']], $actor));
    }

    /** Published courses whose declared skill outcomes address the item's skill. A recommendation only. */
    public function recommendations(DevelopmentPlanItem $item, int $limit = 5): Collection
    {
        if (! $item->skill_id) {
            return collect();
        }

        return Course::query()->whereIn('status', Course::ENROLLABLE)->whereNotNull('skill_outcomes')->orderBy('title')->get()
            ->filter(fn (Course $c) => collect($c->skill_outcomes)->contains(fn ($o) => (int) $o['skill_id'] === (int) $item->skill_id && ($item->target_level === null || (float) $o['level'] >= (float) $item->target_level)))
            ->take($limit)->values();
    }

    /** A person chooses to enrol the learner in the item's course (self-request rules or assigner rights apply). */
    public function enrolItem(DevelopmentPlanItem $item, ?User $actor = null): LearningEnrolment
    {
        $actor ??= auth()->user();
        $plan = $item->plan()->firstOrFail();
        if ($plan->status !== 'active') {
            throw new RuntimeException('Activate the plan before enrolling its learning.');
        }
        $course = $item->course_id ? Course::query()->findOrFail($item->course_id) : throw new RuntimeException('This plan item has no course.');
        $employee = $plan->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail();
        $self = $actor !== null && $this->relationships->forUser($actor)?->id === $employee->id;

        $enrolment = $self || ! $this->learning->mayAssign($actor, $employee)
            ? $this->learning->request($employee, $course, 'Development plan: '.$plan->title, $actor)
            : $this->learning->enrol($employee, $course, $item->due_on, null, null, $actor, false, 'assigned', 'Development plan: '.$plan->title);
        $item->update(['learning_enrolment_id' => $enrolment->id]);

        return $enrolment;
    }

    public function completeItem(DevelopmentPlanItem $item, ?string $notes = null, ?User $actor = null): DevelopmentPlanItem
    {
        $actor ??= auth()->user();
        $this->assertMayManage($item->plan()->firstOrFail()->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), $actor);
        if ($item->status !== 'open') {
            throw new RuntimeException('This item is already closed.');
        }
        if ($item->learning_enrolment_id && LearningEnrolment::query()->withoutGlobalScope(AccessScope::class)->whereKey($item->learning_enrolment_id)->value('status') !== 'completed') {
            throw new RuntimeException('The linked learning is not completed yet.');
        }
        $item->update(['status' => 'done', 'completed_at' => now(), 'notes' => $notes ?? $item->notes]);

        return $item;
    }

    public function transition(DevelopmentPlan $plan, string $to, ?string $reason = null, ?User $actor = null): DevelopmentPlan
    {
        $actor ??= auth()->user();
        $this->assertMayManage($plan->employee()->withoutGlobalScope(AccessScope::class)->firstOrFail(), $actor);

        return DB::transaction(function () use ($plan, $to, $reason, $actor) {
            $current = DevelopmentPlan::query()->withoutGlobalScope(AccessScope::class)->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lock_version !== (int) $plan->lock_version) {
                throw new RuntimeException('This plan was changed by someone else. Reload and try again.');
            }
            if (! in_array($to, DevelopmentPlan::TRANSITIONS[$current->status] ?? [], true)) {
                throw new RuntimeException("A development plan cannot move from {$current->status} to {$to}.");
            }
            if ($to === 'completed' && $current->items()->where('status', 'open')->exists()) {
                throw new RuntimeException('Close or cancel every open item before completing the plan.');
            }
            if (in_array($to, ['cancelled', 'on_hold'], true) && trim((string) $reason) === '') {
                throw new RuntimeException('A reason is required.');
            }
            $from = $current->status;
            $plan->setRawAttributes($current->getAttributes(), true);
            $plan->withAuditReason($reason)->update(['status' => $to, ...($to === 'completed' ? ['completed_at' => now(), 'completed_by' => $actor?->id] : [])]);
            $this->audit->record(AuditAction::StatusChange, 'development', $plan, [['field' => 'status', 'before' => $from, 'after' => $to]], $reason, actor: $actor);
            if ($to === 'completed') {
                DevelopmentEvent::dispatch('development.plan.completed', $plan->employee()->withoutGlobalScope(AccessScope::class)->first(), $plan, ['title' => $plan->title], [$plan->employee_id]);
            }

            return $plan;
        });
    }

    public function privateNotesFor(DevelopmentPlan $plan, User $viewer): ?string
    {
        $me = $this->relationships->forUser($viewer);
        if ($me?->id === $plan->employee_id) {
            return null;
        }
        if ($viewer->hasPermission('development.manage') || $this->relationships->manages($me, $plan->employee_id)) {
            return $plan->private_notes;
        }

        return null;
    }

    public function mayManage(Employee $employee, ?User $actor): bool
    {
        if ($actor === null || $actor->hasPermission('development.manage')) {
            return true;
        }
        $me = $this->relationships->forUser($actor);

        return ($me?->id === $employee->id && $actor->hasPermission('development.own'))
            || ($actor->hasPermission('development.team') && $this->relationships->manages($me, $employee->id));
    }

    private function assertMayManage(Employee $employee, ?User $actor): void
    {
        if (! $this->mayManage($employee, $actor)) {
            throw new RuntimeException('Development plans are kept by the employee, their manager or L&D.');
        }
    }
}
