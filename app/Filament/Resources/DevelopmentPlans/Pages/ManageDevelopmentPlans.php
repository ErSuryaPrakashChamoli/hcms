<?php

namespace App\Filament\Resources\DevelopmentPlans\Pages;

use App\Filament\Resources\DevelopmentPlans\DevelopmentPlanResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageDevelopmentPlans extends PeopleManageRecords
{
    protected static string $resource = DevelopmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return DevelopmentPlanResource::headerActions();
    }
}
