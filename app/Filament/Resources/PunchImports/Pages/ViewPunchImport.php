<?php

namespace App\Filament\Resources\PunchImports\Pages;

use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewPunchImport extends PeopleViewRecord
{
    protected static string $resource = PunchImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
