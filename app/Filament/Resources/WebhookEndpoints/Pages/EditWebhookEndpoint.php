<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEndpoint extends EditRecord
{
    use GovernedEdit;

    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
