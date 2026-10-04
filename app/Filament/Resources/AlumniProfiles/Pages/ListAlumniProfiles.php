<?php

namespace App\Filament\Resources\AlumniProfiles\Pages;

use App\Filament\Resources\AlumniProfiles\AlumniProfileResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListAlumniProfiles extends PeopleListRecords
{
    protected static string $resource = AlumniProfileResource::class;
}
