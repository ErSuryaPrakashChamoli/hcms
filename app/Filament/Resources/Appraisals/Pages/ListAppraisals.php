<?php

namespace App\Filament\Resources\Appraisals\Pages;

use App\Filament\Resources\Appraisals\AppraisalResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PerformanceRegisters;

class ListAppraisals extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = AppraisalResource::class;

    public static function registerKey(): string
    {
        return 'appraisals';
    }

    /** HROne parity GO-1: Performance reviews by status (views of this register). */
    public static function presetViews(): array
    {
        return [
            'in_review' => ['label' => 'In review', 'filters' => ['status' => ['value' => 'in_review']]],
            'calibration' => ['label' => 'In calibration', 'filters' => ['status' => ['value' => 'calibration']]],
            'finalized' => ['label' => 'Finalized', 'filters' => ['status' => ['value' => 'finalized']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['appraisal_status', 'rating_distribution'];
    }

    protected function getHeaderActions(): array
    {
        // HROne parity GO-1: views, report and the performance actions for performance HR; everyone else's list is unchanged.
        return PerformanceRegisters::hrSide() ? [...$this->registerToolActions(), PerformanceRegisters::actionsMenu()] : [];
    }
}
