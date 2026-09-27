<?php

namespace App\Filament\Resources\PunchImports\Pages;

use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Support\ImportActions;
use Filament\Resources\Pages\ViewRecord;

class ViewPunchImport extends ViewRecord
{
    protected static string $resource = PunchImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
