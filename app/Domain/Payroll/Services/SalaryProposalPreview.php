<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Compliance\Services\PfOptions;
use App\Domain\Compliance\Services\StatutoryContexts;
use App\Domain\Compliance\Services\StatutoryEngine;
use App\Domain\Compliance\Services\VerifiedThresholds;
use App\Domain\Compliance\Support\StatutoryContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Enterprise\Services\CountryPacks;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Support\FormulaVariables;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * HROne parity PO-2 (matrix 25 §25.2.2 #12, #22, #23): what a salary proposal gives the employee, before anyone approves
 * it — read-only, nothing stored, no payroll run touched.
 *
 * - Breakup: StructureFormulas::preview over the proposal's structure version with the employee's CTC, fixed amounts and
 *   inputs, attributes and PF options (wage basis), for a full month of the effective date — the structure preview, for
 *   this employee.
 * - Statutory deductions estimate ((D) contributions, (E) recurring deductions, net take home excluding TDS): the
 *   statutory engine on an in-memory computation of that month (StatutoryEngine::apply; never saved). Only schemes whose
 *   rule version is VERIFIED, intact and free of an open notice (VerifiedThresholds) are shown; for the others the estimate
 *   says "No verified rule" instead of a number. TDS is not estimated (HROne's take home excludes it too).
 * - Salary insights: the structure preview's advisory wage-share guardrail, and the Code on Wages share and minimum wage
 *   only from verified WAGES / MIN_WAGE rule versions (else "No verified rule", as the salary letter checks say).
 * - Payment mode: how the employee is paid on the effective date (read-only; changed on Payroll → Payment modes).
 */
final class SalaryProposalPreview
{
    /** Statute => [label, rule code, the parameter VerifiedThresholds tests (null = the rule version itself)]. */
    public const SCHEMES = [
        'EPF' => ['Provident fund', 'EPF', 'employee_rate'],
        'ESI' => ['Employees\' state insurance', 'ESI', 'employee_rate'],
        'PT' => ['Professional tax', 'PT', null],
        'LWF' => ['Labour welfare fund', 'LWF', null],
    ];

    /** The engine's line codes, by the scheme they come from. */
    private const LINES = ['PF_EE' => 'EPF', 'PF_ER' => 'EPF', 'PF_ADMIN' => 'EPF', 'VPF' => 'EPF', 'ESI_EE' => 'ESI', 'ESI_ER' => 'ESI', 'PT' => 'PT', 'LWF_EE' => 'LWF', 'LWF_ER' => 'LWF'];

    public function __construct(
        private readonly StructureFormulas $formulas,
        private readonly StatutoryEngine $statutory,
        private readonly StatutoryContexts $contexts,
        private readonly VerifiedThresholds $thresholds,
        private readonly PayCycle $cycle,
        private readonly PaymentModes $modes,
        private readonly PfOptions $pfOptions,
        private readonly EstablishmentAssignments $establishments,
    ) {}

    /**
     * @return array{on: Carbon, inputs: array<string, mixed>, lines: list<array<string, mixed>>, preview: array<string, mixed>, estimate: array<string, mixed>, checks: list<array{check: string, state: string, text: string}>, pay_mode: array{label: string, set: bool}}
     */
    public function for(Employee $employee, CompensationSegment $segment): array
    {
        $employee->loadMissing(['statutoryDetail', 'person']);
        $on = $segment->from->copy()->startOfDay();
        $period = $this->period($employee, $on);
        $context = $this->contexts->for($employee, $period);
        $lines = self::lines($segment);
        $inputs = $this->inputs($employee, $segment, $context);
        $preview = $this->formulas->preview($lines, $segment->parameters, $inputs);

        return [
            'on' => $on,
            'inputs' => $inputs,
            'lines' => $lines,
            'preview' => $preview,
            'estimate' => $this->estimate($employee, $segment, $period, $context, $preview),
            'checks' => $this->checks($employee, $segment, $preview, $on),
            'pay_mode' => $this->payMode($employee, $on),
        ];
    }

    /**
     * The preview's inputs, as the salary annexure and the readiness panel pass them, with the employee's PF wage basis.
     *
     * @return array<string, mixed>
     */
    public function inputs(Employee $employee, CompensationSegment $segment, ?StatutoryContext $context = null): array
    {
        $on = $segment->from->copy()->startOfDay();
        $context ??= $this->contexts->for($employee, $this->period($employee, $on));

        return [
            'ctc_annual' => $segment->compensation->ctcAnnual, 'variable_target_annual' => $segment->compensation->variableTargetAnnual,
            'values' => $segment->compensation->componentValues, 'ctc_mode' => $segment->ctcMode,
            'attributes' => FormulaVariables::employeeAttributes($employee, $on), 'days' => 30,
            'pf_applies' => $employee->statutoryDetail?->pf_applicable ?? true,
            // HROne parity PY-15: the employee's own PF wage basis, else the establishment's (as payroll decides it).
            'pf_restrict_to_ceiling' => match ($this->pfOptions->for((int) $employee->id, $on)?->wage_basis) {
                'ceiling' => true, 'actual' => false, default => $context->restrictPfToCeiling(),
            },
            'on' => $on,
        ];
    }

    /** @return list<array{component: SalaryComponent, formula_override: ?string, sort_order: int}> */
    public static function lines(CompensationSegment $segment): array
    {
        return $segment->components->map(fn (CompensationComponentLine $line) => ['component' => $line->component, 'formula_override' => $line->formulaOverride, 'sort_order' => $line->sortOrder])->values()->all();
    }

    /**
     * The statutory deductions and contributions of the month, from verified rules only.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function estimate(Employee $employee, CompensationSegment $segment, PayrollPeriod $period, StatutoryContext $context, array $preview): array
    {
        $on = $period->end_date->copy()->startOfDay();
        $gross = (float) ($preview['totals']['gross'] ?? 0);
        $otherDeductions = (float) ($preview['totals']['deductions'] ?? 0);
        $out = ['month' => Carbon::create((int) $period->year, (int) $period->month, 1)->format('M Y'), 'schemes' => [], 'employee' => [], 'employer' => [],
            'employee_total' => 0.0, 'employer_total' => 0.0, 'gross' => $gross, 'other_deductions' => $otherDeductions, 'take_home' => null, 'complete' => false, 'note' => null];

        if (($preview['errors'] ?? []) !== []) {
            $out['note'] = 'The breakup has errors, so no deductions are estimated.';

            return $out;
        }
        if (app(CountryPacks::class)->statutoryEngine($context->jurisdiction()) !== 'india') {
            $out['note'] = 'The estimate covers India\'s PF, ESI, professional tax and labour welfare fund; this employee is paid under '.$context->jurisdiction().' rules.';

            return $out;
        }

        $detail = $employee->statutoryDetail;
        $applies = [
            'EPF' => $context->applies('EPF') && ($detail?->pf_applicable ?? true),
            'ESI' => $context->applies('ESI') && ($detail?->esic_applicable ?? true),
            'PT' => $context->applies('PT') && ($detail?->pt_applicable ?? true),
            'LWF' => $context->applies('LWF') && $context->lwfState !== null,
        ];
        $states = ['PT' => $context->ptState, 'LWF' => $context->lwfState];
        $verified = [];
        foreach (self::SCHEMES as $scheme => [$label, $code, $parameter]) {
            $state = $states[$scheme] ?? null;
            if (! $applies[$scheme]) {
                $out['schemes'][$scheme] = ['label' => $label, 'state' => 'not_applicable', 'rule' => null, 'text' => 'Does not apply to this employee.'];

                continue;
            }
            if (in_array($scheme, ['PT', 'LWF'], true) && $state === null) {
                $out['schemes'][$scheme] = ['label' => $label, 'state' => 'no_rule', 'rule' => null, 'text' => 'No state is known for the employee\'s establishment: not estimated.'];

                continue;
            }
            $rule = $this->thresholds->rule($code, $parameter, $on, $state);
            if ($rule === null) {
                $out['schemes'][$scheme] = ['label' => $label, 'state' => 'no_rule', 'rule' => null, 'text' => 'No verified rule'.($state ? ' for '.$state : '').': not estimated.'];

                continue;
            }
            $verified[$scheme] = (int) $rule->id;
            $out['schemes'][$scheme] = ['label' => $label, 'state' => 'estimated', 'rule' => $rule->label(), 'text' => 'Estimated from '.$rule->label().'.'];
            if ($scheme === 'LWF' && ! in_array((int) $period->month, array_map('intval', (array) $rule->param('months', [])), true)) {
                $out['schemes'][$scheme]['state'] = 'not_due';
                $out['schemes'][$scheme]['text'] = 'Deducted only in '.collect((array) $rule->param('months', []))->map(fn ($m) => Carbon::create(2000, (int) $m, 1)->format('M'))->implode(', ').' ('.$rule->label().').';
            }
        }

        if ($verified !== []) {
            // The engine on an in-memory month: the breakup's lines, then the statutory blocks. Nothing is saved.
            $computation = new PayrollComputation($employee, $period, $segment);
            $byCode = $segment->components->mapWithKeys(fn (CompensationComponentLine $line) => [$line->component->code => $line->component]);
            foreach ($preview['rows'] as $row) {
                $component = $byCode[$row['code']] ?? null;
                if ($component !== null && $row['monthly'] !== null && (float) $row['monthly'] > 0 && StructureFormulas::calculated($component)) {
                    $computation->fromComponent($component, (float) $row['monthly']);
                }
            }
            try {
                $this->statutory->apply($computation);
            } catch (Throwable $e) {
                report($e);
                $out['note'] = 'The statutory estimate could not be worked out for this breakup.';

                return $out;
            }
            foreach ($computation->lines as $line) {
                $scheme = self::LINES[$line['code']] ?? null;
                if ($scheme === null || ! isset($verified[$scheme]) || (float) $line['amount'] <= 0) {
                    continue;
                }
                // Only lines worked out from the verified rule version (VPF is the employee's own choice under that rule).
                $ruleId = $line['basis']['rule_id'] ?? null;
                if ($line['code'] !== 'VPF' && (int) $ruleId !== $verified[$scheme]) {
                    continue;
                }
                $entry = ['code' => $line['code'], 'name' => $line['name'], 'scheme' => $scheme, 'amount' => round((float) $line['amount'], 2)];
                if ($line['type'] === 'deduction') {
                    $out['employee'][] = $entry;
                } elseif ($line['type'] === 'employer_contribution') {
                    $out['employer'][] = $entry;
                }
            }
        }

        $out['employee_total'] = round(array_sum(array_column($out['employee'], 'amount')), 2);
        $out['employer_total'] = round(array_sum(array_column($out['employer'], 'amount')), 2);
        $out['complete'] = collect($out['schemes'])->every(fn (array $s) => in_array($s['state'], ['estimated', 'not_applicable', 'not_due'], true));
        $out['take_home'] = round($gross - $otherDeductions - $out['employee_total'], 2);

        return $out;
    }

    /**
     * HROne's salary insights for the proposal: the advisory guardrail of the structure preview, then the statutory checks
     * that need verified rule data.
     *
     * @param  array<string, mixed>  $preview
     * @return list<array{check: string, state: string, text: string}>
     */
    private function checks(Employee $employee, CompensationSegment $segment, array $preview, CarbonInterface $on): array
    {
        if (($preview['errors'] ?? []) !== []) {
            return [['check' => 'salary', 'state' => 'not_run', 'text' => 'The breakup has errors: the salary checks did not run.']];
        }
        $gross = (float) ($preview['totals']['gross'] ?? 0);
        $share = $preview['totals']['wage_share'] ?? null;
        $guardrail = config('peopleos.payroll.guardrails.wage_share');
        $out = [];
        if ($share !== null && is_array($guardrail)) {
            $out[] = (float) $share < (float) $guardrail['min_percent']
                ? ['check' => 'guardrail', 'state' => 'attention', 'text' => "Exclusions are greater than wages: basic and DA are {$share}% of gross pay, below the advisory {$guardrail['min_percent']}% guardrail of the structure preview. You may want to review the salary structure breakup."]
                : ['check' => 'guardrail', 'state' => 'ok', 'text' => "Wages are greater than exclusions: basic and DA are {$share}% of gross pay (advisory {$guardrail['min_percent']}% guardrail)."];
        }

        $byCode = $segment->components->mapWithKeys(fn (CompensationComponentLine $line) => [$line->component->code => $line->component]);
        $classes = (array) config('peopleos.payroll.guardrails.wage_share.classifications', ['basic', 'da']);
        $wages = 0.0;
        foreach ($preview['rows'] as $row) {
            $component = $byCode[$row['code']] ?? null;
            if ($component !== null && $row['monthly'] !== null && $component->isEarning() && in_array($component->classification, $classes, true)) {
                $wages += (float) $row['monthly'];
            }
        }
        $wages = round($wages, 2);

        $verifiedShare = $this->thresholds->value('WAGES', 'min_wage_share', $on);
        if ($verifiedShare === null) {
            $out[] = ['check' => 'wage_share', 'state' => 'no_rule', 'text' => 'No verified wage rule: the Code on Wages check (exclusions against wages) does not run.'];
        } elseif ($gross > 0) {
            $percent = round($wages / $gross * 100, 1);
            $out[] = $percent < $verifiedShare['value']
                ? ['check' => 'wage_share', 'state' => 'attention', 'text' => "Not compliant as per the Code on Wages: basic and DA are {$percent}% of gross pay, below the {$verifiedShare['value']}% of the verified wage rule ({$verifiedShare['rule']})."]
                : ['check' => 'wage_share', 'state' => 'ok', 'text' => "Compliant as per the Code on Wages: basic and DA are {$percent}% of gross pay ({$verifiedShare['rule']})."];
        }

        $state = $this->establishments->resolve($employee, $on)['establishment']?->state;
        $state = filled($state) ? strtoupper((string) $state) : null;
        $minimum = $state !== null ? $this->thresholds->value('MIN_WAGE', 'monthly_minimum', $on, $state) : null;
        if ($minimum === null) {
            $out[] = ['check' => 'minimum_wage', 'state' => 'no_rule', 'text' => 'No verified minimum wage rule'.($state ? ' for '.$state : ' (no state for the employee\'s establishment)').': the minimum wage check does not run.'];
        } else {
            $out[] = $wages < $minimum['value']
                ? ['check' => 'minimum_wage', 'state' => 'attention', 'text' => "Wages are less than the defined minimum wage of {$state} ({$minimum['rule']})."]
                : ['check' => 'minimum_wage', 'state' => 'ok', 'text' => "Wages are at or above the minimum wage of {$state} ({$minimum['rule']})."];
        }

        return $out;
    }

    /** @return array{label: string, set: bool} */
    private function payMode(Employee $employee, CarbonInterface $on): array
    {
        $mode = $this->modes->for((int) $employee->id, $on);
        if ($mode === null) {
            return ['label' => EmployeePaymentMode::MODES['online_transfer'].' (the default)', 'set' => false];
        }
        $account = $mode->salaryAccount?->label();

        return ['label' => $mode->summary().($account ? ' · from '.$account : '').' (from '.$mode->effective_from->format('j M Y').')', 'set' => true];
    }

    /** The employee's payroll month holding the date: its period when one exists, else an unsaved stand-in (never created here). */
    private function period(Employee $employee, CarbonInterface $on): PayrollPeriod
    {
        $companyId = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->effectiveOn($on)
            ->orderByDesc('effective_from')->value('company_id')
            ?? EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->orderByDesc('effective_from')->value('company_id')
            ?? Company::query()->orderBy('id')->value('id');
        [$year, $month] = $this->cycle->monthContaining((int) $companyId, Carbon::parse($on));
        $existing = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->first();
        if ($existing !== null) {
            return $existing;
        }
        try {
            [$start, $end] = $this->cycle->bounds((int) $companyId, $year, $month);
        } catch (Throwable) {
            [$start, $end] = [Carbon::create($year, $month, 1), Carbon::create($year, $month, 1)->endOfMonth()];
        }

        return new PayrollPeriod(['company_id' => $companyId, 'year' => $year, 'month' => $month, 'start_date' => Carbon::parse($start)->toDateString(), 'end_date' => Carbon::parse($end)->toDateString(), 'status' => 'open']);
    }
}
