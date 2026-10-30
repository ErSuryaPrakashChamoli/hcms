<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Payroll\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Builder;

class PayrollDataset extends Dataset
{
    public function key(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return 'Payroll entries (salary register, finalized and paid runs)';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function fields(): array
    {
        $bank = fn ($e) => $e->employee?->bankAccounts?->sortByDesc('is_primary')->first();

        return [
            'period' => ['label' => 'Period', 'type' => 'string', 'value' => fn ($e) => $e->run?->period?->key()],
            'period_start' => ['label' => 'Period start', 'type' => 'date', 'value' => fn ($e) => $e->run?->period?->start_date],
            'payment_date' => ['label' => 'Payment date', 'type' => 'date', 'value' => fn ($e) => $e->run?->period?->payment_date],
            ...$this->employeeFields(fn ($e) => $e->employee),
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($e) => $e->run?->company?->name],
            'days_in_period' => ['label' => 'Days in period', 'type' => 'number', 'value' => fn ($e) => (int) $e->days_in_period],
            'paid_days' => ['label' => 'Paid days', 'type' => 'number', 'value' => fn ($e) => (float) $e->paid_days],
            'lop_days' => ['label' => 'LOP days', 'type' => 'number', 'value' => fn ($e) => (float) $e->lop_days],
            'gross' => ['label' => 'Gross', 'type' => 'number', 'value' => fn ($e) => (float) $e->gross],
            'total_earnings' => ['label' => 'Total earnings', 'type' => 'number', 'value' => fn ($e) => (float) $e->total_earnings],
            'taxable_earnings' => ['label' => 'Taxable earnings', 'type' => 'number', 'value' => fn ($e) => (float) $e->taxable_earnings],
            'total_deductions' => ['label' => 'Deductions', 'type' => 'number', 'value' => fn ($e) => (float) $e->total_deductions],
            'net_pay' => ['label' => 'Net pay', 'type' => 'number', 'value' => fn ($e) => (float) $e->net_pay],
            'employer_cost' => ['label' => 'Employer cost', 'type' => 'number', 'value' => fn ($e) => (float) $e->employer_cost],
            'basic' => ['label' => 'Basic + DA', 'type' => 'number', 'value' => fn ($e) => round((float) $e->lines->whereIn('classification', ['basic', 'da'])->sum('amount'), 2)],
            'hra' => ['label' => 'HRA', 'type' => 'number', 'value' => fn ($e) => round((float) $e->lines->where('classification', 'hra')->sum('amount'), 2)],
            'pf_employee' => ['label' => 'PF (employee)', 'type' => 'number', 'value' => fn ($e) => $e->amount('PF_EE')],
            'pf_employer' => ['label' => 'PF (employer)', 'type' => 'number', 'value' => fn ($e) => $e->amount('PF_ER')],
            'esi_employee' => ['label' => 'ESI (employee)', 'type' => 'number', 'value' => fn ($e) => $e->amount('ESI_EE')],
            'esi_employer' => ['label' => 'ESI (employer)', 'type' => 'number', 'value' => fn ($e) => $e->amount('ESI_ER')],
            'professional_tax' => ['label' => 'Professional tax', 'type' => 'number', 'value' => fn ($e) => $e->amount('PT')],
            'lwf_employee' => ['label' => 'LWF (employee)', 'type' => 'number', 'value' => fn ($e) => $e->amount('LWF_EE')],
            'lwf_employer' => ['label' => 'LWF (employer)', 'type' => 'number', 'value' => fn ($e) => $e->amount('LWF_ER')],
            'tds' => ['label' => 'TDS', 'type' => 'number', 'value' => fn ($e) => $e->amount('TDS')],
            // Identifiers and bank details are always masked here; full values stay in the statutory returns and bank advice.
            'uan' => ['label' => 'UAN (masked)', 'type' => 'string', 'permission' => 'employee.sensitive.view', 'value' => fn ($e) => EmployeeStatutoryDetail::mask($e->employee?->statutoryDetail?->uan)],
            'esic_number' => ['label' => 'ESI IP number (masked)', 'type' => 'string', 'permission' => 'employee.sensitive.view', 'value' => fn ($e) => EmployeeStatutoryDetail::mask($e->employee?->statutoryDetail?->esic_number)],
            'bank_name' => ['label' => 'Bank', 'type' => 'string', 'permission' => 'employee.sensitive.view', 'value' => fn ($e) => $bank($e)?->bank_name],
            'ifsc' => ['label' => 'IFSC', 'type' => 'string', 'permission' => 'employee.sensitive.view', 'value' => fn ($e) => $bank($e)?->ifsc],
            'account_number' => ['label' => 'Account (masked)', 'type' => 'string', 'permission' => 'employee.sensitive.view', 'value' => fn ($e) => $bank($e)?->maskedAccountNumber()],
            'status' => ['label' => 'Entry status', 'type' => 'string', 'value' => fn ($e) => $e->status],
            'run_status' => ['label' => 'Run status', 'type' => 'string', 'value' => fn ($e) => $e->run?->status],
        ];
    }

    /** HROne parity CA-10: rows come from payroll runs (a view's payroll refresh day applies). */
    public function payrollRunPath(): ?string
    {
        return 'run';
    }

    public function query(): Builder
    {
        return PayrollEntry::query()->with(['run.period', 'run.company', ...self::employeeWith('employee'), 'employee.statutoryDetail', 'employee.bankAccounts', 'lines'])
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->orderByDesc('id');
    }
}
