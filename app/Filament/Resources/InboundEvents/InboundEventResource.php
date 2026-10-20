<?php

namespace App\Filament\Resources\InboundEvents;

use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Services\InboundEvents;
use App\Filament\Resources\InboundEvents\Pages\ListInboundEvents;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 14: every inbound integration event of the tenant: status, attempts, correlation id, error.
 * Payload bodies are never displayed (only their keys, size and checksum); failed and dead-lettered
 * events can be reprocessed with a reason.
 */
class InboundEventResource extends Resource
{
    protected static ?string $model = InboundEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Inbound events';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('system'))
            ->columns([
                TextColumn::make('received_at')->dateTime()->sortable(),
                TextColumn::make('system.code')->label('Integration'),
                TextColumn::make('event_type')->badge()->color('gray'),
                TextColumn::make('external_event_id')->label('Event id')->searchable()->limit(24),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => InboundEvent::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'succeeded' => 'success', 'failed', 'dead_letter' => 'danger', 'retrying', 'processing' => 'warning', default => 'gray'
                    }),
                TextColumn::make('attempts'),
                TextColumn::make('correlation_id')->label('Correlation')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')->label('Error')->limit(60)->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(InboundEvent::STATUSES)])
            ->recordActions([
                Action::make('details')->label('Details')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (InboundEvent $record) => [
                        TextEntry::make('i')->label('Idempotency key')->state($record->idempotency_key),
                        TextEntry::make('c')->label('Correlation id')->state($record->correlation_id),
                        TextEntry::make('k')->label('Payload keys (body not shown)')->state(implode(', ', $record->payload_metadata['keys'] ?? []) ?: '—'),
                        TextEntry::make('s')->label('Payload size / SHA-256')->state($record->payload_size.' bytes · '.$record->payload_sha256),
                        TextEntry::make('p')->label('Payload purged')->state($record->payload_purged_at?->toDateTimeString() ?? 'not yet'),
                        TextEntry::make('r')->label('Result')->state(json_encode($record->result ?? []) ?: '—'),
                        TextEntry::make('e')->label('Last error')->state($record->last_error ?? '—'),
                    ]),
                Action::make('reprocess')->label('Reprocess')->icon('heroicon-m-arrow-path')->color('warning')
                    ->visible(fn (InboundEvent $record) => in_array($record->status, ['failed', 'dead_letter'], true) && auth()->user()->can('integration.manage'))
                    ->schema([Textarea::make('reason')->required()])
                    ->action(fn (InboundEvent $record, array $data) => ServiceDeskActions::run(fn () => app(InboundEvents::class)->reprocess($record, auth()->user(), $data['reason']), 'Queued for processing again')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInboundEvents::route('/')];
    }
}
