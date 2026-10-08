<?php

namespace App\Filament\Resources\CriticalPositions\Pages;

use App\Filament\Resources\CriticalPositions\CriticalPositionResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageCriticalPositions extends PeopleManageRecords
{
    protected static string $resource = CriticalPositionResource::class;

    protected function getHeaderActions(): array
    {
        return CriticalPositionResource::headerActions();
    }
}
