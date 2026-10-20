<?php

namespace App\Filament\Resources\CompensationCycles\Pages;

use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageCompensationCycles extends PeopleManageRecords
{
    protected static string $resource = CompensationCycleResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationCycleResource::headerActions();
    }
}
