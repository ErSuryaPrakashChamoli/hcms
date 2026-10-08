<?php

namespace App\Filament\Resources\ServiceDefinitions\Pages;

use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Filament\Resources\ServiceDefinitions\ServiceDefinitionResource;
use App\Filament\Support\Pages\PeopleCreateRecord;
use App\Filament\Support\ServiceDeskActions;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateServiceDefinition extends PeopleCreateRecord
{
    protected static string $resource = ServiceDefinitionResource::class;

    /** A service starts with a draft version 1: complete it, submit it, and have it approved. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ServiceCatalogue::class)->create($data + ['category' => $data['ticket_category_id'] ?? null], auth()->user());
        } catch (RuntimeException $e) {
            ServiceDeskActions::refuse($e->getMessage());

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
