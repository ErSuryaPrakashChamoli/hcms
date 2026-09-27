<?php

namespace App\Filament\Resources\EmployeeCategories\Pages;

use App\Filament\Resources\EmployeeCategories\EmployeeCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeCategory extends CreateRecord
{
    protected static string $resource = EmployeeCategoryResource::class;
}
