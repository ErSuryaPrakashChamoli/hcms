<?php

namespace App\Filament\Resources\WebhookEndpoints;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\Webhooks;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\WebhookEndpoints\Pages\CreateWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\Pages\EditWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\ServiceDeskActions;
use App\Support\Validation\SafeOutboundUrl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/** Outbound webhooks (§87, §88). */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('webhook.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('url')->url()->rule(new SafeOutboundUrl)->required()->maxLength(255)->helperText('HTTPS endpoint; requests are signed with X-PeopleOS-Signature (HMAC-SHA256 of timestamp.body)'),
            TextInput::make('secret')->password()->revealable()->default(fn () => Str::random(48))->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'paused' => 'Paused'])->default('active')->required(),
            CheckboxList::make('events')->options(collect(config('peopleos.enterprise.webhook_events'))->mapWithKeys(fn ($e) => [$e => $e])->all())->columns(3)->columnSpanFull()->required()->bulkToggleable(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('url')->limit(50),
                TextColumn::make('events')->state(fn (WebhookEndpoint $record) => count($record->events ?? []).' event(s)'),
                TextColumn::make('last_delivered_at')->dateTime()->placeholder('Never'),
                TextColumn::make('failure_count')->label('Failures')->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('test')->label('Send test')->icon('heroicon-m-paper-airplane')->color('gray')
                    ->action(fn (WebhookEndpoint $record) => ServiceDeskActions::run(function () use ($record) {
                        $delivery = WebhookDelivery::create(['webhook_endpoint_id' => $record->id, 'event' => 'ping', 'event_id' => (string) Str::ulid(), 'payload' => ['event' => 'ping', 'occurred_at' => now()->toIso8601String(), 'data' => ['message' => 'PeopleOS webhook test']], 'status' => 'pending', 'next_attempt_at' => now()]);
                        $outcome = app(Webhooks::class)->attempt($delivery);

                        return $outcome === 'delivered' ? 'Delivered (HTTP '.$delivery->response_code.')' : 'Endpoint answered HTTP '.($delivery->response_code ?: 'error').'; see deliveries';
                    }, fn ($m) => $m)),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'create' => CreateWebhookEndpoint::route('/create'),
            'edit' => EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}
