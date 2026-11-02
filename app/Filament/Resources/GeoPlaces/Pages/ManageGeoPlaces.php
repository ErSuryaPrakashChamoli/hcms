<?php

namespace App\Filament\Resources\GeoPlaces\Pages;

use App\Filament\Resources\GeoPlaces\GeoPlaceResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageGeoPlaces extends PeopleManageRecords
{
    protected static string $resource = GeoPlaceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
