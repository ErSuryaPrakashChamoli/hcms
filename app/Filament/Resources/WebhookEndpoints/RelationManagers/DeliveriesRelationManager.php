<?php

namespace App\Filament\Resources\WebhookEndpoints\RelationManagers;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Filament\Support\WebhookDeliveryActions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** An endpoint's deliveries. HROne parity SV-26: latency, attempt history, acknowledge, and bulk replay / acknowledge. */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Deliveries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('webhook.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('event')->badge()->color('gray'),
                ...WebhookDeliveryActions::outcomeColumns(),
                TextColumn::make('next_attempt_at')->dateTime()->placeholder('—'),
                TextColumn::make('delivered_at')->dateTime()->placeholder('—'),
                WebhookDeliveryActions::acknowledgedColumn(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(WebhookDelivery::STATUSES)])
            ->headerActions([WebhookDeliveryActions::errorBreakdown($this->getOwnerRecord()->getKey())])
            ->recordActions([
                WebhookDeliveryActions::details(),
                WebhookDeliveryActions::retry(),
                WebhookDeliveryActions::replay(),
                WebhookDeliveryActions::acknowledge(),
            ])
            ->toolbarActions([WebhookDeliveryActions::bulkReplay(), WebhookDeliveryActions::bulkAcknowledge()]);
    }
}
