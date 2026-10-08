<?php

namespace App\Filament\Resources\LearningInstructors\Pages;

use App\Filament\Resources\LearningInstructors\LearningInstructorResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageLearningInstructors extends PeopleManageRecords
{
    protected static string $resource = LearningInstructorResource::class;

    protected function getHeaderActions(): array
    {
        return LearningInstructorResource::headerActions();
    }
}
