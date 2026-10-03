<?php

namespace App\Filament\Resources\IntegrationSystems;

use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\IntegrationSystems;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\IntegrationSystems\Pages\CreateIntegrationSystem;
use App\Filament\Resources\IntegrationSystems\Pages\EditIntegrationSystem;
use App\Filament\Resources\IntegrationSystems\Pages\ListIntegrationSystems;
use App\Filament\Resources\IntegrationSystems\RelationManagers\InboundEventsRelationManager;
use App\Filament\Resources\IntegrationSystems\RelationManagers\MappingsRelationManager;
use App\Filament\Resources\IntegrationSystems\RelationManagers\ReferencesRelationManager;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/**
 * Phase 14 Integration Hub: external systems a tenant integrates with, each with its own inbound
 * signing secret (shown once, rotatable), an optional bound API key and accepted event types, plus
 * its external references, mappings and inbound events.
 */
class IntegrationSystemResource extends Resource
{
    protected static ?string $model = IntegrationSystem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Integration hub';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Integration')->columns(2)->schema([
                TextInput::make('code')->required()->maxLength(40)->disabledOn('edit')->helperText('Used in the inbound URL: /api/v1/integrations/{code}/events'),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('kind')->options(config('peopleos.integration.kinds'))->default('other')->required()->disabledOn('edit'),
                Select::make('status')->options(['active' => 'Active', 'paused' => 'Paused', 'retired' => 'Retired'])->default('active')->visibleOn('edit'),
            ]),
            Section::make('Inbound security')->columns(2)->schema([
                Toggle::make('require_signature')->label('Require signed events (HMAC-SHA256 over "timestamp.body")')->default(true),
                TextInput::make('signature_tolerance_seconds')->label('Timestamp window (seconds)')->numeric()->minValue(30)->maxValue(900)->default(300),
                Select::make('api_key_id')->label('Only this API key may post (optional)')->placeholder('Any key with integrations.write')
                    ->options(fn () => ApiKey::query()->orderBy('name')->get()->mapWithKeys(fn (ApiKey $k) => [$k->id => $k->auditLabel()])->all()),
                TagsInput::make('allowed_event_types')->label('Accepted event types (empty = every registered handler)')
                    ->suggestions(array_keys(config('peopleos.integration.handlers', []))),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.integration.kinds.{$state}", $state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                IconColumn::make('require_signature')->label('Signed')->boolean(),
                TextColumn::make('secret_rotated_at')->label('Secret rotated')->since(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('rotate')->label('Rotate secret')->icon('heroicon-m-key')->color('warning')->requiresConfirmation()
                    ->modalDescription('The current secret stops working at once. The new one is shown a single time.')
                    ->visible(fn () => auth()->user()->can('integration.manage'))
                    ->action(function (IntegrationSystem $record) {
                        try {
                            $secret = app(IntegrationSystems::class)->rotateSecret($record, auth()->user());
                            Notification::make()->success()->title('New signing secret (copy it now; it is not shown again)')->body($secret)->persistent()->send();
                        } catch (RuntimeException $e) {
                            ServiceDeskActions::refuse($e->getMessage());
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [InboundEventsRelationManager::class, ReferencesRelationManager::class, MappingsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListIntegrationSystems::route('/'), 'create' => CreateIntegrationSystem::route('/create'), 'edit' => EditIntegrationSystem::route('/{record}/edit')];
    }
}
