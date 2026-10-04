<?php

namespace App\Filament\Resources\SalaryComponents\Pages;

use App\Filament\Resources\SalaryComponents\SalaryComponentResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditSalaryComponent extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = SalaryComponentResource::class;
}
