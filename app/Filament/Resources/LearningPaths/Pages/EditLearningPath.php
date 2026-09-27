<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Support\GovernedEdit;
use Filament\Resources\Pages\EditRecord;

class EditLearningPath extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = LearningPathResource::class;
}
