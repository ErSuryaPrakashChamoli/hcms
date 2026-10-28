<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\SalaryComponent;

/**
 * Register of overtime: approved overtime by day from attendance, and the overtime wages paid in finalized payroll
 * (earnings whose formula uses overtime_hours or overtime_minutes).
 */
final class OvertimeRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment)->keyBy('id');
        $attendance = $context->data->attendance($employees->keys()->all(), $period->start, $period->end);
        $overtimeCodes = SalaryComponent::query()->where('formula', 'like', '%overtime_%')->pluck('code')->all();
        $paid = $context->data->payrollFor($context)->keyBy('employee_id');

        $rows = [];
        foreach ($attendance as $employeeId => $days) {
            $worked = collect($days)->filter(fn ($record) => (int) $record->overtime_approved_minutes > 0);
            if ($worked->isEmpty()) {
                continue;
            }
            /** @var Employee $employee */
            $employee = $employees->get($employeeId);
            /** @var PayrollEntry|null $entry */
            $entry = $paid->get($employeeId);
            $minutes = (int) $worked->sum('overtime_approved_minutes');

            $rows[] = [
                'code' => $employee->employee_code,
                'name' => $this->name($employee),
                'gender' => $this->gender($employee->person),
                'designation' => $this->designation($context, $employee),
                'dates' => $worked->map(fn ($record) => $record->date->format('d').' ('.round($record->overtime_approved_minutes / 60, 2).'h)')->implode(', '),
                'hours' => round($minutes / 60, 2),
                'normal_rate' => $entry ? $this->rate($entry) : null,
                'ot_wages' => $entry ? round((float) $entry->lines->whereIn('code', $overtimeCodes)->sum('amount'), 2) : null,
                'paid_on' => $entry ? $this->date($entry->run?->paid_at ?? $entry->run?->period?->payment_date) : '',
            ];
        }

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'gender' => 'Sex', 'designation' => 'Designation', 'dates' => 'Dates (approved hours)',
            'hours' => 'Total overtime hours', 'normal_rate' => 'Normal monthly rate', 'ot_wages' => 'Overtime wages paid', 'paid_on' => 'Date paid'];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => ['normal_rate', 'ot_wages'],
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, ['hours', 'ot_wages']),
            'message' => 'No approved overtime in the period.',
            'notes' => $overtimeCodes === [] ? ['No salary component uses overtime_hours or overtime_minutes, so no overtime wages were paid through payroll.'] : [],
        ])];
    }
}
