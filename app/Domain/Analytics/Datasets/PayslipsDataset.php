<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Payroll\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;

class PayslipsDataset extends Dataset
{
    public function key(): string
    {
        return 'payslips';
    }

    public function label(): string
    {
        return 'Payslips (published and viewed by employees)';
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
        return [
            'number' => ['label' => 'Payslip number', 'type' => 'string', 'value' => fn ($p) => $p->number],
            'period' => ['label' => 'Period', 'type' => 'string', 'value' => fn ($p) => $p->entry?->run?->period?->key()],
            ...$this->employeeFields(fn ($p) => $p->employee),
            'net_pay' => ['label' => 'Net pay', 'type' => 'number', 'value' => fn ($p) => $p->entry ? (float) $p->entry->net_pay : null],
            'generated_on' => ['label' => 'Generated on', 'type' => 'date', 'value' => fn ($p) => $p->generated_at],
            'viewed_on' => ['label' => 'First viewed on', 'type' => 'date', 'value' => fn ($p) => $p->viewed_at],
            'is_viewed' => ['label' => 'Viewed by employee', 'type' => 'boolean', 'value' => fn ($p) => $p->viewed_at !== null],
            'count' => ['label' => 'Payslips (1 per row)', 'type' => 'number', 'value' => fn ($p) => 1],
        ];
    }

    /** HROne parity CA-10: rows come from payroll runs (a view's payroll refresh day applies). */
    public function payrollRunPath(): ?string
    {
        return 'entry.run';
    }

    public function query(): Builder
    {
        return Payslip::query()->with([...self::employeeWith('employee'), 'entry.run.period'])->orderByDesc('id');
    }
}
