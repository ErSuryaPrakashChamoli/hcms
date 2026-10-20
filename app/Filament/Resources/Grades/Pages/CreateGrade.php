<?php

namespace App\Filament\Resources\Grades\Pages;

use App\Filament\Resources\Grades\GradeResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateGrade extends PeopleCreateRecord
{
    protected static string $resource = GradeResource::class;
}
