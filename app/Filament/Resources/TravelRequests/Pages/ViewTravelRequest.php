<?php

namespace App\Filament\Resources\TravelRequests\Pages;

use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewTravelRequest extends PeopleViewRecord
{
    protected static string $resource = TravelRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [TravelRequestResource::cancelAction()];
    }
}
