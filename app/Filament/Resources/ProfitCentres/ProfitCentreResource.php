<?php

namespace App\Filament\Resources\ProfitCentres;

use App\Domain\Organisation\Models\ProfitCentre;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ProfitCentres\Pages\CreateProfitCentre;
use App\Filament\Resources\ProfitCentres\Pages\EditProfitCentre;
use App\Filament\Resources\ProfitCentres\Pages\ListProfitCentres;
use App\Filament\Resources\ProfitCentres\Schemas\ProfitCentreForm;
use App\Filament\Resources\ProfitCentres\Tables\ProfitCentresTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProfitCentreResource extends Resource
{
    protected static ?string $model = ProfitCentre::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'profit centre';

    protected static ?string $pluralModelLabel = 'profit centres';

    public static function form(Schema $schema): Schema
    {
        return ProfitCentreForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfitCentresTable::configure($table);
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
            'index' => ListProfitCentres::route('/'),
            'create' => CreateProfitCentre::route('/create'),
            'edit' => EditProfitCentre::route('/{record}/edit'),
        ];
    }
}
