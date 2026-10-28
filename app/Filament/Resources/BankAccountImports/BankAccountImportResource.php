<?php

namespace App\Filament\Resources\BankAccountImports;

use App\Domain\Employment\Imports\BankAccountImports;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Filament\Resources\BankAccountImports\Pages\ListBankAccountImports;
use App\Filament\Resources\BankAccountImports\Pages\ViewBankAccountImport;
use App\Filament\Resources\EmployeeImports\EmployeeImportResource;
use App\Filament\Resources\EmployeeImports\RelationManagers\RowsRelationManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Bank details uploads: the staging pipeline reused for employees' salary accounts, each row written through
 * ChangeBankAccountAction. Each person sees only the files they uploaded, because the rows hold account numbers.
 */
class BankAccountImportResource extends Resource
{
    protected static ?string $model = EmployeeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Bank details uploads';

    protected static ?string $modelLabel = 'bank details upload';

    protected static ?string $slug = 'bank-uploads';

    protected static ?int $navigationSort = 31;

    public static function canAccess(): bool
    {
        return BankAccountImports::allowed(auth()->user());
    }

    public static function canCreate(): bool
    {
        return BankAccountImports::allowed(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', BankAccountImports::TYPE)->where('uploaded_by', auth()->id());
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
        return ['index' => ListBankAccountImports::route('/'), 'view' => ViewBankAccountImport::route('/{record}')];
    }
}
