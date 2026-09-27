<?php

namespace App\Filament\Resources\SalaryComponents\Pages;

use App\Filament\Resources\SalaryComponents\SalaryComponentResource;
use App\Filament\Support\GovernedEdit;
use Filament\Resources\Pages\EditRecord;

class EditSalaryComponent extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = SalaryComponentResource::class;
}
