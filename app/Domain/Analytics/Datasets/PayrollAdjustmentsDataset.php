<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Payroll\Models\PayrollAdjustment;
use Illuminate\Database\Eloquent\Builder;

class PayrollAdjustmentsDataset extends Dataset
{
    public function key(): string
    {
        return 'payroll_adjustments';
    }

    public function label(): string
    {
        return 'Arrears, bonuses, reimbursements, recoveries and other one-time pay';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function fields(): array
    {
        return [
            'period' => ['label' => 'Pay period', 'type' => 'string', 'value' => fn ($a) => $a->period?->key()],
            'period_start' => ['label' => 'Pay period start', 'type' => 'date', 'value' => fn ($a) => $a->period?->start_date],
            ...$this->employeeFields(fn ($a) => $a->employee),
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($a) => $a->type],
            'type_label' => ['label' => 'Type (label)', 'type' => 'string', 'value' => fn ($a) => PayrollAdjustment::TYPES[$a->type] ?? $a->type],
            'description' => ['label' => 'Description', 'type' => 'string', 'value' => fn ($a) => $a->name],
            'component' => ['label' => 'Component', 'type' => 'string', 'value' => fn ($a) => $a->component?->name],
            'amount' => ['label' => 'Amount', 'type' => 'number', 'value' => fn ($a) => round((float) $a->amount, 2)],
            'taxable' => ['label' => 'Taxable', 'type' => 'boolean', 'value' => fn ($a) => (bool) $a->taxable],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($a) => $a->status],
            'approved_on' => ['label' => 'Approved on', 'type' => 'date', 'value' => fn ($a) => $a->approved_at],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn ($a) => $a->note],
            'count' => ['label' => 'Adjustments (1 per row)', 'type' => 'number', 'value' => fn ($a) => 1],
        ];
    }

    public function query(): Builder
    {
        return PayrollAdjustment::query()->with([...self::employeeWith('employee'), 'period', 'component'])->orderByDesc('id');
    }
}
