<?php

namespace App\Filament\Resources\StatutoryProfiles\Pages;

use App\Filament\Resources\StatutoryProfiles\StatutoryProfileResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageStatutoryProfiles extends PeopleManageRecords
{
    protected static string $resource = StatutoryProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
