<?php

namespace App\Filament\Resources\TimeOfficeImports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use App\Filament\Resources\TimeOfficeImports\Pages\ListTimeOfficeImports;
use App\Filament\Resources\TimeOfficeImports\Pages\ViewTimeOfficeImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity HP-1b: time-office and leave uploads (leave balances and history, shift roster, geo locations,
 * approved overtime, day overrides) on the staged pipeline. Each person sees the upload types they may run.
 */
class TimeOfficeImportResource extends Resource
{
    protected static ?string $model = EmployeeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Time office uploads';

    protected static ?string $modelLabel = 'time office upload';

    protected static ?string $slug = 'time-office-uploads';

    protected static ?int $navigationSort = 92;

    /** The upload screen's types (RowImportTypes area). */
    public static function area(): string
    {
        return 'time_office';
    }

    public static function uploadHeading(): string
    {
        return 'Upload a time office file';
    }

    public static function canAccess(): bool
    {
        return RowImportTypes::allowedTypes(auth()->user(), static::area()) !== [];
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('type', RowImportTypes::allowedTypes(auth()->user(), static::area()));
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
        return ['index' => ListTimeOfficeImports::route('/'), 'view' => ViewTimeOfficeImport::route('/{record}')];
    }
}
