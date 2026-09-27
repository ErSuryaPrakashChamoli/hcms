<?php

namespace App\Filament\Resources\LearningEnrolments\Pages;

use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Support\LearningActions;
use Filament\Resources\Pages\ListRecords;

class ListLearningEnrolments extends ListRecords
{
    protected static string $resource = LearningEnrolmentResource::class;

    protected function getHeaderActions(): array
    {
        return [LearningActions::enrol()];
    }
}
