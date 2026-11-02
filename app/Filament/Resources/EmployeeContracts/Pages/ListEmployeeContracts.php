<?php

namespace App\Filament\Resources\EmployeeContracts\Pages;

use App\Filament\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListEmployeeContracts extends PeopleListRecords
{
    protected static string $resource = EmployeeContractResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
