<?php

namespace App\Domain\Performance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Events\PerformanceEvent;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\GoalCheckIn;
use App\Domain\Performance\Models\KeyResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Goal cascade, key results and check-ins (§34). Progress rolls up from key results and from child goals. */
final class Goals
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<int, array<string, mixed>>  $keyResults */
    public function create(array $attributes, array $keyResults = [], ?User $actor = null): Goal
    {
        $attributes['level'] ??= isset($attributes['employee_id']) ? 'employee' : 'company';
        $attributes['status'] ??= 'active';
        $attributes['created_by'] ??= $actor?->id ?? auth()->id();

        if (isset($attributes['parent_id'])) {
            $parent = Goal::query()->findOrFail($attributes['parent_id']);
            if (! $parent->isOpen()) {
                throw new RuntimeException('Cannot align to a closed goal.');
            }
        }

        return DB::transaction(function () use ($attributes, $keyResults) {
            $goal = Goal::create($attributes);

            foreach ($keyResults as $i => $kr) {
                $goal->keyResults()->create($kr + ['sort_order' => ($i + 1) * 10, 'progress' => Goal::progressFor($kr['measure_type'] ?? 'percentage', (float) ($kr['start_value'] ?? 0), (float) ($kr['target_value'] ?? 100), (float) ($kr['current_value'] ?? 0))]);
            }

            $this->recompute($goal);

            if ($goal->employee_id) {
                PerformanceEvent::dispatch('performance.goal.assigned', $goal->employee, $goal, ['title' => $goal->title, 'due' => $goal->due_date?->toDateString()], [$goal->employee_id]);
            }

            return $goal->refresh();
        });
    }

    public function checkIn(Goal|KeyResult $subject, ?float $value, ?string $note = null, ?string $confidence = null, ?User $actor = null): GoalCheckIn
    {
        $goal = $subject instanceof Goal ? $subject : $subject->goal()->firstOrFail();

        if (! $goal->isOpen()) {
            throw new RuntimeException('This goal is closed.');
        }
        if ($goal->is_locked && $goal->performance_cycle_id && $goal->cycle()->value('status') === 'closed') {
            throw new RuntimeException('This goal belongs to a closed cycle.');
        }

        return DB::transaction(function () use ($subject, $goal, $value, $note, $confidence, $actor) {
            $current = $value ?? (float) $subject->current_value;
            $progress = Goal::progressFor($subject->measure_type, (float) $subject->start_value, (float) $subject->target_value, $current);
            $subject->update(['current_value' => $current, 'progress' => $progress]);

            $checkIn = GoalCheckIn::create([
                'goal_id' => $goal->id,
                'key_result_id' => $subject instanceof KeyResult ? $subject->id : null,
                'value' => $current,
                'progress' => $progress,
                'confidence' => $confidence,
                'note' => $note,
                'created_by' => $actor?->id ?? auth()->id(),
            ]);

            $this->recompute($goal);

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
