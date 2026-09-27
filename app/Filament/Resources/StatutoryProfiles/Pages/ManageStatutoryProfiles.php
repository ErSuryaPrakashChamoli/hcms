<?php

namespace App\Filament\Resources\StatutoryProfiles\Pages;

use App\Filament\Resources\StatutoryProfiles\StatutoryProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStatutoryProfiles extends ManageRecords
{
    protected static string $resource = StatutoryProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
