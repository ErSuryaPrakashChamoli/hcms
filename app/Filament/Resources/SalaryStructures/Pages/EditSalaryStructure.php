<?php

namespace App\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Resources\SalaryStructures\SalaryStructureResource;
use App\Filament\Support\GovernedEdit;
use Filament\Resources\Pages\EditRecord;

class EditSalaryStructure extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = SalaryStructureResource::class;
}
