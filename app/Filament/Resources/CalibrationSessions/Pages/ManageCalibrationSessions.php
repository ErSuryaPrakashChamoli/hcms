<?php

namespace App\Filament\Resources\CalibrationSessions\Pages;

use App\Filament\Resources\CalibrationSessions\CalibrationSessionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCalibrationSessions extends ManageRecords
{
    protected static string $resource = CalibrationSessionResource::class;

    protected function getHeaderActions(): array
    {
        return CalibrationSessionResource::headerActions();
    }
}
