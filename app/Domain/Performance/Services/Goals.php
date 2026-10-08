<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\KeyResult;
use App\Domain\Performance\Models\PerformanceCycle;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Goal cascade, key results and check-ins (§34). Progress rolls up from key results and from child goals. */
final class Goals
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PerformanceRelationships $relationships) {}

    /** @param  array<int, array<string, mixed>>  $keyResults */
    public function create(array $attributes, array $keyResults = [], ?User $actor = null): Goal
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Performance, 'performance.goal.create');
        $attributes['level'] ??= isset($attributes['employee_id']) ? 'employee' : 'company';
        $attributes['status'] ??= 'active';
        $attributes['source'] ??= 'manual';
        $attributes['created_by'] ??= $actor?->id ?? auth()->id();

        if (isset($attributes['parent_id'])) {
            $parent = Goal::query()->findOrFail($attributes['parent_id']);
            if (! $parent->isOpen()) {
                throw new RuntimeException('Cannot align to a closed goal.');
            }
        }
        $this->assertOwnership($attributes, $actor ?? auth()->user());
        foreach ($keyResults as $kr) {
            if ((int) ($kr['weight'] ?? 0) < 0) {
                throw new RuntimeException('A key result weight cannot be negative.');
            }
        }

        return DB::transaction(function () use ($attributes, $keyResults) {
            $this->assertNotDuplicate($attributes);
            $this->assertWithinWeightTotal($attributes);
            $goal = Goal::create($attributes);

            foreach ($keyResults as $i => $kr) {
                $goal->keyResults()->create($kr + ['sort_order' => ($i + 1) * 10, 'progress' => Goal::progressFor($kr['measure_type'] ?? 'percentage', (float) ($kr['start_value'] ?? 0), (float) ($kr['target_value'] ?? 100), (float) ($kr['current_value'] ?? 0))]);
            }

            $this->recompute($goal);

            if ($goal->employee_id) {
                PerformanceEvent::dispatch('performance.goal.assigned', $goal->employee, $goal, ['title' => $goal->title, 'due' => $goal->due_date?->toDateString()], [$goal->employee_id]);
            }
            PerformanceEvent::dispatch('performance.goal.created', $goal->employee, $goal, ['title' => $goal->title, 'level' => $goal->level], []);

            return $goal->refresh();
        });
    }

    /**
     * Phase 7: change a goal's definition. $expectedVersion is the lock_version the editor loaded;
     * a mismatch means someone else changed it first. Progress changes only through checkIn().
     */
    public function update(Goal $goal, array $attributes, ?int $expectedVersion = null, ?User $actor = null, ?string $reason = null): Goal
    {
        unset($attributes['current_value'], $attributes['progress'], $attributes['lock_version'], $attributes['tenant_id'], $attributes['created_by']);

        return DB::transaction(function () use ($goal, $attributes, $expectedVersion, $actor, $reason) {
            $current = Goal::query()->whereKey($goal->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $current->lock_version !== $expectedVersion) {
                throw new RuntimeException('This goal was changed by someone else. Reload and try again.');
            }
            $merged = [...$current->only(['level', 'employee_id', 'organisation_node_id', 'performance_cycle_id', 'title', 'weight', 'status']), ...$attributes];
            $this->assertOwnership($merged, $actor ?? auth()->user());
            $this->assertNotDuplicate($merged, $current->id);
            $this->assertWithinWeightTotal($merged, $current->id);

            $goal->withAuditReason($reason)->update($attributes);

            return $goal->refresh();
        });
    }

    /** The weight total a cycle requires of each employee's goals, or null when not configured. */
    public function requiredWeight(?int $cycleId): ?float
    {
        if ($cycleId === null) {
            return null;
        }
        $cycle = PerformanceCycle::query()->with('templateVersion')->find($cycleId);
        $required = $cycle?->templateVersion?->requiredGoalWeight() ?? $cycle?->setting('required_goal_weight');

        return $required === null ? null : (float) $required;
    }

    /** @return array{total: float, required: ?float, valid: bool, goals: int} */
    public function planStatus(Employee $employee, int $cycleId): array
    {
        $goals = Goal::query()->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->whereIn('status', ['draft', 'active']);
        $total = (float) (clone $goals)->sum('weight');
        $required = $this->requiredWeight($cycleId);

        return ['total' => $total, 'required' => $required, 'valid' => (clone $goals)->exists() && ($required === null || abs($total - $required) < 0.001), 'goals' => (clone $goals)->count()];
    }

    /** Submit an employee's goal plan for a cycle: the weights must add up to the configured total. */
    public function submitPlan(Employee $employee, int $cycleId, ?User $actor = null): int
    {
        $status = $this->planStatus($employee, $cycleId);
        if ($status['goals'] === 0) {
            throw new RuntimeException('There are no goals to submit.');
        }
        if (! $status['valid']) {
            throw new RuntimeException("Goal weights add up to {$status['total']}; this cycle requires {$status['required']}.");
        }

        return DB::transaction(function () use ($employee, $cycleId, $actor, $status) {
            $drafts = Goal::query()->where('employee_id', $employee->id)->where('performance_cycle_id', $cycleId)->where('status', 'draft')->get();
            $drafts->each(fn (Goal $g) => $g->update(['status' => 'active']));
            $this->audit->record(AuditAction::Submitted, 'performance', $employee, [], null, actor: $actor, metadata: ['goal_plan_cycle_id' => $cycleId, 'total_weight' => $status['total'], 'goals' => $status['goals']]);

            return $drafts->count();
        });
    }

    /** Employees own their goals, managers their reports' goals; organisation goals need performance.manage. */
    private function assertOwnership(array $attributes, ?User $actor): void
    {
        if ($actor === null || $actor->hasPermission('performance.manage')) {
            return;
        }
        if (($attributes['level'] ?? 'employee') !== 'employee' || empty($attributes['employee_id'])) {
            throw new RuntimeException('Only performance administrators set organisation goals.');
        }
        $me = $this->relationships->forUser($actor);
        $own = $me !== null && (int) $me->id === (int) $attributes['employee_id'];
        if (! $own && ! ($actor->hasPermission('performance.team') && $this->relationships->manages($me, $attributes['employee_id']))) {
            throw new RuntimeException('You can only set goals for yourself or for employees you manage.');
        }
    }

    private function assertNotDuplicate(array $attributes, ?int $ignoreId = null): void
    {
        if (in_array($attributes['status'] ?? 'active', ['cancelled'], true)) {
            return;
        }
        $duplicate = Goal::query()->withoutGlobalScope(AccessScope::class)
            ->where('level', $attributes['level'] ?? 'employee')
            ->where('employee_id', $attributes['employee_id'] ?? null)
            ->where('organisation_node_id', $attributes['organisation_node_id'] ?? null)
            ->where('performance_cycle_id', $attributes['performance_cycle_id'] ?? null)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim((string) ($attributes['title'] ?? '')))])
            ->where('status', '!=', 'cancelled')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A goal with this title already exists for this owner and cycle.');
        }
    }

    private function assertWithinWeightTotal(array $attributes, ?int $ignoreId = null): void
    {
        $required = empty($attributes['employee_id']) ? null : $this->requiredWeight($attributes['performance_cycle_id'] ?? null);
        if ($required === null || in_array($attributes['status'] ?? 'active', ['cancelled', 'completed'], true)) {
            return;
        }
        $others = (float) Goal::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $attributes['employee_id'])
            ->where('performance_cycle_id', $attributes['performance_cycle_id'])->whereIn('status', ['draft', 'active'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->lockForUpdate()->sum('weight');
        if ($others + (float) ($attributes['weight'] ?? 0) > $required + 0.001) {
            throw new RuntimeException('Goal weights would add up to '.($others + (float) ($attributes['weight'] ?? 0))." ; this cycle allows {$required}.");
        }
    }

    public function checkIn(Goal|KeyResult $subject, ?float $value, ?string $note = null, ?string $confidence = null, ?User $actor = null, string $source = 'manual', ?string $measurement = null, ?string $idempotencyKey = null): GoalCheckIn
    {
        $goalId = $subject instanceof Goal ? $subject->id : $subject->goal_id;
        if ($idempotencyKey !== null && ($previous = GoalCheckIn::query()->where('goal_id', $goalId)->where('idempotency_key', $idempotencyKey)->first())) {
            return $previous; // a retried request: nothing is recorded twice
        }
        if (! array_key_exists($source, config('peopleos.performance.goal_sources'))) {
            throw new RuntimeException("Unknown progress source '{$source}'.");
        }
        if ($confidence !== null && ! array_key_exists($confidence, GoalCheckIn::CONFIDENCE)) {
            throw new RuntimeException("Unknown confidence '{$confidence}'.");
        }
        $goal = $subject instanceof Goal ? $subject : $subject->goal()->firstOrFail();

        if (! $goal->isOpen()) {
            throw new RuntimeException('This goal is closed.');
        }
        if ($goal->is_locked && $goal->performance_cycle_id && $goal->cycle()->value('status') === 'closed') {
            throw new RuntimeException('This goal belongs to a closed cycle.');
        }

        return DB::transaction(function () use ($subject, $goal, $value, $note, $confidence, $actor, $source, $measurement, $idempotencyKey) {
            // Serialize concurrent updates of the same goal; read the previous values under the lock.
            Goal::query()->whereKey($goal->id)->lockForUpdate()->first();
            $subject->refresh();
            $previousValue = $subject->current_value;
            $previousProgress = $subject->progress;
            $current = $value ?? (float) $subject->current_value;
            $progress = Goal::progressFor($subject->measure_type, (float) $subject->start_value, (float) $subject->target_value, $current);
            $subject->update(['current_value' => $current, 'progress' => $progress]);

            $checkIn = GoalCheckIn::create([
                'goal_id' => $goal->id,
                'key_result_id' => $subject instanceof KeyResult ? $subject->id : null,
                'previous_value' => $previousValue,
                'value' => $current,
                'previous_progress' => $previousProgress,
                'progress' => $progress,
                'confidence' => $confidence,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'measurement' => $measurement,
                'note' => $note,
                'created_by' => $actor?->id ?? auth()->id(),
            ]);

            $this->recompute($goal);
            PerformanceEvent::dispatch('performance.goal.progress_updated', $goal->employee, $goal, ['title' => $goal->title, 'progress' => (float) $goal->refresh()->progress, 'source' => $source], []);

            return $checkIn;
        });
    }

    /** Weighted average of key results (or children for organisation goals), then bubble up. */
    public function recompute(Goal $goal): void
    {
        $goal->loadMissing('keyResults');
        $sources = $goal->keyResults->isNotEmpty() ? $goal->keyResults : $goal->children()->where('status', '!=', 'cancelled')->get();

        if ($sources->isNotEmpty()) {
            $totalWeight = $sources->sum(fn ($s) => max(0, (int) $s->weight)) ?: $sources->count();
            $progress = $sources->sum(fn ($s) => (float) $s->progress * (max(0, (int) $s->weight) ?: ($sources->sum('weight') > 0 ? 0 : 1))) / $totalWeight;
            $goal->update(['progress' => round($progress, 2)]);
        }

        if ($goal->parent_id) {
            $parent = $goal->parent()->first();
            if ($parent && $parent->keyResults()->doesntExist()) {
                $this->recompute($parent);
            }
        }
    }

    public function close(Goal $goal, string $status, ?string $reason = null): Goal
    {
        if (! $goal->isOpen()) {
            throw new RuntimeException('This goal is already closed.');
        }
        if (! in_array($status, ['completed', 'cancelled'], true)) {
            throw new RuntimeException('Goals close as completed or cancelled.');
        }

        $goal->withAuditReason($reason)->update(['status' => $status, 'progress' => $status === 'completed' ? 100 : $goal->progress]);
        $this->audit->record($status === 'completed' ? AuditAction::Update : AuditAction::Cancelled, 'performance', $goal, [['field' => 'status', 'before' => 'active', 'after' => $status]], $reason);

        if ($goal->parent_id) {
            $this->recompute($goal->parent()->first());
        }

        return $goal;
    }

    /** Lock every active goal of the employees in a cycle so appraisals rate a stable set. */
    public function lockForCycle(int $cycleId, array $employeeIds): int
    {
        return Goal::query()->where('performance_cycle_id', $cycleId)->whereIn('employee_id', $employeeIds)->where('status', 'active')->update(['is_locked' => true]);
    }

    public function forEmployee(Employee $employee, ?int $cycleId = null)
    {
        return Goal::query()->with('keyResults')->where('employee_id', $employee->id)
            ->when($cycleId, fn ($q) => $q->where('performance_cycle_id', $cycleId))
            ->orderByDesc('weight')->orderBy('due_date')->get();
    }
}
