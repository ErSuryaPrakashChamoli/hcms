<?php

namespace App\Filament\Resources\WebhookDeliveries\Pages;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Filament\Resources\IntegrationLogExports\IntegrationLogExportResource;
use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\WebhookDeliveryActions;
use Filament\Actions\Action;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWebhookDeliveries extends PeopleListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All deliveries'),
            'failed' => Tab::make('Failed deliveries')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', WebhookDelivery::FAILED)->whereNull('acknowledged_at')),
            'pending' => Tab::make('Waiting to retry')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            WebhookDeliveryActions::errorBreakdown(),
            WebhookDeliveryResource::exportAction(),
            Action::make('exports')->label('Log exports')->icon('heroicon-m-folder-arrow-down')->color('gray')
                ->visible(fn () => IntegrationLogExportResource::canAccess())->url(fn () => IntegrationLogExportResource::getUrl('index')),
            Action::make('webhooks')->label('Webhooks')->icon('heroicon-m-bolt')->color('gray')->url(fn () => WebhookEndpointResource::getUrl('index')),
        ];
    }
}
