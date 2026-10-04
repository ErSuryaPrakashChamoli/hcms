<?php

namespace App\Filament\Resources\LearningEnrolments\Pages;

use App\Filament\Resources\LearningEnrolments\LearningEnrolmentResource;
use App\Filament\Support\LearningActions;
use App\Filament\Support\Pages\PeopleListRecords;

class ListLearningEnrolments extends PeopleListRecords
{
    protected static string $resource = LearningEnrolmentResource::class;

    protected function getHeaderActions(): array
    {
        return [LearningActions::requestLearning(), LearningActions::enrol()];
    }
}
