<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PayrollActions;
use App\Filament\Support\PayrollRegisters;

class ListPayrollRuns extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = PayrollRunResource::class;

    public static function registerKey(): string
    {
        return 'payroll_runs';
    }

    public static function registerReports(): array
    {
        return ['salary_register_last_month', 'salary_register_this_month', 'component_register_last_month', 'payroll_cost_by_period'];
    }

    protected function getHeaderActions(): array
    {
        // HROne parity PO-1: views, the payroll reports and the payroll actions next to the run action.
        return [...$this->registerToolActions(), PayrollActions::openRun(), PayrollRegisters::actionsMenu()];
    }
}
