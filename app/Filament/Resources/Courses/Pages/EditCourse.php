<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Support\GovernedEdit;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = CourseResource::class;
}
