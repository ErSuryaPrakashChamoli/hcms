<?php

namespace App\Filament\Resources\ServiceDefinitions\Pages;

use App\Filament\Resources\ServiceDefinitions\ServiceDefinitionResource;
use App\Filament\Support\Pages\PeopleEditRecord;

class EditServiceDefinition extends PeopleEditRecord
{
    protected static string $resource = ServiceDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
