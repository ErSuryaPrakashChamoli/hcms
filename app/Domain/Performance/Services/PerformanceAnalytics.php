<?php

namespace App\Domain\Performance\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Performance\Models\Appraisal;
use App\Domain\Performance\Models\Goal;
use App\Domain\Performance\Models\PerformanceCheckIn;
use App\Domain\Performance\Models\PerformanceCycle;
use Illuminate\Support\Collection;

/**
 * Phase 7 aggregated performance analytics. Every group smaller than
 * `peopleos.performance.analytics_min_group` is suppressed (counts and ratings hidden) so no
 * individual's rating can be inferred. Descriptive only: no ranking, scoring or recommendations.
 */
final class PerformanceAnalytics
{
    public function minGroup(): int
    {
        return max(1, (int) config('peopleos.performance.analytics_min_group', 5));
    }

    /** @return array{cycle: ?string, min_group: int, overall: array<string, mixed>, groups: list<array<string, mixed>>} */
    public function summary(?PerformanceCycle $cycle = null): array
    {
        $appraisals = Appraisal::query()->with(['employee.currentPosition.department'])->when($cycle, fn ($q) => $q->where('performance_cycle_id', $cycle->id))->get();
        $employeeIds = $appraisals->pluck('employee_id')->unique();
        if ($cycle === null) {
            $employeeIds = Employee::query()->employed()->pluck('id');
        }
        $goals = Goal::query()->whereIn('employee_id', $employeeIds)->when($cycle, fn ($q) => $q->where('performance_cycle_id', $cycle->id))->whereIn('status', ['active', 'completed'])->get(['id', 'employee_id', 'progress']);
        $checkIns = PerformanceCheckIn::query()->whereIn('employee_id', $employeeIds)->where('period_date', '>=', now()->subDays(90)->toDateString())->whereIn('status', ['submitted', 'reviewed'])->get(['id', 'employee_id']);
        $departments = Employee::query()->with('currentPosition.department')->whereIn('id', $employeeIds)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->currentPosition?->department?->name ?? 'Unassigned']);
        $scale = $cycle?->ratingScale();

        $block = function (Collection $ids) use ($appraisals, $goals, $checkIns, $scale): array {
            $a = $appraisals->whereIn('employee_id', $ids);
            $size = $ids->count();
            if ($size < $this->minGroup()) {
                return ['employees' => null, 'suppressed' => true];
            }
            $final = $a->whereIn('status', ['finalized', 'acknowledged']);

            return [
                'employees' => $size,
                'suppressed' => false,
                'appraisals' => $a->count(),
                'appraisal_completion' => $a->isEmpty() ? null : round($final->count() / $a->count() * 100, 1),
                'average_goal_progress' => $goals->whereIn('employee_id', $ids)->isEmpty() ? null : round((float) $goals->whereIn('employee_id', $ids)->avg('progress'), 1),
                'check_ins_90_days' => $checkIns->whereIn('employee_id', $ids)->count(),
                'rating_distribution' => $scale === null || $final->count() < $this->minGroup() ? null
                    : collect($scale->levels)->mapWithKeys(fn ($l) => [$l['label'] => $final->filter(fn (Appraisal $x) => $scale->labelFor($x->effectiveRating()) === $l['label'])->count()])->all(),
            ];
        };

        return [
            'cycle' => $cycle?->code,
            'min_group' => $this->minGroup(),
            'overall' => $block($employeeIds->values()),
            'groups' => $departments->groupBy(fn ($name) => $name, true)->map(fn (Collection $members, string $name) => ['group' => $name] + $block($members->keys()))->values()->all(),
        ];
    }
}
