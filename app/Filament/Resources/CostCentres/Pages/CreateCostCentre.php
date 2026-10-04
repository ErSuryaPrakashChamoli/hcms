<?php

namespace App\Filament\Resources\CostCentres\Pages;

use App\Filament\Resources\CostCentres\CostCentreResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCostCentre extends PeopleCreateRecord
{
    protected static string $resource = CostCentreResource::class;
}
