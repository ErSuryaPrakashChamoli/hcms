<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * Professional tax statement for one state over the state's return period (a month, half-year or year): the PT payroll
 * deducted from each employee whose PT state was that state, and the slab summary a PT return asks for (employees
 * by monthly tax amount). The governed PT return is generated through Compliance → Professional tax returns.
 */
final class ProfessionalTaxStatement extends Builder
{
    public function build(ReportContext $context): array
    {
        $state = (string) $context->state;
        $employees = [];
        $slabs = [];
        $months = $context->period->months();
        $payrollSeen = false;

        foreach ($months as $month) {
            $entries = $context->data->payrollFor($context, $month);
            $payrollSeen = $payrollSeen || $entries->isNotEmpty();
            foreach ($entries as $entry) {
                /** @var PayrollEntry $entry */
                $line = $entry->lines->first(fn ($l) => $l->code === 'PT' && ReportCatalogue::canonicalState($l->basis['state'] ?? null) === $state);
                if ($line === null) {
                    continue;
                }
                $key = (int) $entry->employee_id;
                $amount = round((float) $line->amount, 2);
                $employees[$key] ??= [
                    'code' => $entry->employee?->employee_code, 'name' => $this->name($entry->employee), 'gender' => $this->gender($entry->employee?->person),
                    'establishment' => (string) $context->data->establishment($entry->getAttribute('report_establishment_id'))?->name,
                    'months' => 0, 'gross' => 0.0, 'pt' => 0.0,
                ];
                $employees[$key]['months']++;
                $employees[$key]['gross'] = round($employees[$key]['gross'] + (float) ($line->basis['gross'] ?? $entry->gross), 2);
                $employees[$key]['pt'] = round($employees[$key]['pt'] + $amount, 2);
                $slab = $month->start->format('M Y').' · ₹'.number_format($amount, 2);
                $slabs[$slab] ??= ['month' => $month->start->format('M Y'), 'amount' => $amount, 'employees' => 0, 'total' => 0.0];
                $slabs[$slab]['employees']++;
                $slabs[$slab]['total'] = round($slabs[$slab]['total'] + $amount, 2);
            }
        }

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'gender' => 'Sex', 'establishment' => 'Establishment', 'months' => 'Months', 'gross' => 'Gross salary', 'pt' => 'Professional tax deducted'];
        $rows = array_values($employees);
        $message = $payrollSeen ? 'No professional tax was deducted for this state in the period.' : $this->noPayrollMessage($context->forPeriod($months[count($months) - 1]));
        $ruleNote = app(ComplianceRules::class)->resolve('PT', $context->period->end, $state) === null
            ? ["The statutory rule pack has no professional tax rule for {$context->stateName()}, so payroll does not deduct it there yet."] : [];
        $summaryColumns = ['month' => 'Month', 'amount' => 'Tax per employee (₹)', 'employees' => 'Employees', 'total' => 'Tax (₹)'];
        $summary = array_values($slabs);

        return [
            $this->sheet($context, $columns, $this->numbered($rows), ['money' => ['gross', 'pt'], 'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, ['gross', 'pt']), 'message' => $message, 'sheet' => 'PT deducted', 'notes' => $ruleNote,
                'warnings' => $this->payrollGaps($context), 'rules' => $this->rules($context, collect($months)->flatMap(fn ($m) => $context->data->payrollFor($context, $m)), ['PT'])]),
            $this->sheet($context, $summaryColumns, $summary, [
                'title' => 'Professional tax — slab summary', 'sheet' => 'Slab summary', 'money' => ['amount', 'total'],
                'totals' => $summary === [] ? null : ReportSheet::sum($summaryColumns, $summary, ['employees', 'total']),
                'message' => $message,
                'notes' => ['Employees are counted by the monthly tax payroll deducted (the slab of their salary under the state rule version used).'],
            ]),
        ];
    }
}
