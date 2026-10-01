<?php

namespace App\Filament\Resources\WorkforceScenarios\Pages;

use App\Filament\Resources\WorkforceScenarios\WorkforceScenarioResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkforceScenarios extends ManageRecords
{
    protected static string $resource = WorkforceScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return WorkforceScenarioResource::headerActions();
    }
}
