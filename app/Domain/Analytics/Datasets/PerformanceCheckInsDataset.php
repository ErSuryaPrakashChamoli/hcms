<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\PerformanceCheckIn;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity AN-27 (with the goal check-ins of HROne's check-in reports): the recurring employee ↔ manager check-ins
 * — cadence, period, whether the employee submitted and the manager reviewed them, how many goals were updated and
 * actions agreed. For performance HR (performance.view, as the Check-ins screen) in their access scope. What went well,
 * blockers, support needed, priorities and the feedback both ways stay on the check-in.
 */
class PerformanceCheckInsDataset extends Dataset
{
    public function key(): string
    {
        return 'performance_check_ins';
    }

    public function label(): string
    {
        return 'Check-in conversations (employee and manager)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.view'];
    }

    public function fields(): array
    {
        return [
            'period' => ['label' => 'Period', 'type' => 'date', 'value' => fn (PerformanceCheckIn $c) => $c->period_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (PerformanceCheckIn $c) => $c->period_date?->format('Y-m')],
            'cycle' => ['label' => 'Cycle', 'type' => 'string', 'value' => fn (PerformanceCheckIn $c) => $c->cycle?->name],
            ...$this->employeeFields(fn (PerformanceCheckIn $c) => $c->employee),
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn (PerformanceCheckIn $c) => $c->manager?->person?->full_name],
            'cadence' => ['label' => 'Cadence', 'type' => 'string', 'value' => fn (PerformanceCheckIn $c) => config("peopleos.performance.check_in_cadences.{$c->cadence}", $c->cadence)],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (PerformanceCheckIn $c) => $c->status],
            'submitted_on' => ['label' => 'Submitted on', 'type' => 'date', 'value' => fn (PerformanceCheckIn $c) => $c->submitted_at],
            'reviewed_on' => ['label' => 'Reviewed on', 'type' => 'date', 'value' => fn (PerformanceCheckIn $c) => $c->reviewed_at],
            'reviewed_by' => ['label' => 'Reviewed by', 'type' => 'string', 'value' => fn (PerformanceCheckIn $c) => $c->reviewer?->name],
            'goals_updated' => ['label' => 'Goals updated', 'type' => 'number', 'value' => fn (PerformanceCheckIn $c) => count((array) ($c->goal_progress ?? []))],
            'actions' => ['label' => 'Actions agreed', 'type' => 'number', 'value' => fn (PerformanceCheckIn $c) => count((array) ($c->actions ?? []))],
            'count' => ['label' => 'Check-ins (1 per row)', 'type' => 'number', 'value' => fn (PerformanceCheckIn $c) => 1],
        ];
    }

    public function query(): Builder
    {
        return PerformanceCheckIn::query()->with([...self::employeeWith('employee'), 'manager.person', 'cycle', 'reviewer'])->orderByDesc('period_date')->orderByDesc('id');
    }
}
