<?php

namespace App\Filament\Resources\WorkforcePlans\Pages;

use App\Filament\Resources\WorkforcePlans\WorkforcePlanResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageWorkforcePlans extends PeopleManageRecords
{
    protected static string $resource = WorkforcePlanResource::class;

    protected function getHeaderActions(): array
    {
        return WorkforcePlanResource::headerActions();
    }
}
