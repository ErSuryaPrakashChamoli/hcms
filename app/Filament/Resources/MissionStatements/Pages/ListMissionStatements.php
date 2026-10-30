<?php

namespace App\Filament\Resources\MissionStatements\Pages;

use App\Filament\Resources\MissionStatements\MissionStatementResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListMissionStatements extends PeopleListRecords
{
    protected static string $resource = MissionStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add policy')];
    }
}
