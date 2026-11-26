<?php

namespace App\Filament\Resources\TimeOfficeImports\Pages;

use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\Pages\RechecksUploadReview;

class ViewTimeOfficeImport extends PeopleViewRecord
{
    use RechecksUploadReview;

    protected static string $resource = TimeOfficeImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
