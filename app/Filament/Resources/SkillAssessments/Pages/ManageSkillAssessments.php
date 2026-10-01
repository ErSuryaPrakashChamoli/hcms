<?php

namespace App\Filament\Resources\SkillAssessments\Pages;

use App\Filament\Resources\SkillAssessments\SkillAssessmentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSkillAssessments extends ManageRecords
{
    protected static string $resource = SkillAssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return SkillAssessmentResource::headerActions();
    }
}
