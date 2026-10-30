<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compliance\Models\TaxIncomeEntry;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-3: perquisites and income paid outside payroll that TDS counts. */
class TaxIncomeEntriesDataset extends Dataset
{
    public function key(): string
    {
        return 'tax_income_entries';
    }

    public function label(): string
    {
        return 'Perquisites and income outside payroll';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.manage'];
    }

    public function fields(): array
    {
        return [
            'financial_year' => ['label' => 'Financial year', 'type' => 'string', 'value' => fn ($r) => $r->financial_year],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'kind' => ['label' => 'Kind', 'type' => 'string', 'value' => fn ($r) => TaxIncomeEntry::KINDS[$r->kind] ?? $r->kind],
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($r) => $r->typeLabel()],
            'description' => ['label' => 'Description', 'type' => 'string', 'value' => fn ($r) => $r->description],
            'paid_on' => ['label' => 'Paid on', 'type' => 'date', 'value' => fn ($r) => $r->paid_on],
            'amount' => ['label' => 'Value / paid', 'type' => 'number', 'value' => fn ($r) => (float) $r->amount],
            'recovered' => ['label' => 'Recovered', 'type' => 'number', 'value' => fn ($r) => (float) $r->recovered],
            'taxable_amount' => ['label' => 'Taxable', 'type' => 'number', 'value' => fn ($r) => (float) $r->taxable_amount],
            'tds_deducted' => ['label' => 'TDS deducted', 'type' => 'number', 'value' => fn ($r) => (float) $r->tds_deducted],
            'pf_employee' => ['label' => 'PF deducted', 'type' => 'number', 'value' => fn ($r) => (float) $r->pf_employee],
            'professional_tax' => ['label' => 'PT deducted', 'type' => 'number', 'value' => fn ($r) => (float) $r->professional_tax],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => TaxIncomeEntry::STATUSES[$r->status] ?? $r->status],
            'count' => ['label' => 'Entries (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return TaxIncomeEntry::query()->with(self::employeeWith('employee'))->orderByDesc('id');
    }
}
