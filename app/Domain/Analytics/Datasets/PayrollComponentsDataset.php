<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Payroll\Models\PayrollEntryLine;
use Illuminate\Database\Eloquent\Builder;

/** One row per pay component per employee per finalized or paid run: the component-wise salary register. */
class PayrollComponentsDataset extends Dataset
{
    public function key(): string
    {
        return 'payroll_components';
    }

    public function label(): string
    {
        return 'Salary register by component (earnings, deductions, employer contributions)';
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
            'period' => ['label' => 'Period', 'type' => 'string', 'value' => fn ($l) => $l->entry?->run?->period?->key()],
            'period_start' => ['label' => 'Period start', 'type' => 'date', 'value' => fn ($l) => $l->entry?->run?->period?->start_date],
            ...$this->employeeFields(fn ($l) => $l->entry?->employee),
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($l) => $l->entry?->run?->company?->name],
            'component_code' => ['label' => 'Component code', 'type' => 'string', 'value' => fn ($l) => $l->code],
            'component' => ['label' => 'Component', 'type' => 'string', 'value' => fn ($l) => $l->name],
            'component_type' => ['label' => 'Component type', 'type' => 'string', 'value' => fn ($l) => config('peopleos.payroll.component_types.'.$l->type, $l->type)],
            'classification' => ['label' => 'Classification', 'type' => 'string', 'value' => fn ($l) => config('peopleos.payroll.classifications.'.$l->classification, $l->classification)],
            'amount' => ['label' => 'Amount', 'type' => 'number', 'value' => fn ($l) => round((float) $l->amount, 2)],
            'taxable' => ['label' => 'Taxable', 'type' => 'boolean', 'value' => fn ($l) => (bool) $l->taxable],
            'paid_days' => ['label' => 'Paid days', 'type' => 'number', 'value' => fn ($l) => (float) $l->entry?->paid_days],
        ];
    }

    public function query(): Builder
    {
        // Lines are not access-scoped themselves; requiring a visible entry applies the viewer's scope.
        return PayrollEntryLine::query()
            ->whereHas('entry', fn ($q) => $q->whereHas('run', fn ($r) => $r->whereIn('status', ['finalized', 'paid'])))
            ->with(['entry.run.period', 'entry.run.company', ...self::employeeWith('entry.employee')])
            ->orderByDesc('payroll_entry_id')->orderBy('sort_order');
    }
}
