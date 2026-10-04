<?php

namespace App\Filament\Resources\LearningPrograms\Pages;

use App\Filament\Resources\LearningPrograms\LearningProgramResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageLearningPrograms extends PeopleManageRecords
{
    protected static string $resource = LearningProgramResource::class;

    protected function getHeaderActions(): array
    {
        return LearningProgramResource::headerActions();
    }
}
