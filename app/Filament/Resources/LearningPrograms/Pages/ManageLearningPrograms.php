<?php

namespace App\Filament\Resources\LearningPrograms\Pages;

use App\Filament\Resources\LearningPrograms\LearningProgramResource;
use Filament\Resources\Pages\ManageRecords;

class ManageLearningPrograms extends ManageRecords
{
    protected static string $resource = LearningProgramResource::class;

    protected function getHeaderActions(): array
    {
        return LearningProgramResource::headerActions();
    }
}
