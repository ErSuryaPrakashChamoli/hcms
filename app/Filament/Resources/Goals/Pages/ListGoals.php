<?php

namespace App\Filament\Resources\Goals\Pages;

use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListGoals extends PeopleListRecords
{
    protected static string $resource = GoalResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
