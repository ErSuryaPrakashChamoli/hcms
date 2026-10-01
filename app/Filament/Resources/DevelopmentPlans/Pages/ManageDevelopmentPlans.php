<?php

namespace App\Filament\Resources\DevelopmentPlans\Pages;

use App\Filament\Resources\DevelopmentPlans\DevelopmentPlanResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDevelopmentPlans extends ManageRecords
{
    protected static string $resource = DevelopmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return DevelopmentPlanResource::headerActions();
    }
}
