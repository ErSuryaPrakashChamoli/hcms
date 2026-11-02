<?php

namespace App\Domain\Letters\Services;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Compliance\Services\VerifiedThresholds;
use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Models\Letter;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * HROne parity WO-13 (§23.7.1 #16: "This employee's wages are currently below the defined minimum wage …", "Exclusions are
 * greater than wages …"): the compliance warnings of a salary letter (one whose template prints salary variables).
 *
 * - Figures come through CompensationOutput — the approved compensation in force on the letter's salary date (the exit date
 *   for a leaver, as the letter itself) — and its breakup from the structure's own formulas, as the salary annexure and
 *   the payroll readiness panel (PO-3) work it out: wages = earnings classified basic / DA, against gross pay.
 * - Thresholds are statutory values and come ONLY from verified rule data (VerifiedThresholds: `WAGES.min_wage_share`,
 *   `MIN_WAGE.monthly_minimum` for the state of the employee's establishment). Without such a rule the check says so and
 *   does not run — there is no fallback number.
 * - Read-only and informative: it never blocks generating, approving or issuing a letter. No amounts are shown.
 */
final class LetterSalaryChecks
{
    public function __construct(
        private readonly CompensationOutput $compensation,
        private readonly StructureFormulas $formulas,
        private readonly VerifiedThresholds $thresholds,
        private readonly EstablishmentAssignments $establishments,
    ) {}

    /**
     * @return list<array{check: string, state: string, text: string}> state: attention | no_rule | ok | not_run
     */
    public function forLetter(Letter $letter): array
    {
        if (! $letter->usesSalary()) {
            return [];
        }
        $employee = $letter->employee()->with('statutoryDetail')->first();
        if ($employee === null) {
            return [];
        }
        $letterDate = Carbon::parse($letter->created_at ?? now())->startOfDay();
        $on = $employee->exit_date && $employee->exit_date->lt($letterDate) ? $employee->exit_date->copy()->startOfDay() : $letterDate;

        return $this->forEmployee($employee, $on);
    }

    /** @return list<array{check: string, state: string, text: string}> */
    public function forEmployee(Employee $employee, CarbonInterface $on): array
    {
        $breakup = $this->breakup($employee, $on);
        if ($breakup === null) {
            return [['check' => 'salary', 'state' => 'not_run', 'text' => 'No approved salary breakup on '.$on->format('d M Y').': the compliance checks did not run.']];
        }
        ['wages' => $wages, 'gross' => $gross] = $breakup;
        $out = [];

        $share = $this->thresholds->value('WAGES', 'min_wage_share', $on);
        if ($share === null) {
            $out[] = ['check' => 'wage_share', 'state' => 'no_rule', 'text' => 'No verified wage rule: the Code on Wages check (exclusions against wages) does not run.'];
        } elseif ($gross > 0) {
            $percent = round($wages / $gross * 100, 1);
            $out[] = $percent < $share['value']
                ? ['check' => 'wage_share', 'state' => 'attention', 'text' => "Exclusions are greater than wages: basic and DA are {$percent}% of gross pay, below the {$share['value']}% of the verified wage rule ({$share['rule']}). Payroll may want to review the salary structure breakup."]
                : ['check' => 'wage_share', 'state' => 'ok', 'text' => "Wages are {$percent}% of gross pay, within the verified wage rule ({$share['rule']})."];
        }

        $state = $this->state($employee, $on);
        $minimum = $state !== null ? $this->thresholds->value('MIN_WAGE', 'monthly_minimum', $on, $state) : null;
        if ($minimum === null) {
            $out[] = ['check' => 'minimum_wage', 'state' => 'no_rule', 'text' => 'No verified minimum wage rule'.($state ? ' for '.$state : ' (no state for the employee\'s establishment)').': the minimum wage check does not run.'];
        } else {
            $out[] = $wages < $minimum['value']
                ? ['check' => 'minimum_wage', 'state' => 'attention', 'text' => "This employee's wages are currently below the defined minimum wage of {$state} ({$minimum['rule']})."]
                : ['check' => 'minimum_wage', 'state' => 'ok', 'text' => "Wages are at or above the minimum wage of {$state} ({$minimum['rule']})."];
        }

        return $out;
    }

    /** The employee's establishment state on the date (assignment, location or company's principal establishment). */
    private function state(Employee $employee, CarbonInterface $on): ?string
    {
        $state = $this->establishments->resolve($employee, $on)['establishment']?->state;

        return filled($state) ? strtoupper((string) $state) : null;
    }

    /** @return array{wages: float, gross: float}|null a full month's breakup, as the salary annexure works it out */
    private function breakup(Employee $employee, CarbonInterface $on): ?array
    {
        try {
            $segment = $this->compensation->forPayroll($employee, $on, $on)->last();
            if ($segment === null || $segment->components->isEmpty()) {
                return null;
            }
            $lines = $segment->components->map(fn (CompensationComponentLine $line) => ['component' => $line->component, 'formula_override' => $line->formulaOverride, 'sort_order' => $line->sortOrder])->all();
            $preview = $this->formulas->preview($lines, $segment->parameters, [
                'ctc_annual' => $segment->compensation->ctcAnnual, 'variable_target_annual' => $segment->compensation->variableTargetAnnual,
                'values' => $segment->compensation->componentValues, 'ctc_mode' => $segment->ctcMode,
                'attributes' => FormulaVariables::employeeAttributes($employee, $on), 'days' => 30,
                'pf_applies' => $employee->statutoryDetail?->pf_applicable ?? true, 'pf_restrict_to_ceiling' => true, 'on' => $on,
            ]);
        } catch (Throwable) {
            return null;
        }
        if (($preview['errors'] ?? []) !== []) {
            return null;
        }
        $byCode = $segment->components->mapWithKeys(fn (CompensationComponentLine $line) => [$line->component->code => $line->component]);
        $classes = (array) config('peopleos.payroll.guardrails.wage_share.classifications', ['basic', 'da']);
        $wages = 0.0;
        foreach ($preview['rows'] as $row) {
            /** @var SalaryComponent|null $component */
            $component = $byCode[$row['code']] ?? null;
            if ($component !== null && $row['monthly'] !== null && $component->isEarning() && in_array($component->classification, $classes, true)) {
                $wages += (float) $row['monthly'];
            }
        }

        return ['wages' => round($wages, 2), 'gross' => (float) ($preview['totals']['gross'] ?? 0)];
    }
}
