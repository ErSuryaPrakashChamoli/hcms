<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * Register of deductions: loans and advances recovered, fines, damages and other recoveries made from wages in the
 * month — every deduction of finalized payroll that is not a statutory contribution or tax.
 */
final class DeductionRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $entries = $context->data->payrollFor($context);

        $rows = $entries->flatMap(fn (PayrollEntry $entry) => $entry->lines
            ->filter(fn ($line) => $line->type === 'deduction' && ! in_array($line->classification, self::STATUTORY_CLASSES, true) && (float) $line->amount != 0.0)
            ->map(fn ($line) => [
                'code' => $entry->employee?->employee_code,
                'name' => $this->name($entry->employee),
                'designation' => $entry->employee ? $this->designation($context, $entry->employee) : '',
                'wages' => round((float) $entry->total_earnings, 2),
                'recovery' => $line->name,
                'component' => $line->code,
                'amount' => round((float) $line->amount, 2),
                'remarks' => (string) ($line->basis['note'] ?? ''),
            ]))->values()->all();

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'designation' => 'Designation', 'wages' => 'Wages for the month',
            'recovery' => 'Nature of deduction (loan, advance, fine, damage…)', 'component' => 'Component', 'amount' => 'Amount recovered', 'remarks' => 'Remarks'];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => ['wages', 'amount'],
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, ['amount']),
            'message' => $entries->isEmpty() ? $this->noPayrollMessage($context) : 'No loans, advances, fines or other recoveries were deducted this month.',
            'warnings' => [...$this->payrollGaps($context), ...$this->attributionGaps($entries)],
            'notes' => ['Statutory deductions (PF, ESI, PT, LWF, TDS) are in the wage register and their own statements. The amount and date of each loan or advance given, and the act or omission behind a fine, are recorded with the approving order.'],
        ])];
    }
}
