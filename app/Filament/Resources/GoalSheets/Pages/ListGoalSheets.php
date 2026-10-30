<?php

namespace App\Filament\Resources\GoalSheets\Pages;

use App\Filament\Resources\GoalSheets\GoalSheetResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PerformanceRegisters;

/** HROne parity GO-1: Goal sheets — saved views and presets by sheet status. */
class ListGoalSheets extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = GoalSheetResource::class;

    public static function registerKey(): string
    {
        return 'goal_sheets';
    }

    public static function presetViews(): array
    {
        return [
            'not_created' => ['label' => 'Goal sheet not created', 'filters' => ['sheet_status' => ['value' => 'not_created']]],
            'draft' => ['label' => 'Draft (not submitted)', 'filters' => ['sheet_status' => ['value' => 'draft']]],
            'pending' => ['label' => 'Waiting for approval', 'filters' => ['sheet_status' => ['value' => 'pending']]],
            'sent_back' => ['label' => 'Sent back', 'filters' => ['sheet_status' => ['value' => 'sent_back']]],
            'active' => ['label' => 'Active', 'filters' => ['sheet_status' => ['value' => 'active']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['goals_progress', 'overdue_goals'];
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), PerformanceRegisters::actionsMenu()];
    }
}
