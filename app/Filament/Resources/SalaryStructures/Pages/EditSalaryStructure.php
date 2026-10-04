<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditSalaryStructure extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = SalaryStructureResource::class;
}
