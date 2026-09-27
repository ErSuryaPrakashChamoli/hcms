<?php

namespace App\Filament\Resources\TenantFeatures;

use App\Domain\Platform\Models\TenantFeature;
use App\Domain\Platform\Services\FeatureFlags;
use App\Filament\Resources\TenantFeatures\Pages\ManageTenantFeatures;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TenantFeatureResource extends Resource
{
    protected static ?string $model = TenantFeature::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Customisation';

    protected static ?string $navigationLabel = 'Feature flags';

    protected static ?string $modelLabel = 'feature flag';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('feature')->disabled()->dehydrated(false),
                Toggle::make('enabled')->required(),
                AuditReasonField::make(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feature')->searchable()->sortable(),
                TextColumn::make('description')
                    ->state(fn (TenantFeature $record) => config("peopleos.features.{$record->feature}.description"))
                    ->placeholder('—'),
                IconColumn::make('enabled')->boolean()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('feature')
            ->recordActions([
                EditAction::make()
                    ->using(function (TenantFeature $record, array $data) {
                        $record->withAuditReason(AuditReasonField::extract($data))->update($data);
                        app(FeatureFlags::class)->forget();

                        return $record;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTenantFeatures::route('/'),
        ];
    }
}
