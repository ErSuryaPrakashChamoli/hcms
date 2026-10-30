<?php

namespace App\Domain\Expense\Support;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * HROne setup parity EX-B: the yearly amount a percentage-of-CTC expense limit is taken of — the employee's total CTC,
 * or one CTC pay component — on the expense date. Compensation is read only through the CompensationOutput contract
 * (never its tables); a formula component (e.g. Basic = 40% of CTC) is worked out with the structure's own formulas, as
 * the salary annexure does (no salary logic of its own). Null when no approved compensation is in force or the
 * component's amount cannot be worked out: the caller then applies no money (fail closed).
 */
final class CompensationAmounts
{
    public function __construct(private readonly CompensationOutput $compensation, private readonly StructureFormulas $formulas) {}

    public function annual(Employee $employee, CarbonInterface $on, ?SalaryComponent $component = null): ?float
    {
        $snapshot = $this->compensation->on($employee, $on);
        if ($snapshot === null) {
            return null;
        }
        if ($component === null) {
            return round($snapshot->ctcAnnual, 2);
        }
        if ($snapshot->hasValueFor($component->code)) {
            return round($snapshot->valueFor($component->code) * 12, 2);
        }

        $segment = $this->compensation->forPayroll($employee, $on, $on)->last();
        if ($segment === null || $segment->components->isEmpty()) {
            return null;
        }
        $lines = $segment->components->map(fn (CompensationComponentLine $line) => ['component' => $line->component, 'formula_override' => $line->formulaOverride, 'sort_order' => $line->sortOrder])->all();
        try {
            $preview = $this->formulas->preview($lines, $segment->parameters, [
                'ctc_annual' => $segment->compensation->ctcAnnual, 'variable_target_annual' => $segment->compensation->variableTargetAnnual,
                'values' => $segment->compensation->componentValues, 'attributes' => FormulaVariables::employeeAttributes($employee, $on), 'days' => 30,
                'pf_applies' => $employee->statutoryDetail?->pf_applicable ?? true, 'pf_restrict_to_ceiling' => true, 'on' => $on,
            ]);
        } catch (RuntimeException) {
            return null;
        }
        $row = collect($preview['rows'])->firstWhere('code', $component->code);

        return $row !== null && $row['monthly'] !== null ? round((float) $row['monthly'] * 12, 2) : null;
    }
}
