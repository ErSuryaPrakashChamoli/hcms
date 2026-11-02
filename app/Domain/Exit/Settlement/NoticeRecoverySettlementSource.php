<?php

namespace App\Domain\Exit\Settlement;

use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Contracts\SettlementSource;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Exit\Support\NoticeShortfall;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\StructureFormulas;
use App\Domain\Payroll\Support\FormulaVariables;
use Carbon\CarbonInterface;
use Throwable;

/**
 * HROne parity WO-11 (§23.6.1 #6, "Total / actual recovery amount"): the notice shortfall recovered in the full and final
 * settlement on the exit policy's own rule (`notice_recovery_rule` = policy): shortfall days × a daily rate, where the
 * daily rate is the monthly pay of the policy's basis (gross, basic, basic + DA or CTC) divided by the policy's divisor
 * (a fixed number of days, or the days of the month of the last working day). Nothing is hard-coded: with the rule blank
 * the settlement keeps its built-in line (organisation setting exit.notice_recovery, gross ÷ 30, as before); with `none`
 * nothing is recovered.
 *
 * The monthly pay comes only through CompensationOutput — the approved compensation in force on the last working day —
 * broken up by the salary structure's own formulas (as the salary annexure and the letter checks work it out). Without an
 * approved breakup no line is added (the settlement's inputs say why).
 */
final class NoticeRecoverySettlementSource implements SettlementSource
{
    public const BASES = ['gross' => 'Gross pay', 'basic' => 'Basic', 'basic_da' => 'Basic + DA', 'ctc' => 'CTC (monthly)'];

    public const DIVISORS = ['fixed' => 'A fixed number of days', 'month_days' => 'The days of the month of the last working day'];

    public function __construct(
        private readonly CompensationOutput $compensation,
        private readonly StructureFormulas $formulas,
    ) {}

    public function linesFor(ExitCase $case, FinalSettlement $settlement): array
    {
        $rule = NoticeShortfall::rule($case);
        $notice = NoticeShortfall::for($case);
        if ($rule['rule'] !== 'policy' || $notice === null || $notice['shortfall_days'] <= 0) {
            return [];
        }
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->with('statutoryDetail')->find($case->employee_id);
        $lwd = $case->last_working_day;
        $monthly = $employee !== null ? $this->monthly($employee, $lwd, $rule['basis']) : null;
        $divisor = $rule['divisor'] === 'month_days' ? $lwd->daysInMonth : ($rule['days'] ?? null);
        if ($monthly === null || $monthly <= 0 || $divisor === null || $divisor <= 0) {
            return [];
        }
        $perDay = round($monthly / $divisor, 2);

        return [[
            'type' => 'deduction', 'code' => 'NOTICE_RECOVERY', 'name' => "Notice shortfall ({$notice['shortfall_days']} days)",
            'amount' => round($notice['shortfall_days'] * $perDay, 2),
            'basis' => ['rule' => 'exit policy', 'notice_days' => $notice['notice_days'], 'served_days' => $notice['served_days'], 'relaxation_days' => $notice['relaxation_days'],
                'shortfall_days' => $notice['shortfall_days'], 'basis' => self::BASES[$rule['basis']] ?? $rule['basis'], 'monthly' => round($monthly, 2),
                'divisor' => $divisor, 'per_day' => $perDay],
        ]];
    }

    public function settled(ExitCase $case, FinalSettlement $settlement): void {}

    /** A full month's pay of the chosen basis, from the approved compensation in force on the day. */
    private function monthly(Employee $employee, CarbonInterface $on, string $basis): ?float
    {
        try {
            $segment = $this->compensation->forPayroll($employee, $on, $on)->last();
            if ($segment === null) {
                return null;
            }
            if ($basis === 'ctc') {
                return round($segment->compensation->ctcAnnual / 12, 2);
            }
            if ($segment->components->isEmpty()) {
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
        if ($basis === 'gross') {
            return (float) ($preview['totals']['gross'] ?? 0);
        }
        $classes = $basis === 'basic_da' ? ['basic', 'da'] : ['basic'];
        $byCode = $segment->components->mapWithKeys(fn (CompensationComponentLine $line) => [$line->component->code => $line->component]);
        $sum = 0.0;
        foreach ($preview['rows'] as $row) {
            /** @var SalaryComponent|null $component */
            $component = $byCode[$row['code']] ?? null;
            if ($component !== null && $row['monthly'] !== null && $component->isEarning() && in_array($component->classification, $classes, true)) {
                $sum += (float) $row['monthly'];
            }
        }

        return round($sum, 2);
    }
}
