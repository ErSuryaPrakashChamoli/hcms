<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateCourse extends PeopleCreateRecord
{
    protected static string $resource = CourseResource::class;
}
