<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

class ListWebhookEndpoints extends PeopleListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // HROne parity SV-26: every endpoint's deliveries in one place ("Logs", "Failed deliveries").
            Action::make('deliveries')->label('All deliveries')->icon('heroicon-m-queue-list')->color('gray')
                ->visible(fn () => WebhookDeliveryResource::canAccess())->url(fn () => WebhookDeliveryResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
