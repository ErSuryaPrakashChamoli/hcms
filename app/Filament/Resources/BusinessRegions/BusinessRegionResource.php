<?php

namespace App\Filament\Resources\BusinessRegions;

use App\Domain\Organisation\Models\BusinessRegion;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\BusinessRegions\Pages\CreateBusinessRegion;
use App\Filament\Resources\BusinessRegions\Pages\EditBusinessRegion;
use App\Filament\Resources\BusinessRegions\Pages\ListBusinessRegions;
use App\Filament\Resources\BusinessRegions\Schemas\BusinessRegionForm;
use App\Filament\Resources\BusinessRegions\Tables\BusinessRegionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Data import DI-1 (Core → Business regions): business regions that group branches. */
class BusinessRegionResource extends Resource
{
    protected static ?string $model = BusinessRegion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?int $navigationSort = 23;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'business region';

    protected static ?string $pluralModelLabel = 'business regions';

    public static function form(Schema $schema): Schema
    {
        return BusinessRegionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BusinessRegionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusinessRegions::route('/'),
            'create' => CreateBusinessRegion::route('/create'),
            'edit' => EditBusinessRegion::route('/{record}/edit'),
        ];
    }
}
