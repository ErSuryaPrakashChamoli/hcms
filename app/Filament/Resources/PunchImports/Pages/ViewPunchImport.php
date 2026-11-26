<?php

namespace App\Filament\Resources\PunchImports\Pages;

use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\Pages\RechecksUploadReview;

class ViewPunchImport extends PeopleViewRecord
{
    use RechecksUploadReview;

    protected static string $resource = PunchImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
