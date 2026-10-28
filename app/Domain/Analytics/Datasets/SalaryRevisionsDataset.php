<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compensation\Models\CompensationChange;
use Illuminate\Database\Eloquent\Builder;

/** Compensation changes (hikes, promotions, corrections) with old and new CTC; internal notes are never reported. */
class SalaryRevisionsDataset extends Dataset
{
    public function key(): string
    {
        return 'salary_revisions';
    }

    public function label(): string
    {
        return 'Salary revisions and increments (old CTC, new CTC, % hike)';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['compensation.view'];
    }

    public function fields(): array
    {
        return [
            'reference' => ['label' => 'Reference', 'type' => 'string', 'value' => fn ($c) => $c->reference],
            ...$this->employeeFields(fn ($c) => $c->employee),
            'change_type' => ['label' => 'Change type', 'type' => 'string', 'value' => fn ($c) => $c->change_type],
            'source' => ['label' => 'Source', 'type' => 'string', 'value' => fn ($c) => config('peopleos.compensation.sources.'.$c->source, $c->source)],
            'cycle' => ['label' => 'Compensation cycle', 'type' => 'string', 'value' => fn ($c) => $c->cycle?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($c) => $c->status],
            'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'value' => fn ($c) => $c->effective_from],
            'month' => ['label' => 'Effective month', 'type' => 'string', 'value' => fn ($c) => $c->effective_from?->format('Y-m')],
            'previous_ctc' => ['label' => 'Previous CTC', 'type' => 'number', 'value' => fn ($c) => $c->previous_ctc_annual !== null ? (float) $c->previous_ctc_annual : null],
            'new_ctc' => ['label' => 'New CTC', 'type' => 'number', 'value' => fn ($c) => (float) $c->ctc_annual],
            'increase_amount' => ['label' => 'Increase (amount)', 'type' => 'number', 'value' => fn ($c) => $c->previous_ctc_annual !== null ? round((float) $c->ctc_annual - (float) $c->previous_ctc_annual, 2) : null],
            'increase_percent' => ['label' => 'Increase (%)', 'type' => 'number', 'value' => fn ($c) => $c->increase_percent !== null ? (float) $c->increase_percent : ((float) $c->previous_ctc_annual > 0 ? round(((float) $c->ctc_annual / (float) $c->previous_ctc_annual - 1) * 100, 2) : null)],
            'performance_label' => ['label' => 'Performance rating', 'type' => 'string', 'value' => fn ($c) => $c->performance_label],
            'from_grade' => ['label' => 'From grade', 'type' => 'string', 'value' => fn ($c) => $c->fromGrade?->name],
            'to_grade' => ['label' => 'To grade', 'type' => 'string', 'value' => fn ($c) => $c->toGrade?->name],
            'approved_on' => ['label' => 'Approved on', 'type' => 'date', 'value' => fn ($c) => $c->approved_at],
            'is_pending' => ['label' => 'Awaiting approval', 'type' => 'boolean', 'value' => fn ($c) => in_array($c->status, CompensationChange::PENDING, true)],
            'count' => ['label' => 'Changes (1 per row)', 'type' => 'number', 'value' => fn ($c) => 1],
        ];
    }

    public function query(): Builder
    {
        return CompensationChange::query()->with([...self::employeeWith('employee'), 'cycle', 'fromGrade', 'toGrade'])->orderByDesc('effective_from');
    }
}
