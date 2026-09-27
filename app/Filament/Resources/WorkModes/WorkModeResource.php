<?php

namespace App\Filament\Resources\WorkModes;

use App\Domain\Organisation\Models\WorkMode;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\WorkModes\Pages\CreateWorkMode;
use App\Filament\Resources\WorkModes\Pages\EditWorkMode;
use App\Filament\Resources\WorkModes\Pages\ListWorkModes;
use App\Filament\Resources\WorkModes\Schemas\WorkModeForm;
use App\Filament\Resources\WorkModes\Tables\WorkModesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WorkModeResource extends Resource
{
    protected static ?string $model = WorkMode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'work mode';

    protected static ?string $pluralModelLabel = 'work modes';

    public static function form(Schema $schema): Schema
    {
        return WorkModeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkModesTable::configure($table);
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
            'index' => ListWorkModes::route('/'),
            'create' => CreateWorkMode::route('/create'),
            'edit' => EditWorkMode::route('/{record}/edit'),
        ];
    }
}
