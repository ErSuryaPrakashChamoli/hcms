<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * ESI contribution statement and challan working for an ESIC-registered establishment: per insured person, the days
 * paid, wages and the employee and employer contributions payroll deducted, with the challan total. Employees who
 * left in the month are marked so the exit can be recorded on the ESIC portal. The monthly contribution file is
 * produced, approved and exported through Compliance → ESI returns.
 */
final class EsiChallan extends Builder
{
    public function build(ReportContext $context): array
    {
        $all = $context->data->payrollFor($context);
        $entries = $all->filter(fn (PayrollEntry $e) => $e->lines->contains('code', 'ESI_EE') || $e->lines->contains('code', 'ESI_ER'))->values();
        $columns = ['sl' => 'Sl.', 'ip' => 'IP number', 'code' => 'Code', 'name' => 'Name', 'days' => 'Days paid', 'wages' => 'Wages', 'ee' => 'Employee contribution',
            'er' => 'Employer contribution', 'total' => 'Total', 'remarks' => 'Remarks'];
        $money = ['wages', 'ee', 'er', 'total'];

        $rows = $entries->map(function (PayrollEntry $entry) use ($context) {
            $line = $entry->lines->firstWhere('code', 'ESI_EE') ?? $entry->lines->firstWhere('code', 'ESI_ER');
            $ee = $this->amount($entry, 'ESI_EE');
            $er = $this->amount($entry, 'ESI_ER');
            $exit = $entry->employee?->exit_date;

            return [
                'ip' => $context->identifier($entry->employee?->statutoryDetail?->esic_number),
                'code' => $entry->employee?->employee_code,
                'name' => $this->name($entry->employee),
                'days' => (float) $entry->paid_days,
                'wages' => round((float) ($line->basis['wages'] ?? $entry->gross), 2),
                'ee' => $ee, 'er' => $er, 'total' => round($ee + $er, 2),
                'remarks' => $exit !== null && $context->period->contains($exit) ? 'Left service on '.$this->date($exit) : (trim((string) $entry->employee?->statutoryDetail?->esic_number) === '' ? 'IP number missing — register on the ESIC portal' : ''),
            ];
        })->values()->all();

        $message = $all->isEmpty() ? $this->noPayrollMessage($context) : 'No employee was covered by ESI this month (wages above the ESI ceiling or ESI not applicable).';

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => $money,
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, [...$money, 'days']),
            'message' => $message,
            'registration' => 'esic_employer_code',
            'warnings' => [...$this->payrollGaps($context), ...$this->attributionGaps($entries), ...$this->registrationGaps($context, 'esic_employer_code'), ...$this->identifierGaps($entries, 'esic_number', 'ESI IP number')],
            'rules' => $this->rules($context, $entries, ['ESI_EE', 'ESI_ER']),
            'notes' => ['Coverage follows the wage ceiling of the ESI rule version payroll used; an employee covered at the start of a contribution period stays covered to its end — check such cases on the ESIC portal.'],
        ])];
    }
}
