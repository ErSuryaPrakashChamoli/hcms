<?php

namespace App\Filament\Resources\BusinessUnits\Pages;

use App\Filament\Resources\BusinessUnits\BusinessUnitResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateBusinessUnit extends PeopleCreateRecord
{
    protected static string $resource = BusinessUnitResource::class;
}
