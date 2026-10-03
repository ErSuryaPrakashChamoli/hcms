<?php

namespace App\Filament\Resources\SsoConnections;

use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Identity\Models\Role;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\SsoConnections\Pages\CreateSsoConnection;
use App\Filament\Resources\SsoConnections\Pages\EditSsoConnection;
use App\Filament\Resources\SsoConnections\Pages\ListSsoConnections;
use App\Filament\Support\AuditReasonField;
use App\Support\Validation\SafeOutboundUrl;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Single sign-on connections (§109). */
class SsoConnectionResource extends Resource
{
    protected static ?string $model = SsoConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Single sign-on';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('sso.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connection')->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->maxLength(64)->helperText('Login URL: /sso/{slug}/redirect'),
                Select::make('provider')->options(config('peopleos.enterprise.sso_providers'))->default('oidc')->required()->live()
                    ->afterStateUpdated(function (Set $set, ?string $state) {
                        $preset = config("peopleos.enterprise.sso_presets.{$state}");
                        if ($preset) {
                            $set('authorization_url', $preset['authorization']);
                            $set('token_url', $preset['token']);
                            $set('userinfo_url', $preset['userinfo']);
                            $set('scopes', $preset['scopes']);
                        }
                    }),
                TextInput::make('client_id')->required()->maxLength(255),
                TextInput::make('client_secret')->password()->revealable()->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->maxLength(2000),
                TextInput::make('scopes')->default('openid profile email')->required(),
                TextInput::make('authorization_url')->label('Authorization endpoint')->url()->required()->columnSpan(3)->helperText('Replace {tenant} / {domain} placeholders from the preset'),
                TextInput::make('token_url')->label('Token endpoint')->url()->rule(new SafeOutboundUrl)->required()->columnSpan(3),
                TextInput::make('userinfo_url')->label('Userinfo endpoint')->url()->rule(new SafeOutboundUrl)->required()->columnSpan(3),
            ]),
            Section::make('Provisioning & policy')->columns(3)->schema([
                TagsInput::make('allowed_domains')->placeholder('example.com')->helperText('Empty = any domain'),
                Toggle::make('auto_provision')->label('Create users on first login')->default(true)->inline(false),
                Select::make('default_role_id')->label('Role for new users')->placeholder('None')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
                Toggle::make('enforce')->label('Show SSO as the primary login for this tenant')->inline(false),
                Select::make('status')->options(['active' => 'Active', 'disabled' => 'Disabled'])->default('active')->required(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('provider')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.enterprise.sso_providers.{$state}", $state)),
                TextColumn::make('login_url')->label('Login URL')->state(fn (SsoConnection $record) => url('/sso/'.$record->slug.'/redirect'))->copyable(),
                TextColumn::make('allowed_domains')->label('Domains')->state(fn (SsoConnection $record) => implode(', ', $record->allowed_domains ?? []) ?: 'Any'),
                IconColumn::make('auto_provision')->label('Auto-provision')->boolean(),
                TextColumn::make('last_login_at')->dateTime()->placeholder('Never'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSsoConnections::route('/'),
            'create' => CreateSsoConnection::route('/create'),
            'edit' => EditSsoConnection::route('/{record}/edit'),
        ];
    }
}
