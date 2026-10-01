<?php

namespace App\Filament\Resources\CriticalPositions\Pages;

use App\Filament\Resources\CriticalPositions\CriticalPositionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCriticalPositions extends ManageRecords
{
    protected static string $resource = CriticalPositionResource::class;

    protected function getHeaderActions(): array
    {
        return CriticalPositionResource::headerActions();
    }
}
