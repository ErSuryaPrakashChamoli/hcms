<?php

namespace App\Filament\Resources\EmploymentTypes\Pages;

use App\Filament\Resources\EmploymentTypes\EmploymentTypeResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateEmploymentType extends PeopleCreateRecord
{
    protected static string $resource = EmploymentTypeResource::class;
}
