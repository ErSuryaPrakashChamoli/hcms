<?php

namespace App\Filament\Resources\GoalPlans\Pages;

use App\Filament\Resources\GoalPlans\GoalPlanResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PerformanceCycleActions;
use App\Filament\Support\PerformanceRegisters;

/** HROne parity GO-1: Goal requests — saved views and presets over the goal sheet submissions. */
class ListGoalPlans extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = GoalPlanResource::class;

    public static function registerKey(): string
    {
        return 'goal_requests';
    }

    public static function presetViews(): array
    {
        return [
            'pending' => ['label' => 'Waiting for approval', 'filters' => ['status' => ['values' => ['pending']]]],
            'approved' => ['label' => 'Approved', 'filters' => ['status' => ['values' => ['approved']]]],
            'sent_back' => ['label' => 'Sent back', 'filters' => ['status' => ['values' => ['rejected']]]],
        ];
    }

    public static function registerReports(): array
    {
        return ['goals_progress'];
    }

    protected function getHeaderActions(): array
    {
        // HROne parity GO-2: the header "Extend goal" of HROne's Goal requests (§27.2.4 #4).
        return [...$this->registerToolActions(), PerformanceCycleActions::extendWindow('goal_setting'), PerformanceRegisters::actionsMenu()];
    }
}
