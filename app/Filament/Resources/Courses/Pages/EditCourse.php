<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditCourse extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = CourseResource::class;
}
