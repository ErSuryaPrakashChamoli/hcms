<?php

namespace App\Filament\Resources\CompensationRanges\Pages;

use App\Filament\Resources\CompensationRanges\CompensationRangeResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageCompensationRanges extends PeopleManageRecords
{
    protected static string $resource = CompensationRangeResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationRangeResource::headerActions();
    }
}
