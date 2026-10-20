<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\CreateAction;

class ListWebhookEndpoints extends PeopleListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
