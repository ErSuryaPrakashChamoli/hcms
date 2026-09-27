<?php

namespace App\Filament\Resources\PunchImports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use App\Filament\Resources\PunchImports\Pages\ListPunchImports;
use App\Filament\Resources\PunchImports\Pages\ViewPunchImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Raw punch imports (Phase 2 §32): the staging pipeline reused for attendance evidence. */
class PunchImportResource extends Resource
{
    protected static ?string $model = EmployeeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Punch imports';

    protected static ?string $slug = 'punch-imports';

    protected static ?int $navigationSort = 13;

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('attendance.manage') ?? false) && (auth()->user()?->can('employee.import') ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', 'punches');
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeImportResource::infolist($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeImportResource::table($table);
    }

    public static function getRelations(): array
    {
        return [RowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPunchImports::route('/'), 'view' => ViewPunchImport::route('/{record}')];
    }
}
