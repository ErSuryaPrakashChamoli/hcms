<?php

namespace App\Filament\Resources\EmployeeImports\Pages;

use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewEmployeeImport extends PeopleViewRecord
{
    protected static string $resource = EmployeeImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
