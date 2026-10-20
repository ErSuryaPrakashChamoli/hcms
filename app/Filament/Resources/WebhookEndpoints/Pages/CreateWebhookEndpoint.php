<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Support\Pages\PeopleCreateRecord;

class CreateWebhookEndpoint extends PeopleCreateRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
