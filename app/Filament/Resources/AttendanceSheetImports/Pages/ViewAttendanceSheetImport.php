<?php

namespace App\Filament\Resources\AttendanceSheetImports\Pages;

use App\Filament\Resources\AttendanceSheetImports\AttendanceSheetImportResource;
use App\Filament\Support\ImportActions;
use App\Filament\Support\Pages\PeopleViewRecord;

class ViewAttendanceSheetImport extends PeopleViewRecord
{
    protected static string $resource = AttendanceSheetImportResource::class;

    protected function getHeaderActions(): array
    {
        return ImportActions::forImport();
    }
}
