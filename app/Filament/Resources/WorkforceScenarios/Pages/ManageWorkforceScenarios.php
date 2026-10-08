<?php

namespace App\Filament\Resources\WorkforceScenarios\Pages;

use App\Filament\Resources\WorkforceScenarios\WorkforceScenarioResource;
use App\Filament\Support\Pages\PeopleManageRecords;

class ManageWorkforceScenarios extends PeopleManageRecords
{
    protected static string $resource = WorkforceScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return WorkforceScenarioResource::headerActions();
    }
}
