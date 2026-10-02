<?php

namespace App\Filament\Resources\CompensationCycles\Pages;

use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCompensationCycles extends ManageRecords
{
    protected static string $resource = CompensationCycleResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationCycleResource::headerActions();
    }
}
