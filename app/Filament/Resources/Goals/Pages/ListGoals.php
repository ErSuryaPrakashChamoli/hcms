<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\PerformanceActions;
use App\Filament\Support\PerformanceRegisters;
use Filament\Actions\CreateAction;

class ListGoals extends PeopleListRecords
{
    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        // HP-8d / HP-8e: submit a goal sheet; create goals from a goal template. HROne parity GO-1: the performance panel's
        // actions menu for performance HR (hidden for everyone else).
        return [CreateAction::make(), ...PerformanceActions::forGoalSheet(), PerformanceRegisters::actionsMenu()];
    }
}
