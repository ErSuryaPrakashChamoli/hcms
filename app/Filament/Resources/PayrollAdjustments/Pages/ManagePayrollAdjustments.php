<?php

namespace App\Filament\Resources\PayrollAdjustments\Pages;

use App\Filament\Resources\PayrollAdjustments\PayrollAdjustmentResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManagePayrollAdjustments extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = PayrollAdjustmentResource::class;

    public static function registerKey(): string
    {
        return 'payroll_adjustments';
    }

    public static function registerReports(): array
    {
        return ['adjustments_last_month', 'arrears_this_fy', 'reimbursements_this_fy'];
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])];
    }
}
