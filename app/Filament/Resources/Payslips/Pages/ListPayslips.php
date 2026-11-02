<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\Pages\Concerns\ConfirmsPayPassword;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollRegisters;

class ListPayslips extends PeopleListRecords
{
    use ConfirmsPayPassword;
    use HasRegisterTools;

    protected static string $resource = PayslipResource::class;

    protected static function payPasswordScope(): string
    {
        return 'payslips';
    }

    protected function payPasswordReturnUrl(): string
    {
        return PayslipResource::getUrl('index');
    }

    public static function registerKey(): string
    {
        return 'payslips';
    }

    public static function registerReports(): array
    {
        return ['salary_register_last_month', 'payslips_not_viewed'];
    }

    /** HROne parity PO-1: views and report for payroll (an employee's own payslips look as before). */
    protected function getHeaderActions(): array
    {
        return PayrollRegisters::hrSide('payroll.view') ? $this->registerToolActions() : [];
    }
}
