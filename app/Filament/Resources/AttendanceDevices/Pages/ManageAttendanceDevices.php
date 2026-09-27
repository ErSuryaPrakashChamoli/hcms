<?php

namespace App\Filament\Resources\AttendanceDevices\Pages;

use App\Filament\Resources\AttendanceDevices\AttendanceDeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendanceDevices extends ManageRecords
{
    protected static string $resource = AttendanceDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
