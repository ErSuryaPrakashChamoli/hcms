<?php

namespace App\Filament\Resources\TenantSettings;

use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\TenantSettings\Pages\ManageTenantSettings;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TenantSettingResource extends Resource
{
    protected static ?string $model = TenantSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Customisation';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $modelLabel = 'setting';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[a-z0-9_.]+$/')
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated()
                    ->helperText('Dot-separated, e.g. branding.display_name'),
                TextInput::make('value')
                    ->required()
                    ->formatStateUsing(fn ($state) => is_scalar($state) || $state === null ? $state : json_encode($state))
                    ->dehydrateStateUsing(fn ($state) => self::decode($state)),
                AuditReasonField::make(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->searchable()->sortable(),
                TextColumn::make('value')->formatStateUsing(fn ($state) => is_scalar($state) ? (string) $state : json_encode($state))->wrap(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('key')
            ->recordActions([
                EditAction::make()
                    ->using(function (TenantSetting $record, array $data) {
                        $record->withAuditReason(AuditReasonField::extract($data))->update($data);
                        app(SettingsRepository::class)->forget();

                        return $record;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTenantSettings::route('/'),
        ];
    }

    private static function decode(mixed $state): mixed
    {
        if (! is_string($state)) {
            return $state;
        }

        $decoded = json_decode($state, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $state;
    }
}
