<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Masters\MasterDeleteAction;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditLocation extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MasterDeleteAction::make(),
        ];
    }
}
