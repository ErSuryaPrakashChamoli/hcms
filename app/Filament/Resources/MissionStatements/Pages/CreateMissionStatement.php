<?php

namespace App\Filament\Resources\MissionStatements\Pages;

use App\Filament\Resources\MissionStatements\MissionStatementResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateMissionStatement extends PeopleCreateRecord
{
    protected static string $resource = MissionStatementResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
