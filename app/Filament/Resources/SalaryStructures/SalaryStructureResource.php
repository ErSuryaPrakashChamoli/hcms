<?php

namespace App\Filament\Resources\SalaryStructures;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\SalaryStructures\Pages\CreateSalaryStructure;
use App\Filament\Resources\SalaryStructures\Pages\EditSalaryStructure;
use App\Filament\Resources\SalaryStructures\Pages\ListSalaryStructures;
use App\Filament\Resources\SalaryStructures\RelationManagers\ItemsRelationManager;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Salary structures (§30): which components, in which order, with optional formula overrides. */
class SalaryStructureResource extends Resource
{
    protected static ?string $model = SalaryStructure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Salary structures';

    protected static ?int $navigationSort = 41;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('items_count')->counts('items')->label('Components'),
                TextColumn::make('assignments_count')->counts('assignments')->label('Employees'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryStructures::route('/'),
            'create' => CreateSalaryStructure::route('/create'),
            'edit' => EditSalaryStructure::route('/{record}/edit'),
        ];
    }
}
