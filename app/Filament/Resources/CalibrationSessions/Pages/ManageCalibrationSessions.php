<?php

namespace App\Filament\Resources\CalibrationSessions\Pages;

use App\Filament\Resources\CalibrationSessions\CalibrationSessionResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageCalibrationSessions extends PeopleManageRecords
{
    protected static string $resource = CalibrationSessionResource::class;

    protected function getHeaderActions(): array
    {
        return CalibrationSessionResource::headerActions();
    }
}
