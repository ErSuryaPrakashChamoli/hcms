<?php

namespace App\Filament\Resources\WorkforcePlans\Pages;

use App\Filament\Resources\WorkforcePlans\WorkforcePlanResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkforcePlans extends ManageRecords
{
    protected static string $resource = WorkforcePlanResource::class;

    protected function getHeaderActions(): array
    {
        return WorkforcePlanResource::headerActions();
    }
}
