<?php

namespace App\Filament\Resources\CompensationChanges\Pages;

use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Filament\Support\PayrollRegisters;

class ManageCompensationChanges extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = CompensationChangeResource::class;

    public static function registerKey(): string
    {
        return 'compensation_changes';
    }

    /** HROne parity PO-1 (matrix 25 §25.2.3): HROne's Salary structure requests register as views of the compensation changes. */
    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for review', 'filters' => ['status' => ['value' => 'submitted']]],
            'under_review' => ['label' => 'Reviewed, waiting for approval', 'filters' => ['status' => ['value' => 'under_review']]],
            'to_execute' => ['label' => 'Approved, to execute', 'filters' => ['status' => ['value' => 'approved']]],
            'scheduled' => ['label' => 'Scheduled', 'filters' => ['status' => ['value' => 'scheduled']]],
            'effective' => ['label' => 'In force (revisions)', 'filters' => ['status' => ['value' => 'effective']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['salary_revisions_this_fy', 'pending_salary_revisions', 'average_increase_by_department'];
    }

    /** HROne parity PO-1: views, report and the salary upload (the register's "IMPORTS"). */
    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), PayrollRegisters::uploads(['salary'])];
    }
}
