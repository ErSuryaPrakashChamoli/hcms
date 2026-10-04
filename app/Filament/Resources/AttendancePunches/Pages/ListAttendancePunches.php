<?php

namespace App\Filament\Resources\AttendancePunches\Pages;

use App\Filament\Resources\AttendancePunches\AttendancePunchResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListAttendancePunches extends PeopleListRecords
{
    protected static string $resource = AttendancePunchResource::class;
}
