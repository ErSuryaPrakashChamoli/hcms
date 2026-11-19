<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateLearningPath extends PeopleCreateRecord
{
    protected static string $resource = LearningPathResource::class;
}
