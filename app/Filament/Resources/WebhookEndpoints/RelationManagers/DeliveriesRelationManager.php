<?php

namespace App\Filament\Resources\WebhookEndpoints\RelationManagers;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Services\Webhooks;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'delivered' => 'success', 'failed', 'dead_letter' => 'danger', default => 'warning'
                }),
                TextColumn::make('attempts'),
                TextColumn::make('response_code')->label('HTTP')->placeholder('—'),
                TextColumn::make('next_attempt_at')->dateTime()->placeholder('—'),
                TextColumn::make('delivered_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'delivered' => 'Delivered', 'dead_letter' => 'Dead letter', 'failed' => 'Failed (before Phase 14)'])])
            ->recordActions([
                Action::make('payload')->label('Payload')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([TextEntry::make('payload')->state(fn (WebhookDelivery $record) => json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->prose(), TextEntry::make('response_excerpt')->placeholder('—')]),
                // Phase 14: a pending delivery is attempted now through the same claim as the scheduler (never twice at once);
                // a dead letter is replayed with a fresh attempt budget (audited).
                Action::make('retry')->label('Retry now')->icon('heroicon-m-arrow-path')->visible(fn (WebhookDelivery $record) => $record->status === 'pending')
                    ->action(fn (WebhookDelivery $record) => ServiceDeskActions::run(fn () => app(Webhooks::class)->deliver($record->id, force: true), fn ($o) => match ($o) {
                        'delivered' => 'Delivered', 'skipped' => 'Another attempt is in progress', 'dead_letter' => 'Moved to dead letter', default => 'Still failing'
                    })),
                Action::make('replay')->label('Replay')->icon('heroicon-m-arrow-uturn-right')->requiresConfirmation()
                    ->visible(fn (WebhookDelivery $record) => in_array($record->status, ['dead_letter', 'failed'], true))
                    ->action(fn (WebhookDelivery $record) => ServiceDeskActions::run(fn () => app(Webhooks::class)->replay($record, auth()->user()), 'Queued for delivery again')),
            ]);
    }
}
