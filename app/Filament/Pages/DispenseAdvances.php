<?php

namespace App\Filament\Pages;

use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne setup parity EX-D (Expense → Dispense advance): approved expense advances waiting to be paid, for `expense.pay`
 * holders to record the payment made outside PeopleOS (reference and date). Dispensing posts the ledger and starts the
 * settle-by clock. EX-E: advances whose policy pays them with payroll are paid by the next payroll unless dispensed here
 * first; once a payroll run not yet finalized carries one, it is refused here. One's own advances are never dispensed here.
 *
 * HROne parity XO-5 (matrix 26 §26.3.2 #1, #2, #5, #7–#9; §26.9 #2): two tabs (`?tab=`) — "To dispense" (as before, plus
 * the trip and the employee's outstanding across their dispensed advances) and "Dispensed" (dispensed, settled, recovered
 * or written off: approved, paid out, outstanding, dispensed on, how, reference, note, settle by) with status,
 * dispense-date and requested-date filters; dispensing may carry a note and, with partial dispense on (owner decision
 * D-XO8), an amount up to what is left (a partly dispensed advance stays under "To dispense" for the rest). The navigation
 * badge counts the advances waiting to be dispensed that the signed-in person may dispense (in scope, never their own).
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

    /** HROne parity XO-5: the page's tabs ("To dispense" is the page as it was). */
    public const TABS = ['to_dispense' => 'To dispense', 'dispensed' => 'Dispensed'];

    /** What the "Dispensed" tab lists: every advance paid out (outside payroll, with payroll or in the F&F). */
    public const DISPENSED = ['disbursed', 'settled', 'recovered', 'written_off'];

    #[Url(as: 'tab')]
    public string $dispenseTab = 'to_dispense';

    public function getSubheading(): ?string
    {
        return 'Approved expense advances waiting to be paid. Record each payment made outside payroll.';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('expense.pay');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** HROne parity XO-5 (§26.9 #2): advances waiting to be dispensed by the signed-in person — in scope, never their own. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if ($user === null || ! static::canAccess()) {
            return null;
        }
        $count = static::awaiting()->whereNotIn('expense_advances.employee_id', fn ($q) => $q->select('id')->from('employees')->where('user_id', $user->getKey()))->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Approved advances (and, with partial dispense on, dispensed ones with something left to pay out), in the access scope. */
    public static function awaiting(): Builder
    {
        $partial = app(ExpenseAdvances::class)->partialDispense();

        return ExpenseAdvance::query()->where(fn (Builder $q) => $q->where('expense_advances.status', 'approved')
            ->when($partial, fn (Builder $w) => $w->orWhere(fn (Builder $p) => $p->where('expense_advances.status', 'disbursed')
                ->whereNotNull('expense_advances.dispensed_amount')->whereNull('expense_advances.remainder_lapsed_at')
                ->whereColumn('expense_advances.dispensed_amount', '<', 'expense_advances.approved_amount'))));
    }

    /** @return array<string, string> */
    public function tabsFor(): array
    {
        return self::TABS;
    }

    public function tab(): string
    {
        return array_key_exists($this->dispenseTab, self::TABS) ? $this->dispenseTab : 'to_dispense';
    }

    /** Each tab is its own address (`?tab=`), so its table and filters are built afresh. */
    public function tabUrl(string $tab): string
    {
        return static::getUrl($tab === 'to_dispense' ? [] : ['tab' => $tab]);
    }

    public function table(Table $table): Table
    {
        return $this->tab() === 'dispensed' ? $this->dispensedTable($table) : $this->toDispenseTable($table);
    }

    private function toDispenseTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::withEmployeeOutstanding(static::awaiting()->with(['employee.person', 'travelRequest'])))
            ->columns([
                TextColumn::make('number')->searchable()->url(fn (ExpenseAdvance $r) => ExpenseAdvanceResource::getUrl('view', ['record' => $r])),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('purpose')->limit(40)->placeholder('—'),
                TextColumn::make('trip')->label('Trip')->html()->state(fn (ExpenseAdvance $r) => ExpenseAdvanceResource::tripLink($r))->placeholder('—'),
                TextColumn::make('needed_on')->label('Needed on')->date()->placeholder('—'),
                TextColumn::make('approved_amount')->label('To pay')->numeric(2)
                    ->state(fn (ExpenseAdvance $r) => $r->status === 'approved' ? (float) $r->approved_amount : $r->remainingToDispense())
                    ->description(fn (ExpenseAdvance $r) => $r->status === 'disbursed' ? number_format((float) $r->dispensed_amount, 2).' of '.number_format((float) $r->approved_amount, 2).' paid out' : null),
                TextColumn::make('employee_outstanding')->label('Employee\'s outstanding')->numeric(2)
                    ->tooltip('What the employee already owes across their dispensed advances')
                    // HROne parity XO-6 (§26.3.2 #5, "View ledger"): the employee's advance ledger.
                    ->url(fn (ExpenseAdvance $r) => AdvanceLedger::getUrl(['employee' => $r->employee_id])),
                TextColumn::make('disbursement_channel')->label('Policy says')->formatStateUsing(fn (string $state) => $state === 'payroll' ? 'With payroll' : 'By bank'),
                TextColumn::make('decided_at')->label('Approved')->date(),
            ])
            ->filters([
                EmployeeFilters::dateRange('requested', 'Requested', 'created_at'),
            ])
            ->defaultSort('decided_at')
            ->recordActions([ExpenseAdvanceResource::dispenseAction()]);
    }

    private function dispensedTable(Table $table): Table
    {
        $channel = fn (?string $state) => match ($state) {
            'payroll' => 'With payroll', 'settlement' => 'In the full and final settlement', default => 'By bank',
        };

        return $table
            ->query(fn (): Builder => static::withEmployeeOutstanding(ExpenseAdvance::query()->with(['employee.person', 'travelRequest'])
                ->whereIn('expense_advances.status', self::DISPENSED)->whereNotNull('expense_advances.disbursed_on')))
            ->columns([
                TextColumn::make('number')->searchable()->url(fn (ExpenseAdvance $r) => ExpenseAdvanceResource::getUrl('view', ['record' => $r])),
                TextColumn::make('employee.person.display_name')->label('Employee'),
                TextColumn::make('purpose')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('trip')->label('Trip')->html()->state(fn (ExpenseAdvance $r) => ExpenseAdvanceResource::tripLink($r))->placeholder('—'),
                TextColumn::make('created_at')->label('Requested on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_amount')->label('Approved')->numeric(2),
                TextColumn::make('paid_out')->label('Paid out')->numeric(2)->state(fn (ExpenseAdvance $r) => $r->dispensedAmount())
                    ->description(fn (ExpenseAdvance $r) => match (true) {
                        $r->remainingToDispense() > 0 => number_format($r->remainingToDispense(), 2).' left to pay out',
                        $r->lapsedAmount() > 0 => number_format($r->lapsedAmount(), 2).' lapsed',
                        default => null,
                    }),
                TextColumn::make('outstanding')->numeric(2),
                TextColumn::make('employee_outstanding')->label('Employee\'s outstanding')->numeric(2)
                    ->tooltip('What the employee owes across all their dispensed advances')
                    ->url(fn (ExpenseAdvance $r) => AdvanceLedger::getUrl(['employee' => $r->employee_id])),
                TextColumn::make('disbursed_on')->label('Dispensed on')->date()->sortable(),
                TextColumn::make('disbursement_channel')->label('How')->formatStateUsing(fn (?string $state) => $channel($state)),
                TextColumn::make('disbursement_reference')->label('Reference')->placeholder('—'),
                TextColumn::make('disbursement_note')->label('Note')->limit(40)->placeholder('—'),
                TextColumn::make('settle_by')->label('Settle by')->date()->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseAdvance::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'settled', 'recovered' => 'success', 'disbursed' => 'info', default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->multiple()->options(array_intersect_key(ExpenseAdvance::STATUSES, array_flip(self::DISPENSED))),
                EmployeeFilters::dateRange('dispensed', 'Dispensed', 'disbursed_on'),
                EmployeeFilters::dateRange('requested', 'Requested', 'created_at'),
            ])
            ->defaultSort('disbursed_on', 'desc')
            ->emptyStateHeading('Nothing dispensed yet')
            ->emptyStateDescription('Advances paid out outside payroll, with payroll or in the full and final settlement appear here.')
            ->recordActions([ExpenseAdvanceResource::dispenseAction()]);
    }

    /** HROne parity XO-5 (§26.3.2 #5): what the employee owes across their dispensed advances, on each row. */
    private static function withEmployeeOutstanding(Builder $query): Builder
    {
        return $query->addSelect(['employee_outstanding' => DB::table('expense_advances as eao')->selectRaw('COALESCE(SUM(eao.outstanding), 0)')
            ->whereColumn('eao.employee_id', 'expense_advances.employee_id')->where('eao.status', 'disbursed')]);
    }
}
