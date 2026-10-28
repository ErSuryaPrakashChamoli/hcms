<?php

namespace App\Filament\Resources\AttendanceSheetImports;

use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Filament\Resources\AttendanceSheetImports\Pages\ListAttendanceSheetImports;
use App\Filament\Resources\AttendanceSheetImports\Pages\ViewAttendanceSheetImport;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use App\Filament\Support\ImportActions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Monthly attendance uploads: for organisations without a biometric device, a month's attendance as one row per
 * employee and a day code per day. Running one stores marks the attendance engine applies; it never writes days.
 */
class AttendanceSheetImportResource extends Resource
{
    protected static ?string $model = EmployeeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Attendance';

    protected static ?string $navigationLabel = 'Monthly attendance uploads';

    protected static ?string $modelLabel = 'monthly attendance upload';

    protected static ?string $slug = 'attendance-uploads';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return AttendanceSheetImports::allowed(auth()->user());
    }

    public static function canCreate(): bool
    {
        return AttendanceSheetImports::allowed(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', AttendanceSheetImports::TYPE);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeImportResource::infolist($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeImportResource::table($table)
            ->pushColumns([TextColumn::make('month')->label('Month')->state(fn (EmployeeImport $record) => ImportActions::monthLabel($record))]);
    }

    public static function getRelations(): array
    {
        return [RowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListAttendanceSheetImports::route('/'), 'view' => ViewAttendanceSheetImport::route('/{record}')];
    }
}
