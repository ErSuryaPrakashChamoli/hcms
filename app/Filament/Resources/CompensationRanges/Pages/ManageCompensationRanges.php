<?php

namespace App\Filament\Resources\CompensationRanges\Pages;

use App\Filament\Resources\CompensationRanges\CompensationRangeResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCompensationRanges extends ManageRecords
{
    protected static string $resource = CompensationRangeResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationRangeResource::headerActions();
    }
}
