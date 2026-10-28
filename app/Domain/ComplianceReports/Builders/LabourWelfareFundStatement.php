<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * Labour welfare fund statement for one state over its contribution period: the employee and employer contributions
 * payroll made for employees whose LWF state was that state, with the total payable to the welfare board.
 */
final class LabourWelfareFundStatement extends Builder
{
    public function build(ReportContext $context): array
    {
        $state = (string) $context->state;
        $rows = [];
        $payrollSeen = false;
        $months = $context->period->months();

        foreach ($months as $month) {
            $entries = $context->data->payrollFor($context, $month);
            $payrollSeen = $payrollSeen || $entries->isNotEmpty();
            foreach ($entries as $entry) {
                /** @var PayrollEntry $entry */
                $lines = $entry->lines->filter(fn ($l) => in_array($l->code, ['LWF_EE', 'LWF_ER'], true) && ReportCatalogue::canonicalState($l->basis['state'] ?? null) === $state);
                if ($lines->isEmpty()) {
                    continue;
                }
                $ee = round((float) $lines->where('code', 'LWF_EE')->sum('amount'), 2);
                $er = round((float) $lines->where('code', 'LWF_ER')->sum('amount'), 2);
                $rows[] = [
                    'month' => $month->start->format('M Y'),
                    'code' => $entry->employee?->employee_code,
                    'name' => $this->name($entry->employee),
                    'establishment' => (string) $context->data->establishment($entry->getAttribute('report_establishment_id'))?->name,
                    'gross' => round((float) $entry->gross, 2),
                    'ee' => $ee, 'er' => $er, 'total' => round($ee + $er, 2),
                ];
            }
        }

        $columns = ['sl' => 'Sl.', 'month' => 'Deducted in', 'code' => 'Code', 'name' => 'Name', 'establishment' => 'Establishment', 'gross' => 'Gross wages',
            'ee' => 'Employee contribution', 'er' => 'Employer contribution', 'total' => 'Total'];
        $message = $payrollSeen ? 'No labour welfare fund was deducted for this state in the period.' : $this->noPayrollMessage($context->forPeriod($months[count($months) - 1]));
        $ruleNote = app(ComplianceRules::class)->resolve('LWF', $context->period->end, $state) === null
            ? ["The statutory rule pack has no labour welfare fund rule for {$context->stateName()}, so payroll does not deduct it there yet."] : [];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => ['gross', 'ee', 'er', 'total'],
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, ['ee', 'er', 'total']),
            'message' => $message,
            'registration' => null,
            'notes' => $ruleNote,
            'warnings' => $this->payrollGaps($context),
            'rules' => $this->rules($context, collect($months)->flatMap(fn ($m) => $context->data->payrollFor($context, $m)), ['LWF_EE', 'LWF_ER']),
        ])];
    }
}
