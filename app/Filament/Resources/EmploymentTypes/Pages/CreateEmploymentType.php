<?php

namespace App\Filament\Resources\EmploymentTypes\Pages;

use App\Filament\Resources\EmploymentTypes\EmploymentTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmploymentType extends CreateRecord
{
    protected static string $resource = EmploymentTypeResource::class;
}
