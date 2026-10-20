<?php

namespace App\Filament\Resources\SalaryComponents\Pages;

use App\Filament\Resources\SalaryComponents\SalaryComponentResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateSalaryComponent extends PeopleCreateRecord
{
    protected static string $resource = SalaryComponentResource::class;
}
