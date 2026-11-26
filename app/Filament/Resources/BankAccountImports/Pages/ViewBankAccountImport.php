<?php

namespace App\Filament\Resources\BankAccountImports\Pages;

use App\Filament\Resources\BankAccountImports\BankAccountImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\Pages\RechecksUploadReview;

class ViewBankAccountImport extends PeopleViewRecord
{
    use RechecksUploadReview;

    protected static string $resource = BankAccountImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
