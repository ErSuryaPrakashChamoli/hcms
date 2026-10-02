<?php

namespace App\Filament\Resources\CompensationCycles\Pages;

use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCompensationCycle extends ViewRecord
{
    protected static string $resource = CompensationCycleResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationCycleResource::stepActions();
    }
}
