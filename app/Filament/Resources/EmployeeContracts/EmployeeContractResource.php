<?php

namespace App\Filament\Resources\EmployeeContracts;

use App\Domain\Employment\Models\EmployeeContract;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\EmployeeContracts\Pages\CreateEmployeeContract;
use App\Filament\Resources\EmployeeContracts\Pages\EditEmployeeContract;
use App\Filament\Resources\EmployeeContracts\Pages\ListEmployeeContracts;
use App\Filament\Resources\EmployeeContracts\Schemas\EmployeeContractForm;
use App\Filament\Resources\EmployeeContracts\Tables\EmployeeContractsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Workforce → Contract: the contracts of contract staff (agency, number, dates), written through EmployeeContracts. */
class EmployeeContractResource extends Resource
{
    protected static ?string $model = EmployeeContract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Contracts';

    protected static ?int $navigationSort = 46;

    protected static ?string $recordTitleAttribute = 'contract_number';

    protected static ?string $modelLabel = 'contract';

    protected static ?string $pluralModelLabel = 'contracts';

    protected static ?string $slug = 'employee-contracts';

    public static function form(Schema $schema): Schema
    {
        return EmployeeContractForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeContractsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeContracts::route('/'),
            'create' => CreateEmployeeContract::route('/create'),
            'edit' => EditEmployeeContract::route('/{record}/edit'),
        ];
    }
}
