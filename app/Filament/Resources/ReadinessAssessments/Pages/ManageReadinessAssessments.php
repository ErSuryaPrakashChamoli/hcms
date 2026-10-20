<?php

namespace App\Filament\Resources\ReadinessAssessments\Pages;

use App\Filament\Resources\ReadinessAssessments\ReadinessAssessmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageReadinessAssessments extends PeopleManageRecords
{
    protected static string $resource = ReadinessAssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return ReadinessAssessmentResource::headerActions();
    }
}
