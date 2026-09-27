<?php

namespace App\Filament\Resources\CostCentres;

use App\Domain\Organisation\Models\CostCentre;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\CostCentres\Pages\CreateCostCentre;
use App\Filament\Resources\CostCentres\Pages\EditCostCentre;
use App\Filament\Resources\CostCentres\Pages\ListCostCentres;
use App\Filament\Resources\CostCentres\Schemas\CostCentreForm;
use App\Filament\Resources\CostCentres\Tables\CostCentresTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CostCentreResource extends Resource
{
    protected static ?string $model = CostCentre::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'cost centre';

    protected static ?string $pluralModelLabel = 'cost centres';

    public static function form(Schema $schema): Schema
    {
        return CostCentreForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostCentresTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AuditHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostCentres::route('/'),
            'create' => CreateCostCentre::route('/create'),
            'edit' => EditCostCentre::route('/{record}/edit'),
        ];
    }
}
