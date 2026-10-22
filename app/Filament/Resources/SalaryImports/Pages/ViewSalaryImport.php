<?php

namespace App\Filament\Resources\SalaryImports\Pages;

use App\Filament\Resources\SalaryImports\SalaryImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewSalaryImport extends PeopleViewRecord
{
    protected static string $resource = SalaryImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
