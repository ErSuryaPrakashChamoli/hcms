<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;

/** Mutable working state for one employee in one period; the calculator and statutory engine append lines. */
final class PayrollComputation
{
    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    /** @var array<int, array{type: string, message: string}> */
    public array $exceptions = [];

    public float $paidDays = 0;

    public float $lopDays = 0;

    public int $daysInPeriod = 0;

    /** @var array<string, mixed> */
    public array $inputs = [];

    public function __construct(
        public readonly Employee $employee,
        public readonly PayrollPeriod $period,
        public readonly ?EmployeeSalaryAssignment $assignment,
    ) {}

    public function addLine(string $code, string $name, string $type, float $amount, array $extra = []): void
    {
        $this->lines[] = [
            'code' => strtoupper($code),
            'name' => $name,
            'type' => $type,
            'amount' => round($amount, 2),
            'taxable' => (bool) ($extra['taxable'] ?? false),
            'classification' => $extra['classification'] ?? 'other',
            'salary_component_id' => $extra['salary_component_id'] ?? null,
            'basis' => $extra['basis'] ?? [],
            'sort_order' => $extra['sort_order'] ?? (count($this->lines) + 1) * 10,
            'pf_applicable' => (bool) ($extra['pf_applicable'] ?? false),
            'esi_applicable' => (bool) ($extra['esi_applicable'] ?? false),
            'include_in_gross' => (bool) ($extra['include_in_gross'] ?? false),
        ];
    }

    public function fromComponent(SalaryComponent $component, float $amount, array $basis = [], ?float $fullMonthAmount = null): void
    {
        $this->addLine($component->code, $component->name, $component->type, $amount, [
            'taxable' => $component->taxable && $component->type !== 'reimbursement',
            'classification' => $component->classification,
            'salary_component_id' => $component->id,
            'basis' => $basis + ['full_month' => round($fullMonthAmount ?? $amount, 2)],
            'sort_order' => $component->sort_order,
            'pf_applicable' => $component->pf_applicable,
            'esi_applicable' => $component->esi_applicable,
            'include_in_gross' => $component->include_in_gross,
        ]);
    }

    public function exception(string $type, string $message): void
    {
        $this->exceptions[] = ['type' => $type, 'message' => $message];
    }

    public function amount(string $code): float
    {
        return (float) (collect($this->lines)->firstWhere('code', strtoupper($code))['amount'] ?? 0);
    }

    public function has(string $code): bool
    {
        return collect($this->lines)->contains('code', strtoupper($code));
    }

    public function sum(callable $filter): float
    {
        return round(collect($this->lines)->filter($filter)->sum('amount'), 2);
    }

    public function earnings(): float
    {
        return $this->sum(fn ($l) => in_array($l['type'], ['earning', 'reimbursement'], true));
    }

    public function gross(): float
    {
        return $this->sum(fn ($l) => $l['type'] === 'earning' && $l['include_in_gross']);
    }

    public function pfWages(): float
    {
        return $this->sum(fn ($l) => $l['type'] === 'earning' && $l['pf_applicable']);
    }

    public function esiWages(): float
    {
        return $this->sum(fn ($l) => $l['type'] === 'earning' && $l['esi_applicable']);
    }

    public function taxableEarnings(): float
    {
        return $this->sum(fn ($l) => in_array($l['type'], ['earning'], true) && $l['taxable']);
    }

    public function deductions(): float
    {
        return $this->sum(fn ($l) => $l['type'] === 'deduction');
    }

    public function employerContributions(): float
    {
        return $this->sum(fn ($l) => $l['type'] === 'employer_contribution');
    }

    public function net(): float
    {
        return round($this->earnings() - $this->deductions(), 2);
    }

    public function blocking(): bool
    {
        return collect($this->exceptions)->contains(fn ($e) => in_array($e['type'], ['no_salary', 'no_structure', 'negative_net', 'formula_error'], true));
    }
}
