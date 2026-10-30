<?php

namespace App\Filament\Resources\GoalTemplates\Pages;

use App\Filament\Resources\GoalTemplates\GoalTemplateResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListGoalTemplates extends PeopleListRecords
{
    protected static string $resource = GoalTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add template')];
    }
}
