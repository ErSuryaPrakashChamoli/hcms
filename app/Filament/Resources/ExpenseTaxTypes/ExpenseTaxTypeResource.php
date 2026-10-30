<?php

namespace App\Filament\Resources\ExpenseTaxTypes;

use App\Domain\Expense\Models\ExpenseTaxType;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\ExpenseTaxTypes\Pages\ManageExpenseTaxTypes;
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

/**
 * HROne setup parity EX-A (Expense → Tax): "Tax types need to be created so that user can submit tax bifurcation at time
 * of expense submission." Tenant data, separate from the platform's billing tax.
 */
class ExpenseTaxTypeResource extends Resource
{
    protected static ?string $model = ExpenseTaxType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Tax';

    protected static ?string $modelLabel = 'expense tax type';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('expense.manage') || auth()->user()?->can('expense.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Tax name')->required()->maxLength(60),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated()
                ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
            TextInput::make('default_rate')->label('Usual rate (%)')->numeric()->minValue(0)->maxValue(100)
                ->helperText('Optional: suggested on receipts. The receipt records the tax actually charged.'),
            TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Tax heads an employee splits a receipt\'s tax into. Inactive ones stay on earlier receipts.')
            ->columns([
                TextColumn::make('name')->label('Tax name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('default_rate')->label('Usual rate (%)')->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('id')->label('Tax')->multiple()->options(fn () => ExpenseTaxType::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(ActiveStatus::class),
            ])
            ->recordActions([
                EditAction::make()->using(function (ExpenseTaxType $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageExpenseTaxTypes::route('/')];
    }
}
