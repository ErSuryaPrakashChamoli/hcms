<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use Illuminate\Database\Eloquent\Builder;

/** One row per employee per financial year: regime, status and declared / proved totals. */
class TaxDeclarationsDataset extends Dataset
{
    /** @var array<int, object{declared: float, proved: float, items: int, pending: int}>|null investment totals by declaration */
    private ?array $totals = null;

    public function key(): string
    {
        return 'tax_declarations';
    }

    public function label(): string
    {
        return 'Income-tax declarations (regime, declared and proved amounts)';
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
        // Guided declarations keep item rows; older ones keep section amounts on the declaration itself.
        $declared = fn ($d) => isset($this->totals()[$d->id]) ? $this->totals()[$d->id]->declared : (float) collect($d->declarations ?? [])->filter(fn ($v) => is_numeric($v))->sum();

        return [
            'financial_year' => ['label' => 'Financial year', 'type' => 'string', 'value' => fn ($d) => $d->financial_year],
            ...$this->employeeFields(fn ($d) => $d->employee),
            'regime' => ['label' => 'Tax regime', 'type' => 'string', 'value' => fn ($d) => $d->regime],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($d) => $d->status],
            'declared_total' => ['label' => 'Declared (total)', 'type' => 'number', 'value' => fn ($d) => round($declared($d), 2)],
            'proof_total' => ['label' => 'Proof accepted (total)', 'type' => 'number', 'value' => fn ($d) => round($this->totals()[$d->id]->proved ?? 0, 2)],
            'items' => ['label' => 'Investment items', 'type' => 'number', 'value' => fn ($d) => $this->totals()[$d->id]->items ?? 0],
            'proofs_pending' => ['label' => 'Proofs pending verification', 'type' => 'number', 'value' => fn ($d) => $this->totals()[$d->id]->pending ?? 0],
            'previous_employer_income' => ['label' => 'Previous employer income', 'type' => 'number', 'value' => fn ($d) => (float) $d->previous_employer_income],
            'previous_employer_tds' => ['label' => 'Previous employer TDS', 'type' => 'number', 'value' => fn ($d) => (float) $d->previous_employer_tds],
            'submitted_on' => ['label' => 'Last updated', 'type' => 'date', 'value' => fn ($d) => $d->updated_at],
            'verified_on' => ['label' => 'Verified on', 'type' => 'date', 'value' => fn ($d) => $d->verified_at],
        ];
    }

    public function query(): Builder
    {
        return EmployeeTaxDeclaration::query()->with(self::employeeWith('employee'))->orderByDesc('financial_year')->orderBy('employee_id');
    }

    /** @return array<int, object> */
    private function totals(): array
    {
        return $this->totals ??= TdsEmployeeInvestment::query()->whereNotNull('employee_tax_declaration_id')
            ->selectRaw("employee_tax_declaration_id, sum(declared_amount) as declared, sum(case when proof_status = 'verified' then proof_amount else 0 end) as proved, count(*) as items, sum(case when proof_status = 'pending' and proof_amount > 0 then 1 else 0 end) as pending")
            ->groupBy('employee_tax_declaration_id')->toBase()->get()
            ->mapWithKeys(fn ($row) => [(int) $row->employee_tax_declaration_id => (object) ['declared' => (float) $row->declared, 'proved' => (float) $row->proved, 'items' => (int) $row->items, 'pending' => (int) $row->pending]])
            ->all();
    }
}
