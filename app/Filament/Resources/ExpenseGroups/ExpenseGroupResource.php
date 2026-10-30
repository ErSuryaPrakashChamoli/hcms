<?php

namespace App\Filament\Resources\ExpenseGroups;

use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\ExpenseGroups\Pages\ManageExpenseGroups;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** HROne setup parity EX-A (Expense → Expense group): headings expense categories are filed under. */
class ExpenseGroupResource extends Resource
{
    protected static ?string $model = ExpenseGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Expense groups';

    protected static ?string $modelLabel = 'expense group';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('expense.manage') || auth()->user()?->can('expense.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Expense group')->required()->maxLength(120),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
            TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Expense group')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('categories_count')->counts('categories')->label('Categories'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([
                EditAction::make()->using(function (ExpenseGroup $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseGroups::route('/')];
    }
}
