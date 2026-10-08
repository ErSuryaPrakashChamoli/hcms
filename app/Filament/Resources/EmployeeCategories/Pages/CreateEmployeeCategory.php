<?php

namespace App\Filament\Resources\EmployeeCategories\Pages;

use App\Filament\Resources\EmployeeCategories\EmployeeCategoryResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateEmployeeCategory extends PeopleCreateRecord
{
    protected static string $resource = EmployeeCategoryResource::class;
}
