<?php

namespace App\Filament\Resources\SkillScales\Pages;

use App\Filament\Resources\SkillScales\SkillScaleResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageSkillScales extends PeopleManageRecords
{
    protected static string $resource = SkillScaleResource::class;

    protected function getHeaderActions(): array
    {
        return SkillScaleResource::headerActions();
    }
}
