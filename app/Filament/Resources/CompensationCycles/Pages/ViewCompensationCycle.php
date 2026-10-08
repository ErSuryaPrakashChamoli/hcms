<?php

namespace App\Filament\Resources\CompensationCycles\Pages;

use App\Filament\Resources\CompensationCycles\CompensationCycleResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewCompensationCycle extends PeopleViewRecord
{
    protected static string $resource = CompensationCycleResource::class;

    protected function getHeaderActions(): array
    {
        return CompensationCycleResource::stepActions();
    }
}
