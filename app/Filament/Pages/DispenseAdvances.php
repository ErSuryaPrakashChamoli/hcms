<?php

namespace App\Filament\Pages;

use App\Domain\Expense\Models\ExpenseAdvance;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity EX-D (Expense → Dispense advance): approved expense advances waiting to be paid, for `expense.pay`
 * holders to record the payment made outside PeopleOS (reference and date). Dispensing posts the ledger and starts the
 * settle-by clock. EX-E: advances whose policy pays them with payroll are paid by the next payroll unless dispensed here
 * first; once a payroll run not yet finalized carries one, it is refused here. One's own advances are never dispensed here.
 */
class DispenseAdvances extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Dispense advances';

    protected static ?string $title = 'Dispense advances';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.dispense-advances';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('expense.pay');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ExpenseAdvance::query()->with('employee.person')->where('status', 'approved'))
            ->columns([
                TextColumn::make('number')->searchable()->url(fn (ExpenseAdvance $r) => ExpenseAdvanceResource::getUrl('view', ['record' => $r])),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('purpose')->limit(40)->placeholder('—'),
                TextColumn::make('needed_on')->label('Needed on')->date()->placeholder('—'),
                TextColumn::make('approved_amount')->label('To pay')->numeric(2),
                TextColumn::make('disbursement_channel')->label('Policy says')->formatStateUsing(fn (string $state) => $state === 'payroll' ? 'With payroll' : 'By bank'),
                TextColumn::make('decided_at')->label('Approved')->date(),
            ])
            ->defaultSort('decided_at')
            ->recordActions([ExpenseAdvanceResource::dispenseAction()]);
    }
}
