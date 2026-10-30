<?php

namespace App\Filament\Resources\WebhookDeliveries;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Integration\Services\IntegrationLogExports;
use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Support\ServiceDeskActions;
use App\Filament\Support\WebhookDeliveryActions;
use App\Support\Http\SafeHttp;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * HROne parity SV-26 ("Logs" and "Failed deliveries" across webhooks): every delivery of every endpoint with its latency
 * and attempts; filters by webhook, event, status, acknowledgement and dates; replay or acknowledge one or many; the error
 * breakdown; and a queued CSV export. Read and acted on with webhook.manage only. Deliveries are written by Webhooks.
 */
class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Webhook deliveries';

    protected static ?string $modelLabel = 'webhook delivery';

    protected static ?string $pluralModelLabel = 'webhook deliveries';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('webhook.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('endpoint');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Time stamp')->dateTime('d M Y, H:i:s')->sortable(),
                TextColumn::make('endpoint.name')->label('Webhook')->placeholder('—'),
                TextColumn::make('destination')->label('Target URL')->state(fn (WebhookDelivery $record) => $record->endpoint ? SafeHttp::redact((string) $record->endpoint->url) : null)
                    ->placeholder('—')->toggleable(),
                TextColumn::make('event')->badge()->color('gray')->searchable(),
                ...WebhookDeliveryActions::outcomeColumns(),
                TextColumn::make('delivered_at')->dateTime()->placeholder('—')->toggleable(),
                WebhookDeliveryActions::acknowledgedColumn(),
                TextColumn::make('replay_count')->label('Replays')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('webhook_endpoint_id')->label('Webhook')->options(fn () => WebhookEndpoint::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('event')->options(fn () => collect(config('peopleos.enterprise.webhook_events', []))->prepend('ping')->mapWithKeys(fn ($e) => [$e => $e])->all())->searchable(),
                SelectFilter::make('status')->options(WebhookDelivery::STATUSES),
                TernaryFilter::make('acknowledged')->label('Acknowledged')->nullable()
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('acknowledged_at'), false: fn (Builder $q) => $q->whereNull('acknowledged_at'), blank: fn (Builder $q) => $q),
                Filter::make('dates')->columns(2)->schema([
                    DatePicker::make('from')->native(false),
                    DatePicker::make('until')->native(false),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
                    ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->where('created_at', '<=', Carbon::parse($until)->endOfDay()))),
            ])
            ->recordActions([
                WebhookDeliveryActions::details(),
                WebhookDeliveryActions::retry(),
                WebhookDeliveryActions::replay(),
                WebhookDeliveryActions::acknowledge(),
            ])
            ->toolbarActions([WebhookDeliveryActions::bulkReplay(), WebhookDeliveryActions::bulkAcknowledge()]);
    }

    /** SV-26 ("Export webhook log"): a queued CSV of the deliveries matching the chosen filters. */
    public static function exportAction(): Action
    {
        return Action::make('export')->label('Export CSV')->icon('heroicon-m-arrow-down-tray')->color('gray')
            ->visible(fn () => app(IntegrationLogExports::class)->mayExport('webhook_deliveries', auth()->user()))
            ->modalDescription('The file is prepared in the background; you are told when it is ready under Integrations → Log exports. Payloads are not included.')
            ->schema([
                DatePicker::make('from')->native(false)->default(now()->subDays(30)->toDateString()),
                DatePicker::make('until')->native(false),
                Select::make('webhook_endpoint_id')->label('Webhook')->options(fn () => WebhookEndpoint::query()->orderBy('name')->pluck('name', 'id')->all())->placeholder('Every webhook'),
                Select::make('status')->options(WebhookDelivery::STATUSES)->placeholder('Any status'),
                TextInput::make('event')->maxLength(64)->placeholder('Any event'),
            ])
            ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(IntegrationLogExports::class)->request('webhook_deliveries', $data, auth()->user()),
                'Export queued — you will be told when it is ready'));
    }

    public static function getPages(): array
    {
        return ['index' => ListWebhookDeliveries::route('/')];
    }
}
