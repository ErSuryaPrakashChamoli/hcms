<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\SalaryComponent;

/**
 * Register of wages, overtime, advances, fines and deductions for damage and loss in the layout of Form IV of the
 * Wages (Central) Rules, 2026 (OSH Form XV; earlier Form B with Form C): finalized payroll of the month, one row per
 * employee — days, overtime hours, the rate and the wages earned as basic, DA, allowances and overtime, the deductions
 * (EPF, ESIC, income tax, advances, fines, damages, others), net pay and payment. A second sheet itemises every
 * component paid. Employer contributions are not wages paid and stay out (they are in the PF and ESI statements).
 */
final class WageRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $entries = $context->data->payrollFor($context);
        if ($entries->isEmpty()) {
            return [$this->sheet($context, ['sl' => 'Sl.', 'code' => 'Employee code', 'name' => 'Name'], [], ['message' => $this->noPayrollMessage($context), 'sheet' => 'Form IV', 'warnings' => $this->payrollGaps($context)])];
        }

        $overtimeCodes = SalaryComponent::query()->where('formula', 'like', '%overtime_%')->pluck('code')->all();
        $period = $context->period;

        $rows = $entries->map(function (PayrollEntry $entry) use ($context, $overtimeCodes, $period) {
            $employee = $entry->employee;
            $earnings = $entry->lines->whereIn('type', ['earning', 'reimbursement']);
            $overtime = $earnings->whereIn('code', $overtimeCodes);
            $allowances = $earnings->reject(fn (PayrollEntryLine $l) => in_array($l->classification, ['basic', 'da'], true) || in_array($l->code, $overtimeCodes, true));
            $full = fn ($lines) => round((float) $lines->sum(fn ($l) => (float) (($l->basis['full_month'] ?? null) ?? $l->amount)), 2);
            $deductions = $entry->lines->where('type', 'deduction');
            $other = $deductions->filter(fn ($l) => $l->classification === 'other_deduction');
            $advances = $other->filter(fn ($l) => preg_match('/adv|loan/i', $l->code.' '.$l->name));
            $fines = $other->filter(fn ($l) => preg_match('/fine/i', $l->code.' '.$l->name));
            $damages = $other->filter(fn ($l) => preg_match('/damage|loss/i', $l->code.' '.$l->name));
            $named = $advances->keys()->merge($fines->keys())->merge($damages->keys());
            $sum = fn ($lines) => round((float) $lines->sum('amount'), 2);

            return [
                'code' => $employee?->employee_code,
                'name' => $this->name($employee),
                'designation' => $employee ? $this->designation($context, $employee) : '',
                'department' => $employee ? $this->department($context, $employee) : '',
                'duration' => 'Monthly',
                'wage_period' => $this->date($period->start).' to '.$this->date($period->end),
                'days_worked' => (float) $entry->paid_days,
                'ot_hours' => round(((int) ($entry->inputs['attendance']['approved_overtime_minutes'] ?? 0)) / 60, 2),
                'rate_basic' => $full($earnings->where('classification', 'basic')),
                'rate_da' => $full($earnings->where('classification', 'da')),
                'rate_allowances' => $full($allowances),
                'basic' => $sum($earnings->where('classification', 'basic')),
                'da' => $sum($earnings->where('classification', 'da')),
                'allowances' => $sum($allowances),
                'overtime' => $sum($overtime),
                'total_earned' => round((float) $entry->total_earnings, 2),
                'epf' => $this->amount($entry, 'PF_EE'),
                'esic' => $this->amount($entry, 'ESI_EE'),
                'society' => 0.0,
                'income_tax' => $this->amount($entry, 'TDS'),
                'insurance' => 0.0,
                'advances' => $sum($advances),
                'fine' => $sum($fines),
                'damages' => $sum($damages),
                'others' => round($this->amount($entry, 'PT') + $this->amount($entry, 'LWF_EE') + $sum($other->except($named->all())), 2),
                'total_deductions' => round((float) $entry->total_deductions, 2),
                'net' => round((float) $entry->net_pay, 2),
                'paid_on' => $this->date($entry->run?->paid_at ?? $entry->run?->period?->payment_date),
                'paid_into' => $this->primaryAccount($context, $employee),
                'fine_reason' => '',
            ];
        })->values()->all();

        $columns = ['sl' => 'Sl. no.', 'code' => 'Employee code', 'name' => 'Name of the employee', 'designation' => 'Designation', 'department' => 'Department',
            'duration' => 'Duration of payment of wages', 'wage_period' => 'Wage period', 'days_worked' => 'Total days worked', 'ot_hours' => 'Total overtime hours',
            'rate_basic' => 'Rate — basic', 'rate_da' => 'Rate — DA', 'rate_allowances' => 'Rate — allowances',
            'basic' => 'Earned — basic', 'da' => 'Earned — DA', 'allowances' => 'Earned — allowances', 'overtime' => 'Earned — overtime', 'total_earned' => 'Total wages earned',
            'epf' => 'EPF', 'esic' => 'ESIC', 'society' => 'Society', 'income_tax' => 'Income tax', 'insurance' => 'Insurance', 'advances' => 'Advances',
            'fine' => 'Recovery of fine', 'damages' => 'Recovery for damage / loss', 'others' => 'Others (PT, LWF, other)', 'total_deductions' => 'Total deductions',
            'net' => 'Net payment', 'paid_on' => 'Date of payment', 'paid_into' => 'Bank account / transaction', 'fine_reason' => 'Act or omission for which fine imposed, with date'];
        $money = ['rate_basic', 'rate_da', 'rate_allowances', 'basic', 'da', 'allowances', 'overtime', 'total_earned', 'epf', 'esic', 'society', 'income_tax', 'insurance', 'advances', 'fine', 'damages', 'others', 'total_deductions', 'net'];

        return [
            $this->sheet($context, $columns, $this->numbered($rows), [
                'sheet' => 'Form IV', 'money' => $money,
                'warnings' => [...$this->payrollGaps($context), ...$this->attributionGaps($entries)],
                'rules' => $this->rules($context, $entries, ['PF_EE', 'ESI_EE', 'PT', 'LWF_EE', 'TDS']),
                'totals' => ReportSheet::sum($columns, $rows, [...$money, 'days_worked', 'ot_hours']),
                'notes' => ['Rate is the full-month amount before proration. Allowances include every other earning (HRA, conveyance, special, bonus, arrears…); the component sheet itemises them. Wage slips (Form V) are issued from Payroll → Payslips.'],
            ]),
            $this->components($context, $entries),
        ];
    }

    /** Every earning and deduction component paid, a column each. */
    private function components(ReportContext $context, $entries): ReportSheet
    {
        $lines = $entries->flatMap(fn (PayrollEntry $e) => $e->lines)->sortBy('sort_order');
        $earnings = $lines->whereIn('type', ['earning', 'reimbursement'])->pluck('name', 'code')->all();
        $deductions = $lines->where('type', 'deduction')->pluck('name', 'code')->all();

        $rows = $entries->map(function (PayrollEntry $entry) use ($earnings, $deductions) {
            $row = ['code' => $entry->employee?->employee_code, 'name' => $this->name($entry->employee), 'paid_days' => (float) $entry->paid_days, 'lop_days' => (float) $entry->lop_days];
            foreach (array_keys($earnings) as $code) {
                $row['e_'.$code] = $this->amount($entry, $code);
            }
            $row['gross'] = round((float) $entry->total_earnings, 2);
            foreach (array_keys($deductions) as $code) {
                $row['d_'.$code] = $this->amount($entry, $code);
            }

            return $row + ['net' => round((float) $entry->net_pay, 2)];
        })->values()->all();

        $columns = ['sl' => 'Sl.', 'code' => 'Employee code', 'name' => 'Name', 'paid_days' => 'Days paid', 'lop_days' => 'LOP days'];
        foreach ($earnings as $code => $name) {
            $columns['e_'.$code] = $name;
        }
        $columns['gross'] = 'Gross earnings';
        foreach ($deductions as $code => $name) {
            $columns['d_'.$code] = $name;
        }
        $columns['net'] = 'Net paid';
        $money = ['gross', 'net', ...array_map(fn ($c) => 'e_'.$c, array_keys($earnings)), ...array_map(fn ($c) => 'd_'.$c, array_keys($deductions))];

        return $this->sheet($context, $columns, $this->numbered($rows), ['title' => 'Wages by component', 'sheet' => 'Components', 'money' => $money, 'totals' => ReportSheet::sum($columns, $rows, [...$money, 'paid_days', 'lop_days'])]);
    }
}
