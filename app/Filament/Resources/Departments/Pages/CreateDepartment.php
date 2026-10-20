<?php

namespace App\Filament\Resources\Departments\Pages;

use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateDepartment extends PeopleCreateRecord
{
    protected static string $resource = DepartmentResource::class;
}
