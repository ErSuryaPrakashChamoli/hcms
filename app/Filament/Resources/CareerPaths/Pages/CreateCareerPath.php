<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCareerPath extends PeopleCreateRecord
{
    protected static string $resource = CareerPathResource::class;
}
