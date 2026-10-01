<?php

namespace App\Filament\Resources\LearningInstructors\Pages;

use App\Filament\Resources\LearningInstructors\LearningInstructorResource;
use Filament\Resources\Pages\ManageRecords;

class ManageLearningInstructors extends ManageRecords
{
    protected static string $resource = LearningInstructorResource::class;

    protected function getHeaderActions(): array
    {
        return LearningInstructorResource::headerActions();
    }
}
