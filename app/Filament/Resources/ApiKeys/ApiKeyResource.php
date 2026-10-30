<?php

namespace App\Filament\Resources\ApiKeys;

use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\ApiRequestLogs;
use App\Domain\Integration\Support\IpAllowlist;
use App\Filament\Pages\ApiReference;
use App\Filament\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Resources\ApiKeys\Pages\ViewApiKey;
use App\Filament\Resources\ApiKeys\RelationManagers\RequestLogsRelationManager;
use App\Filament\Resources\ApiKeys\RelationManagers\SecurityEventsRelationManager;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use RuntimeException;
use UnitEnum;

/**
 * Integration Hub credentials (§87, §109). The secret is shown exactly once.
 *
 * HROne parity CA-11 / SV-26: a description, a per-key IP allowlist and rate limit (both optional; empty = as before),
 * edit (audited with the reason), Regenerate (revoke and reissue in one audited step; the new secret shown once), and a
 * detail page with the key's API calls, its security events and a usage chart. api_key.view reads; api_key.manage acts.
 */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'API keys';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Basic info')->schema([
                TextInput::make('name')->required()->maxLength(255)->placeholder('Recruitment system'),
                TextInput::make('description')->maxLength(255)->placeholder('What the key is used for'),
                DatePicker::make('expires_at')->native(false)->placeholder('Never'),
            ]),
            Section::make('Scopes')->schema([
                CheckboxList::make('scopes')->options(config('peopleos.api.scopes'))->required()->columns(1)->searchable()->bulkToggleable(),
            ]),
            Section::make('Access limits')->schema([
                Textarea::make('allowed_ips')->label('Allowed IP addresses')->rows(3)
                    ->helperText('One per line (or comma separated): IPv4 / IPv6 addresses or CIDR blocks such as 203.0.113.0/24. Empty = any address. Calls from any other address are refused.')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode("\n", $state) : $state)
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        $entries = IpAllowlist::normalise(is_array($value) ? $value : (string) $value);
                        if (($invalid = IpAllowlist::invalid($entries)) !== []) {
                            $fail('Not an IP address or CIDR block: '.implode(', ', array_slice($invalid, 0, 5)).'.');
                        } elseif (count($entries) > IpAllowlist::MAX_ENTRIES) {
                            $fail('At most '.IpAllowlist::MAX_ENTRIES.' addresses or blocks.');
                        }
                    }),
                TextInput::make('rate_limit_per_minute')->label('Requests per minute')->integer()->minValue(1)->maxValue(ApiKeys::MAX_RATE_LIMIT)
                    ->placeholder(fn () => (string) config('peopleos.api.rate_limit_per_minute'))
                    ->helperText(fn () => 'Empty = the API-wide limit ('.config('peopleos.api.rate_limit_per_minute').' a minute).'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Overview')->columns(4)->schema([
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('prefix')->label('Key prefix')->fontFamily('mono'),
                TextEntry::make('status')->badge(),
                TextEntry::make('description')->placeholder('—'),
                TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('last_used_at')->label('Last used')->dateTime()->placeholder('Never'),
                TextEntry::make('expires_at')->label('Expires')->dateTime()->placeholder('Never'),
                TextEntry::make('regeneratedFrom.prefix')->label('Replaces key')->fontFamily('mono')->placeholder('—'),
            ]),
            Section::make('Auth')->columns(2)->schema([
                TextEntry::make('scopes')->badge()->separator(',')->columnSpanFull(),
                TextEntry::make('secret_note')->label('Secret')->state('Shown once, when the key was issued')
                    ->helperText('PeopleOS keeps only a hash. Regenerate to replace it: the current key stops working at once.'),
            ]),
            Section::make('IP addresses and rate limit')->columns(2)->schema([
                TextEntry::make('allowed_ips')->label('Allowed IP addresses')->state(fn (ApiKey $record) => $record->ipAllowlist() === [] ? null : $record->ipAllowlist())
                    ->placeholder('Any address')->badge()->color('gray'),
                TextEntry::make('rate_limit_per_minute')->label('Requests per minute')
                    ->state(fn (ApiKey $record) => $record->rateLimitPerMinute().(($record->getAttributes()['rate_limit_per_minute'] ?? null) === null ? ' (API-wide limit)' : ' (this key)')),
            ]),
            Section::make('Usage (last 30 days)')->columns(4)->schema([
                TextEntry::make('usage_calls')->label('API calls')->state(fn (ApiKey $record) => self::usage($record)['calls']),
                TextEntry::make('usage_errors')->label('Refused or failed')->state(fn (ApiKey $record) => self::usage($record)['errors']),
                TextEntry::make('usage_average')->label('Average response')->state(fn (ApiKey $record) => self::usage($record)['average_ms'] === null ? null : self::usage($record)['average_ms'].' ms')->placeholder('—'),
                TextEntry::make('usage_last')->label('Last call')->state(fn (ApiKey $record) => self::usage($record)['last_at'])->dateTime()->placeholder('Never'),
            ]),
        ]);
    }

    /** @return array{calls: int, errors: int, average_ms: int|null, last_at: ?string} */
    private static function usage(ApiKey $record): array
    {
        return app(ApiRequestLogs::class)->summary($record);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (ApiKey $record) => $record->getAttributes()['description'] ?? null),
                TextColumn::make('prefix')->fontFamily('mono'),
                TextColumn::make('scopes')->badge()->separator(','),
                TextColumn::make('allowed_ips')->label('IP addresses')->state(fn (ApiKey $record) => $record->ipAllowlist() === [] ? 'Any' : count($record->ipAllowlist()).' allowed')->toggleable(),
                TextColumn::make('last_used_at')->dateTime()->placeholder('Never'),
                TextColumn::make('expires_at')->dateTime()->placeholder('Never'),
                TextColumn::make('status')->badge(),
                TextColumn::make('creator.name')->label('Created by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (ApiKey $record) => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('open')->label('Details')->icon('heroicon-m-eye')->color('gray')->url(fn (ApiKey $record) => static::getUrl('view', ['record' => $record])),
                self::editAction(),
                self::regenerateAction(),
                self::revokeAction(),
            ]);
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->visible(fn (ApiKey $record) => $record->status->value === 'active' && (auth()->user()?->can('update', $record) ?? false))
            ->using(function (ApiKey $record, array $data) {
                $reason = AuditReasonField::extract($data);
                $data['expires_at'] = filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at'])->endOfDay() : null;
                try {
                    return app(ApiKeys::class)->update($record, $data, auth()->user(), $reason);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

                    return $record->refresh();
                }
            });
    }

    /** SV-26: revoke and reissue with the same name, scopes and limits; the new secret is shown once. */
    public static function regenerateAction(): Action
    {
        return Action::make('regenerate')->label('Regenerate')->icon('heroicon-m-arrow-path')->color('warning')
            ->visible(fn (ApiKey $record) => $record->isUsable() && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Regenerate this key?')
            ->modalDescription('The current key is revoked at once: systems still using it stop working until they get the new key. The new key keeps the name, scopes, expiry, IP addresses and rate limit, and is shown only once.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
            ->action(function (ApiKey $record, array $data, $livewire) {
                try {
                    $issued = app(ApiKeys::class)->regenerate($record, auth()->user(), (string) $data['reason']);
                } catch (RuntimeException $e) {
                    ServiceDeskActions::refuse($e->getMessage());

                    return;
                }
                self::showSecretOnce($issued['plaintext'], 'API key regenerated — copy the new key now, it will not be shown again');
                if ($livewire instanceof ViewApiKey) {
                    $livewire->redirect(static::getUrl('view', ['record' => $issued['key']]));
                }
            });
    }

    public static function revokeAction(): Action
    {
        return Action::make('revoke')->label('Revoke')->icon('heroicon-m-no-symbol')->color('danger')
            ->visible(fn (ApiKey $record) => $record->isUsable() && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->maxLength(255)])
            ->action(fn (ApiKey $record, array $data) => app(ApiKeys::class)->revoke($record, $data['reason'] ?? null));
    }

    public static function apiReferenceAction(): Action
    {
        return Action::make('apiReference')->label('API reference')->icon('heroicon-m-book-open')->color('gray')
            ->visible(fn () => ApiReference::canAccess())
            ->url(fn () => ApiReference::getUrl());
    }

    public static function getRelations(): array
    {
        return [RequestLogsRelationManager::class, SecurityEventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApiKeys::route('/'),
            'view' => ViewApiKey::route('/{record}'),
        ];
    }

    public static function issueAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Issue key')
            ->using(function (array $data) {
                try {
                    $issued = app(ApiKeys::class)->issue($data['name'], $data['scopes'], isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null, [
                        'description' => $data['description'] ?? null,
                        'allowed_ips' => $data['allowed_ips'] ?? null,
                        'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? null,
                    ]);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not issued')->body($e->getMessage())->send();

                    throw new Halt;
                }

                self::showSecretOnce($issued['plaintext'], 'API key issued — copy it now, it will not be shown again');

                return $issued['key'];
            });
    }

    private static function showSecretOnce(string $plaintext, string $title): void
    {
        Notification::make()
            ->success()
            ->title($title)
            ->body($plaintext)
            ->persistent()
            ->send();
    }
}
