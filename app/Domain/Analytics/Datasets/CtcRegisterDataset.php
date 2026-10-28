<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationSnapshot;
use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Current salary per employee, read through the Compensation read contract (approved compensation in
 * force today only; proposals, reasons and notes never reach a report). History is salary_revisions.
 */
class CtcRegisterDataset extends Dataset
{
    /** @var array{0: int, 1: CompensationSnapshot|null}|null snapshot of the row being mapped */
    private ?array $current = null;

    public function key(): string
    {
        return 'ctc_register';
    }

    public function label(): string
    {
        return 'CTC register (current salary per employee)';
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
            ...$this->employeeFields(fn ($e) => $e),
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($e) => $e->joining_date],
            'has_salary' => ['label' => 'Has an approved salary', 'type' => 'boolean', 'value' => fn ($e) => $this->snapshot($e) !== null],
            'structure' => ['label' => 'Salary structure', 'type' => 'string', 'value' => fn ($e) => $this->snapshot($e)?->structureCode],
            'ctc_annual' => ['label' => 'Annual CTC', 'type' => 'number', 'value' => fn ($e) => $this->snapshot($e)?->ctcAnnual],
            'ctc_monthly' => ['label' => 'Monthly CTC', 'type' => 'number', 'value' => fn ($e) => $this->snapshot($e)?->monthlyCtc()],
            'variable_annual' => ['label' => 'Variable target (annual)', 'type' => 'number', 'value' => fn ($e) => $this->snapshot($e)?->variableTargetAnnual],
            'fixed_annual' => ['label' => 'Fixed (annual)', 'type' => 'number', 'value' => fn ($e) => ($s = $this->snapshot($e)) ? round($s->ctcAnnual - (float) $s->variableTargetAnnual, 2) : null],
            'currency' => ['label' => 'Currency', 'type' => 'string', 'value' => fn ($e) => $this->snapshot($e)?->currency],
            'pay_frequency' => ['label' => 'Pay frequency', 'type' => 'string', 'value' => fn ($e) => $this->snapshot($e)?->payFrequency],
            'change_type' => ['label' => 'Last change', 'type' => 'string', 'value' => fn ($e) => ($s = $this->snapshot($e)) ? Str::headline($s->changeType) : null],
            'effective_from' => ['label' => 'Salary effective from', 'type' => 'date', 'value' => fn ($e) => $this->snapshot($e)?->effectiveFrom],
        ];
    }

    public function query(): Builder
    {
        return Employee::query()->with(['person', 'currentPosition.company', 'currentPosition.location', 'currentPosition.department', 'currentPosition.designation'])->orderBy('employee_code');
    }

    /** Compensation in force today, fetched once per employee row. */
    private function snapshot(Employee $employee): ?CompensationSnapshot
    {
        if ($this->current === null || $this->current[0] !== $employee->id) {
            $this->current = [$employee->id, app(CompensationOutput::class)->on($employee)];
        }

        return $this->current[1];
    }
}
