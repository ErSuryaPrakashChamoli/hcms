<?php

namespace App\Filament\Resources\CareerPaths\Pages;

use App\Filament\Resources\CareerPaths\CareerPathResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditCareerPath extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = CareerPathResource::class;
}
