<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditWebhookEndpoint extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
