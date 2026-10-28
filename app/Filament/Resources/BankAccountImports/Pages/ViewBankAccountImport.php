<?php

namespace App\Filament\Resources\BankAccountImports\Pages;

use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewBankAccountImport extends PeopleViewRecord
{
    protected static string $resource = BankAccountImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
