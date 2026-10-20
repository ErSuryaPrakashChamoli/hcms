<?php

namespace App\Filament\Resources\TdsProfiles\Pages;

use App\Filament\Resources\TdsProfiles\TdsProfileResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\CreateAction;

class ManageTdsProfiles extends PeopleManageRecords
{
    protected static string $resource = TdsProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
