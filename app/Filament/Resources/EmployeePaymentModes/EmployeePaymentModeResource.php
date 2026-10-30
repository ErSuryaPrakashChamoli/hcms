<?php

namespace App\Filament\Resources\EmployeePaymentModes;

use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Filament\Resources\EmployeePaymentModes\Pages\ListEmployeePaymentModes;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** HROne setup parity BP-13 (pay modes): how employees are paid — bank transfer, cash or cheque — from a date. */
class EmployeePaymentModeResource extends Resource
{
    protected static ?string $model = EmployeePaymentMode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payment modes';

    protected static ?string $modelLabel = 'payment mode';

    protected static ?string $slug = 'payment-modes';

    protected static ?int $navigationSort = 78;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $mode = fn (?string $state) => $state === null ? 'As the salary' : (EmployeePaymentMode::MODES[$state] ?? $state);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'salaryAccount', 'reimbursementAccount', 'creator']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(['people.first_name', 'people.last_name'])->description(fn (EmployeePaymentMode $r) => $r->employee?->employee_code),
                TextColumn::make('effective_from')->label('From')->date()->sortable(),
                TextColumn::make('salary_mode')->label('Salary')->badge()->formatStateUsing(fn (?string $state) => $mode($state))
                    ->description(fn (EmployeePaymentMode $r) => $r->salaryAccount?->label()),
                TextColumn::make('reimbursement_mode')->label('Reimbursements')->state(fn (EmployeePaymentMode $r) => $r->reimbursement_mode)->formatStateUsing(fn (?string $state) => $mode($state))->placeholder('As the salary')
                    ->description(fn (EmployeePaymentMode $r) => $r->reimbursementAccount?->label()),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('creator.name')->label('Set by')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('salary_mode')->label('Salary paid by')->options(EmployeePaymentMode::MODES)])
            ->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeePaymentModes::route('/')];
    }
}
