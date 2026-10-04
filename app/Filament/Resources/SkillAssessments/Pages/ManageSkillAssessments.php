<?php

namespace App\Filament\Resources\SkillAssessments\Pages;

use App\Filament\Resources\SkillAssessments\SkillAssessmentResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageSkillAssessments extends PeopleManageRecords
{
    protected static string $resource = SkillAssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return SkillAssessmentResource::headerActions();
    }
}
