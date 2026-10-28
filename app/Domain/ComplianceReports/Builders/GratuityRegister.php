<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\GratuityEntitlements;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Payroll\Models\PayrollEntry;
use Carbon\CarbonImmutable;

/**
 * Gratuity register for a year: per employee the service at the year end (or exit), the last drawn wages (basic + DA)
 * from finalized payroll, whether the qualifying service is met and the gratuity on service so far under the GRATUITY
 * rule version in force at the year end (or, provisionally, a proposed version prepared for review), plus gratuity paid
 * in full and final settlements and the nominee on file.
 *
 * Each row is GratuityEntitlements::accrued(), the calculator full and final settlements use at exit: the rule's
 * parameters decide it (rate, minimum_years, fixed_term_minimum_years, ceiling), a part year counts only in excess of
 * six months, and the status says whether the result can be relied on: Qualifies / Not yet qualified (verified rule),
 * Pending determination, Insufficient data, Legally unverified. Coverage is decided per establishment by the plan.
 */
final class GratuityRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        ['active' => $active, 'proposed' => $proposed] = app(ComplianceRules::class)->resolveWithProposed('GRATUITY', $period->end);
        // As GratuityEntitlements chooses: a proposed version only for a later legal position than the one in force.
        $rule = $proposed !== null && ($active === null || $proposed->effective_from->gt($active->effective_from)) ? $proposed : $active;
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment);
        $lastWages = $this->lastWages($context);
        $settled = FinalSettlement::query()->with('lines')->whereIn('employee_id', $employees->modelKeys())
            ->whereNotNull('paid_at')->whereBetween('paid_at', [$period->start->startOfDay(), $period->end->endOfDay()])->get()->groupBy('employee_id');

        $entitlements = app(GratuityEntitlements::class);
        $rows = $employees->map(function (Employee $employee) use ($context, $lastWages, $settled, $entitlements) {
            $until = $employee->exit_date !== null && $employee->exit_date->lt($context->period->end) ? CarbonImmutable::parse($employee->exit_date) : $context->period->end;
            [$wages, $remuneration] = $lastWages[(int) $employee->getKey()] ?? [null, null];
            $type = (string) $context->data->positionOn($employee, $until)?->employmentType?->name;
            $gratuity = $entitlements->accrued($employee, $until, $wages, $type, $remuneration);
            $nominee = $employee->person?->familyMembers?->where('is_nominee', true)->pluck('name')->implode(', ');
            $paid = $settled->get($employee->getKey(), collect())->flatMap(fn ($s) => $s->lines)->filter(fn ($l) => stripos((string) $l->code.' '.$l->name, 'gratuity') !== false)->sum('amount');

            return [
                'code' => $employee->employee_code,
                'name' => $this->name($employee),
                'joined' => $this->date($employee->joining_date),
                'left' => $this->date($employee->exit_date),
                'employment_type' => $type,
                'service' => $gratuity->serviceText,
                'years' => $gratuity->yearsCounted,
                'wages' => $wages,
                'status' => $gratuity->label(),
                'reason' => $gratuity->reason,
                'accrued' => $gratuity->amount,
                'paid' => $paid > 0 ? round((float) $paid, 2) : null,
                'nominee' => $nominee ?: 'Not recorded (Form III)',
            ];
        })->values()->all();

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'joined' => 'Date of joining', 'left' => 'Date of exit', 'employment_type' => 'Type of employment',
            'service' => 'Service', 'years' => 'Years counted', 'wages' => 'Last drawn wages (basic + DA)', 'status' => 'Status', 'reason' => 'Reason',
            'accrued' => 'Gratuity on service so far', 'paid' => 'Gratuity paid in settlement', 'nominee' => 'Nominee'];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => ['wages', 'accrued', 'paid'],
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, ['accrued', 'paid']),
            'message' => 'No employees at this establishment in the period.',
            'warnings' => [...$this->payrollGaps($context), ...$this->attributionGaps($employees)],
            'rules' => $rule === null ? [] : [$rule->getKey() => ['id' => $rule->getKey(), 'code' => 'GRATUITY', 'state' => null, 'version' => $rule->version, 'verification_status' => $rule->isProposed() ? 'proposed' : $rule->verification_status, 'status_at_calculation' => null, 'label' => $rule->label().' ('.($rule->isProposed() ? 'proposed, not in force' : $rule->verification_status).')']],
            'notes' => [
                $rule === null ? 'No GRATUITY rule version applies on the period end, so gratuity is not computed.'
                    : ($rule->isProposed() ? "Computed provisionally with {$rule->label()}, prepared for review and not in force; ".($active ? "the version in force is {$active->label()}." : 'no version is in force.') : "Computed with {$rule->label()} ({$rule->verification_status})."),
                'Fixed-term employees are recognised by an employment type whose name contains "fixed". Wages are basic + DA as classified in payroll; check whether the Code\'s 50% wage rule adds other components.',
            ],
        ])];
    }

    /** @return array<int, array{0: float, 1: float}> employee id => full-month [basic + DA, remuneration less bonus] in the latest finalized payroll of the period */
    private function lastWages(ReportContext $context): array
    {
        $out = [];
        foreach ($context->period->months() as $month) {
            foreach ($context->data->payrollFor($context, $month) as $entry) {
                /** @var PayrollEntry $entry */
                $wage = $this->rate($entry, ['basic', 'da']);
                if ($wage > 0) {
                    $out[(int) $entry->employee_id] = [$wage, round($this->rate($entry) - $this->rate($entry, ['bonus']), 2)];
                }
            }
        }

        return $out;
    }
}
