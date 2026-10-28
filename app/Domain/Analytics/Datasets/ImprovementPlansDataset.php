<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\ImprovementPlan;
use Illuminate\Database\Eloquent\Builder;

/** Performance improvement plans: dates and status only; reasons, objectives and outcomes stay on the plan. */
class ImprovementPlansDataset extends Dataset
{
    public function key(): string
    {
        return 'improvement_plans';
    }

    public function label(): string
    {
        return 'Performance improvement plans (PIP)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.pip'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($p) => $p->employee),
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($p) => $p->manager?->person?->full_name],
            'start_date' => ['label' => 'Start', 'type' => 'date', 'value' => fn ($p) => $p->start_date],
            'end_date' => ['label' => 'End', 'type' => 'date', 'value' => fn ($p) => $p->end_date],
            'days_left' => ['label' => 'Days left', 'type' => 'number', 'value' => fn ($p) => in_array($p->status, ImprovementPlan::FINAL, true) ? null : self::daysFromToday($p->end_date)],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($p) => $p->status],
            'closed_on' => ['label' => 'Closed on', 'type' => 'date', 'value' => fn ($p) => $p->closed_at],
            'count' => ['label' => 'Plans (1 per row)', 'type' => 'number', 'value' => fn ($p) => 1],
        ];
    }

    public function query(): Builder
    {
        return ImprovementPlan::query()->with([...self::employeeWith('employee'), 'manager.person'])->orderByDesc('start_date');
    }
}
