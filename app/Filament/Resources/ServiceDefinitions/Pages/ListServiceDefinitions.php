<?php

namespace App\Filament\Resources\ServiceDefinitions\Pages;

use App\Filament\Resources\ServiceDefinitions\ServiceDefinitionResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListServiceDefinitions extends PeopleListRecords
{
    protected static string $resource = ServiceDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New service')];
    }
}
