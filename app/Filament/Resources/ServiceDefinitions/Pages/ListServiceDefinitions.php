<?php

namespace App\Filament\Resources\ServiceDefinitions\Pages;

use App\Filament\Resources\ServiceDefinitions\ServiceDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceDefinitions extends ListRecords
{
    protected static string $resource = ServiceDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New service')];
    }
}
