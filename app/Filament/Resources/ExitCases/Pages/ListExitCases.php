<?php

namespace App\Filament\Resources\ExitCases\Pages;

use App\Domain\Exit\Models\ExitCase;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Support\ExitActions;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;

class ListExitCases extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = ExitCaseResource::class;

    public static function registerKey(): string
    {
        return 'exit_cases';
    }

    /** HROne parity WO-1: the Separation registers (resignations, clearances, final clearances) as views of the exit cases. */
    public static function presetViews(): array
    {
        return [
            'resignations' => ['label' => 'Resignations', 'filters' => ['type' => ['value' => 'resignation'], 'status' => ['values' => ExitCase::OPEN]]],
            'notice' => ['label' => 'Serving notice', 'filters' => ['status' => ['values' => ['initiated', 'notice']]]],
            'clearance' => ['label' => 'In clearance', 'filters' => ['status' => ['values' => ['clearance']]]],
            'final' => ['label' => 'Final clearance (clearance or settlement)', 'filters' => ['status' => ['values' => ['clearance', 'settlement']]]],
            'withdrawn' => ['label' => 'Withdrawn resignations', 'filters' => ['type' => ['value' => 'resignation'], 'status' => ['values' => ['withdrawn']]]],
            // HROne parity PO-1 (matrix 25 §25.7): the Payroll panel's Settlement and Settlement requests registers.
            'settlement' => ['label' => 'Full and final settlement', 'filters' => ['status' => ['values' => ['settlement']]]],
            'settled' => ['label' => 'Settled (completed exits)', 'filters' => ['status' => ['values' => ['completed']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['resignations_this_month', 'notice_period_tracker', 'clearances_pending', 'fnf_pending', 'exits_this_fy', 'exit_reasons'];
    }

    public static function registerBulkOperations(): array
    {
        return ['exit_cases.start_clearance', 'exit_cases.complete'];
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), ExitActions::initiate(), ExitActions::resign()];
    }
}
