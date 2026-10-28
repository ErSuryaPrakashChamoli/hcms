<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use Illuminate\Database\Eloquent\Builder;

class TaxInvestmentsDataset extends Dataset
{
    public function key(): string
    {
        return 'tax_investments';
    }

    public function label(): string
    {
        return 'Investment declarations and proofs by section (80C, 80D, HRA …)';
    }

    public function category(): string
    {
        return 'Income tax';
    }

    public function permissions(): array
    {
        return ['compliance.returns.view', 'compliance.tds.manage'];
    }

    public function fields(): array
    {
        return [
            'financial_year' => ['label' => 'Financial year', 'type' => 'string', 'value' => fn ($i) => $i->financial_year],
            ...$this->employeeFields(fn ($i) => $i->employee),
            'section' => ['label' => 'Section', 'type' => 'string', 'value' => fn ($i) => $i->section],
            'item' => ['label' => 'Item', 'type' => 'string', 'value' => fn ($i) => $i->description],
            'declared_amount' => ['label' => 'Declared', 'type' => 'number', 'value' => fn ($i) => round((float) $i->declared_amount, 2)],
            'proof_amount' => ['label' => 'Proof amount', 'type' => 'number', 'value' => fn ($i) => round((float) $i->proof_amount, 2)],
            'proof_status' => ['label' => 'Proof status', 'type' => 'string', 'value' => fn ($i) => $i->proof_status],
            'has_document' => ['label' => 'Proof uploaded', 'type' => 'boolean', 'value' => fn ($i) => $i->employee_document_id !== null],
            'verified_on' => ['label' => 'Verified on', 'type' => 'date', 'value' => fn ($i) => $i->verified_at],
            'count' => ['label' => 'Items (1 per row)', 'type' => 'number', 'value' => fn ($i) => 1],
        ];
    }

    public function query(): Builder
    {
        return TdsEmployeeInvestment::query()->with(self::employeeWith('employee'))->orderByDesc('financial_year')->orderBy('employee_id')->orderBy('section');
    }
}
