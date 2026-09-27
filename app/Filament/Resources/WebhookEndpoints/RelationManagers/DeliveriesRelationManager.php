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
                    'delivered' => 'success', 'failed' => 'danger', default => 'warning'
                }),
                TextColumn::make('attempts'),
                TextColumn::make('response_code')->label('HTTP')->placeholder('—'),
                TextColumn::make('next_attempt_at')->dateTime()->placeholder('—'),
                TextColumn::make('delivered_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'delivered' => 'Delivered', 'failed' => 'Failed'])])
            ->recordActions([
                Action::make('payload')->label('Payload')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([TextEntry::make('payload')->state(fn (WebhookDelivery $record) => json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->prose(), TextEntry::make('response_excerpt')->placeholder('—')]),
                Action::make('retry')->label('Retry now')->icon('heroicon-m-arrow-path')->visible(fn (WebhookDelivery $record) => $record->status !== 'delivered')
                    ->action(fn (WebhookDelivery $record) => ServiceDeskActions::run(fn () => app(Webhooks::class)->attempt($record->forceFill(['status' => 'pending']) && $record ? $record : $record), fn ($o) => $o === 'delivered' ? 'Delivered' : 'Still failing')),
            ]);
    }
}
