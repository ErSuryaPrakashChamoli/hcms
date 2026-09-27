<?php

namespace App\Filament\Resources\EmploymentTypes;

use App\Domain\Organisation\Models\EmploymentType;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\EmploymentTypes\Pages\CreateEmploymentType;
use App\Filament\Resources\EmploymentTypes\Pages\EditEmploymentType;
use App\Filament\Resources\EmploymentTypes\Pages\ListEmploymentTypes;
use App\Filament\Resources\EmploymentTypes\Schemas\EmploymentTypeForm;
use App\Filament\Resources\EmploymentTypes\Tables\EmploymentTypesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EmploymentTypeResource extends Resource
{
    protected static ?string $model = EmploymentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'employment type';

    protected static ?string $pluralModelLabel = 'employment types';

    public static function form(Schema $schema): Schema
    {
        return EmploymentTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmploymentTypesTable::configure($table);
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
            'index' => ListEmploymentTypes::route('/'),
            'create' => CreateEmploymentType::route('/create'),
            'edit' => EditEmploymentType::route('/{record}/edit'),
        ];
    }
}
