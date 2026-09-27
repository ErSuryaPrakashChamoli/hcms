<?php

namespace App\Filament\Resources\AttendancePunches\Pages;

use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendancePunches extends ListRecords
{
    protected static string $resource = AttendancePunchResource::class;
}
