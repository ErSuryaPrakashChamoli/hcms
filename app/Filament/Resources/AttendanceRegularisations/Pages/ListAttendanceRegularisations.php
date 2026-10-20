<?php

namespace App\Filament\Resources\AttendanceRegularisations\Pages;

use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListAttendanceRegularisations extends PeopleListRecords
{
    protected static string $resource = AttendanceRegularisationResource::class;
}
