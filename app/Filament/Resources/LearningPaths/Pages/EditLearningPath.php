<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditLearningPath extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = LearningPathResource::class;
}
