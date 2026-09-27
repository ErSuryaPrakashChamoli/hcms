<?php

namespace App\Domain\Analytics\Datasets;

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
        return 'Payroll entries (finalized and paid runs)';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function fields(): array
    {
        return [
            'period' => ['label' => 'Period', 'type' => 'string', 'value' => fn ($e) => $e->run?->period?->start_date?->format('Y-m')],
            'company' => ['label' => 'Company', 'type' => 'string', 'value' => fn ($e) => $e->run?->company?->name],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($e) => $e->employee?->employee_code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($e) => $e->employee?->person?->full_name],
            'department' => ['label' => 'Department', 'type' => 'string', 'value' => fn ($e) => $e->employee?->currentPosition?->department?->name],
            'paid_days' => ['label' => 'Paid days', 'type' => 'number', 'value' => fn ($e) => (float) $e->paid_days],
            'lop_days' => ['label' => 'LOP days', 'type' => 'number', 'value' => fn ($e) => (float) $e->lop_days],
            'gross' => ['label' => 'Gross', 'type' => 'number', 'value' => fn ($e) => (float) $e->gross],
            'total_deductions' => ['label' => 'Deductions', 'type' => 'number', 'value' => fn ($e) => (float) $e->total_deductions],
            'net_pay' => ['label' => 'Net pay', 'type' => 'number', 'value' => fn ($e) => (float) $e->net_pay],
            'employer_cost' => ['label' => 'Employer cost', 'type' => 'number', 'value' => fn ($e) => (float) $e->employer_cost],
            'pf_employee' => ['label' => 'PF (employee)', 'type' => 'number', 'value' => fn ($e) => $e->amount('PF_EE')],
            'tds' => ['label' => 'TDS', 'type' => 'number', 'value' => fn ($e) => $e->amount('TDS')],
            'status' => ['label' => 'Entry status', 'type' => 'string', 'value' => fn ($e) => $e->status],
        ];
    }

    public function query(): Builder
    {
        return PayrollEntry::query()->with(['run.period', 'run.company', 'employee.person', 'employee.currentPosition.department', 'lines'])
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->orderByDesc('id');
    }
}
