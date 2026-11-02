<?php

namespace App\Filament\Resources\DimensionValues;

use App\Domain\Organisation\Models\DimensionValue;
use App\Domain\Organisation\Support\DimensionLabel;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\DimensionValues\Pages\CreateDimensionValue;
use App\Filament\Resources\DimensionValues\Pages\EditDimensionValue;
use App\Filament\Resources\DimensionValues\Pages\ListDimensionValues;
use App\Filament\Resources\DimensionValues\Schemas\DimensionValueForm;
use App\Filament\Resources\DimensionValues\Tables\DimensionValuesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Data import DI-1 (Core → Dimension): the values of the organisation's own extra dimension (verticals, practices …).
 * They carry JV codes, and an employee's position may name one (decision D1); the organisation names the dimension (D5).
 */
class DimensionValueResource extends Resource
{
    protected static ?string $model = DimensionValue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 92;

    protected static ?string $recordTitleAttribute = 'name';

    /** Decision D5: the screen carries the organisation's own name for its dimension (Setup → Core HR). */
    public static function getNavigationLabel(): string
    {
        return DimensionLabel::name();
    }

    public static function getModelLabel(): string
    {
        return DimensionLabel::valueLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return Str::plural(DimensionLabel::valueLabel());
    }

    public static function form(Schema $schema): Schema
    {
        return DimensionValueForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DimensionValuesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDimensionValues::route('/'),
            'create' => CreateDimensionValue::route('/create'),
            'edit' => EditDimensionValue::route('/{record}/edit'),
        ];
    }
}
