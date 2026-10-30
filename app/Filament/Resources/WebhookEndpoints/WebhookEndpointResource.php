<?php

namespace App\Filament\Resources\WebhookEndpoints;

use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Enterprise\Support\WebhookSamples;
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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

/**
 * Outbound webhooks (§87, §88).
 *
 * HROne parity SV-26: success rate over 30 days and a status filter on the list; "Send test" for any event with a sample
 * payload (never real data; "ping" as before by default); "Rotate secret" with a grace period for the old secret.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?int $navigationSort = 20;

    public const SUCCESS_RATE_DAYS = 30;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('webhook.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('url')->url()->rule(new SafeOutboundUrl)->required()->maxLength(255)->helperText('HTTPS endpoint; requests are signed with X-PeopleOS-Signature (HMAC-SHA256 of timestamp.body). While a rotated secret is in its grace period, X-PeopleOS-Signature-Previous carries the signature made with the old one.'),
            TextInput::make('secret')->password()->revealable()->default(fn () => Str::random(48))->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'paused' => 'Paused'])->default('active')->required(),
            CheckboxList::make('events')->options(collect(config('peopleos.enterprise.webhook_events'))->mapWithKeys(fn ($e) => [$e => $e])->all())->columns(3)->columnSpanFull()->required()->bulkToggleable(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'deliveries as recent_attempted_count' => fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(self::SUCCESS_RATE_DAYS))->where('attempts', '>', 0),
                'deliveries as recent_delivered_count' => fn (Builder $q) => $q->where('created_at', '>=', now()->subDays(self::SUCCESS_RATE_DAYS))->where('status', 'delivered'),
            ]))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('url')->limit(50),
                TextColumn::make('events')->state(fn (WebhookEndpoint $record) => count($record->events ?? []).' event(s)'),
                TextColumn::make('last_delivered_at')->dateTime()->placeholder('Never'),
                TextColumn::make('success_rate')->label('Success rate (30 days)')->placeholder('—')
                    ->state(fn (WebhookEndpoint $record) => self::successRate($record))
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state.'%')
                    ->badge()->color(fn ($state) => $state === null ? 'gray' : ($state >= 95 ? 'success' : ($state >= 80 ? 'warning' : 'danger'))),
                TextColumn::make('failure_count')->label('Failures')->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'paused' => 'Paused'])])
            ->recordActions([
                self::testAction(),
                self::rotateSecretAction(),
                EditAction::make(),
            ]);
    }

    /** Delivered ÷ attempted among the deliveries created in the last 30 days (whole percent), or null when none was attempted. */
    public static function successRate(WebhookEndpoint $record): ?int
    {
        $attributes = $record->getAttributes();
        $attempted = (int) ($attributes['recent_attempted_count'] ?? 0);

        return $attempted === 0 ? null : (int) floor(100 * (int) ($attributes['recent_delivered_count'] ?? 0) / $attempted);
    }

    /** SV-26 (event test): posts a sample of the chosen event now; "ping" is the connection test it always was. */
    public static function testAction(): Action
    {
        return Action::make('test')->label('Send test')->icon('heroicon-m-paper-airplane')->color('gray')
            ->schema([
                Select::make('event')->label('Event')->options(fn () => WebhookSamples::events())->default(WebhookSamples::PING)->required()->searchable()
                    ->helperText('A sample payload marked "test": true, built from placeholder values — never real records.'),
            ])
            ->action(fn (WebhookEndpoint $record, array $data) => ServiceDeskActions::run(function () use ($record, $data) {
                $delivery = app(Webhooks::class)->sendTest($record, (string) ($data['event'] ?? WebhookSamples::PING));

                return $delivery->status === 'delivered'
                    ? 'Delivered (HTTP '.$delivery->response_code.', '.$delivery->duration_ms.' ms)'
                    : 'Endpoint answered HTTP '.($delivery->response_code ?: 'error').'; see deliveries';
            }, fn ($m) => $m));
    }

    /** SV-26: a new secret at once; the old one signs a second header for the grace period. The new secret is shown once. */
    public static function rotateSecretAction(): Action
    {
        return Action::make('rotateSecret')->label('Rotate secret')->icon('heroicon-m-key')->color('warning')
            ->visible(fn () => auth()->user()?->can('webhook.manage') ?? false)
            ->modalDescription('A new secret signs every request from now on. For the grace period the old secret also signs each request, in X-PeopleOS-Signature-Previous, so the receiver can switch over without missing events.')
            ->schema([
                TextInput::make('grace_hours')->label('Old secret grace period (hours)')->integer()->required()
                    ->minValue(Webhooks::GRACE_HOURS_MIN)->maxValue(Webhooks::GRACE_HOURS_MAX)->default(24),
                Textarea::make('reason')->required()->maxLength(255),
            ])
            ->action(function (WebhookEndpoint $record, array $data, $livewire) {
                try {
                    $secret = app(Webhooks::class)->rotateSecret($record, auth()->user(), (int) $data['grace_hours'], (string) $data['reason']);
                } catch (RuntimeException $e) {
                    ServiceDeskActions::refuse($e->getMessage());

                    return;
                }
                Notification::make()->success()->title('Secret rotated — copy the new secret now, it is shown here once')->body($secret)->persistent()->send();
                if ($livewire instanceof EditWebhookEndpoint) {
                    // The edit form still holds the old secret; reload it so a later save cannot put the old one back.
                    $livewire->redirect(static::getUrl('edit', ['record' => $record]));
                }
            });
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
