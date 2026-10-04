<?php

namespace App\Filament\Resources\IntegrationSystems\Pages;

use App\Filament\Resources\IntegrationSystems\IntegrationSystemResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListIntegrationSystems extends PeopleListRecords
{
    protected static string $resource = IntegrationSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
