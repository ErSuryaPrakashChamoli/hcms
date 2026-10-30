<?php

namespace App\Filament\Resources\SalaryImports;

use App\Domain\Compensation\Imports\SalaryImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use App\Filament\Resources\SalaryImports\Pages\ListSalaryImports;
use App\Filament\Resources\SalaryImports\Pages\ViewSalaryImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Salary uploads: the staging pipeline reused for employee compensation. A run prepares a draft "salary
 * upload" compensation cycle; pay changes only when that cycle is approved and executed. Each person
 * sees only the files they uploaded, because the rows hold pay.
 */
class SalaryImportResource extends Resource
{
    protected static ?string $model = EmployeeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Compensation';

    protected static ?string $navigationLabel = 'Salary uploads';

    protected static ?string $modelLabel = 'salary upload';

    protected static ?string $slug = 'salary-uploads';

    protected static ?int $navigationSort = 45;

    public static function canAccess(): bool
    {
        return SalaryImports::allowed(auth()->user());
    }

    public static function canCreate(): bool
    {
        return SalaryImports::allowed(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        // HROne setup parity P2: plus the uploads this person checks under maker-checker or workflow approval.
        return ImportGovernance::ownOrChecked(parent::getEloquentQuery()->where('type', SalaryImports::TYPE), SalaryImports::TYPE, auth()->user());
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
        return ['index' => ListSalaryImports::route('/'), 'view' => ViewSalaryImport::route('/{record}')];
    }
}
