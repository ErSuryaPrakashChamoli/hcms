<?php

namespace App\Filament\Resources\IntegrationSystems\Pages;

use App\Filament\Resources\IntegrationSystems\IntegrationSystemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationSystems extends ListRecords
{
    protected static string $resource = IntegrationSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
