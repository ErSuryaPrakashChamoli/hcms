<?php

namespace App\Filament\Resources\EmployeeImports\Pages;

use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Support\ImportActions;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeImport extends ViewRecord
{
    protected static string $resource = EmployeeImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
