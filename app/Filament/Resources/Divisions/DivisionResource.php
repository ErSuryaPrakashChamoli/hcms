<?php

namespace App\Filament\Resources\Divisions;

use App\Domain\Organisation\Models\Division;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Divisions\Pages\CreateDivision;
use App\Filament\Resources\Divisions\Pages\EditDivision;
use App\Filament\Resources\Divisions\Pages\ListDivisions;
use App\Filament\Resources\Divisions\Schemas\DivisionForm;
use App\Filament\Resources\Divisions\Tables\DivisionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DivisionResource extends Resource
{
    protected static ?string $model = Division::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'division';

    protected static ?string $pluralModelLabel = 'divisions';

    public static function form(Schema $schema): Schema
    {
        return DivisionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DivisionsTable::configure($table);
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
            'index' => ListDivisions::route('/'),
            'create' => CreateDivision::route('/create'),
            'edit' => EditDivision::route('/{record}/edit'),
        ];
    }
}
