<?php

namespace App\Filament\Resources\GeoFences\Pages;

use App\Domain\Attendance\Services\GeoLocations;
use App\Filament\Resources\GeoFences\GeoFenceResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageGeoFences extends PeopleManageRecords
{
    protected static string $resource = GeoFenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(function (array $data): array {
                app(GeoLocations::class)->assertPlace($data);

                return $data + ['source' => 'hr', 'created_by' => auth()->id()];
            }),
        ];
    }
}
