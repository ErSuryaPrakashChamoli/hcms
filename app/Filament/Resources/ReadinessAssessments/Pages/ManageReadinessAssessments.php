<?php

namespace App\Filament\Resources\ReadinessAssessments\Pages;

use App\Filament\Resources\ReadinessAssessments\ReadinessAssessmentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageReadinessAssessments extends ManageRecords
{
    protected static string $resource = ReadinessAssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return ReadinessAssessmentResource::headerActions();
    }
}
