<?php

namespace App\Filament\Resources\ExpenseAdvances;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use App\Filament\Resources\ExpenseAdvances\Pages\ViewExpenseAdvance;
use App\Filament\Resources\ExpenseAdvances\RelationManagers\EntriesRelationManager;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ExpenseRegisters;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity EX-D: expense advances — the employee's own under Me (My expense advances), everyone's in scope
 * under Expense & travel. Requested under the expense advance policy, approved (possibly for less), cancelled (an
 * approved one after approval when the policy says so), dispensed with the payment reference (`expense.pay`, also on
 * Dispense advances) or paid with payroll (EX-E), settled against approved expense reports oldest first, refunded,
 * written off, or recovered through payroll from its recovery date and at exit in the final settlement. The ledger is on
 * the advance's page.
 */
class ExpenseAdvanceResource extends Resource
{
    protected static ?string $model = ExpenseAdvance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $modelLabel = 'expense advance';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function oversees(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('expense.view') || $user->can('expense.approve') || $user->can('expense.pay'));
    }

    public static function getNavigationGroup(): ?string
    {
        return static::oversees() ? 'Expense & travel' : 'Me';
    }

    public static function getNavigationLabel(): string
    {
        return static::oversees() ? 'Expense advances' : 'My expense advances';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'decider']);

        return static::oversees() ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function infolist(Schema $schema): Schema
    {
        $base = fn () => app(CurrencyRates::class)->baseCurrency();

        return $schema->components([
            Section::make('Advance')->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseAdvance::STATUSES[$state] ?? $state),
                TextEntry::make('needed_on')->label('Needed on')->date()->placeholder('—'),
                TextEntry::make('amount')->label('Asked for')->formatStateUsing(fn (ExpenseAdvance $r) => $r->currency.' '.number_format((float) $r->amount, 2)),
                TextEntry::make('approved_amount')->label('Approved')->money($base)->placeholder('—'),
                TextEntry::make('outstanding')->money($base),
                TextEntry::make('settle_by')->label('Settle by')->date()->placeholder('—'),
                TextEntry::make('recovery_due_on')->label('Recovered through payroll from')->date()->placeholder('Not through payroll'),
                TextEntry::make('disbursement_channel')->label('Paid')->formatStateUsing(fn (string $state) => match ($state) {
                    'payroll' => 'With payroll', 'settlement' => 'In the full and final settlement', default => 'By bank',
                }),
                TextEntry::make('disbursed_on')->label('Dispensed on')->date()->placeholder('Not yet'),
                TextEntry::make('disbursement_reference')->label('Payment reference')->placeholder('—'),
                TextEntry::make('purpose')->columnSpanFull()->placeholder('—'),
                TextEntry::make('decision_note')->label('Decision note')->columnSpanFull()->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->columnSpanFull()->visible(fn (ExpenseAdvance $r) => filled($r->cancel_reason)),
                // HROne parity XO-1: a refused cancellation stays on record.
                TextEntry::make('cancel_refused_at')->label('Cancellation refused on')->dateTime()->visible(fn (ExpenseAdvance $r) => $r->cancel_refused_at !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (ExpenseAdvance $record, array $data = []) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };
        $advances = fn () => app(ExpenseAdvances::class);
        $can = fn (string $ability, string ...$statuses) => fn (ExpenseAdvance $r) => in_array($r->status, $statuses, true) && auth()->user()->can($ability, $r);

        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                // HROne parity XO-1 (WO-2): the employee by code, names, work e-mail or designation.
                TextColumn::make('employee.person.display_name')->label('Employee')->visible(fn () => static::oversees())
                    ->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('purpose')->limit(40)->placeholder('—'),
                TextColumn::make('amount_base')->label('Asked for')->numeric(2),
                TextColumn::make('approved_amount')->label('Approved')->numeric(2)->placeholder('—'),
                TextColumn::make('outstanding')->numeric(2),
                TextColumn::make('settle_by')->label('Settle by')->date()->placeholder('—'),
                TextColumn::make('files')->label('Files')->html()->toggleable()->state(fn (ExpenseAdvance $r) => new HtmlString(collect($r->attachments ?? [])->values()
                    ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('expense-advance', $r, $i)).'">'.e(basename($path)).'</a>')->implode('<br>')))->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ExpenseAdvance::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'settled', 'recovered' => 'success', 'approved', 'disbursed' => 'info', 'rejected', 'cancelled', 'written_off' => 'gray', default => 'warning',
                    }),
                // HROne parity XO-1 (matrix 26 §26.3.1 #2, #3): requested / decided dates, decided by and whom a waiting
                // advance waits with — for the HR side, hidden until chosen (the preset views show them).
                TextColumn::make('created_at')->label('Requested on')->date()->sortable()->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('waiting_with')->label('Waiting with')->state(fn (ExpenseAdvance $r) => ExpenseRegisters::advanceWaitingWith($r))->placeholder('—')->wrap()
                    ->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decided_at')->label('Decided on')->date()->sortable()->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_refused_at')->label('Cancellation refused on')->date()->placeholder('—')->visible(fn () => static::oversees())->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ExpenseAdvance::STATUSES)->multiple(),
                // HROne parity XO-1 (§26.3.1 #5, §26.3.3 #1): cancellations, requested / decided dates and the WO-2
                // employee filters, for the HR side only.
                ExpenseRegisters::cancellation(fn () => static::oversees()),
                ...ExpenseRegisters::filters(fn () => static::oversees(), ['request_dates' => ['Requested', 'created_at'], 'decision_dates' => ['Decided', 'decided_at']]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible($can('approve', 'requested'))
                    ->fillForm(fn (ExpenseAdvance $r) => ['approved_amount' => (float) $r->amount_base])
                    ->schema([TextInput::make('approved_amount')->label('Approve')->numeric()->minValue(0.01)->required(), Textarea::make('note')->maxLength(500)])
                    ->action($run(fn ($r, $d) => $advances()->approve($r, (float) $d['approved_amount'], $d['note'] ?? null), 'Advance approved')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible($can('approve', 'requested'))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => $advances()->reject($r, $d['note']), 'Advance rejected')),
                Action::make('approveCancellation')->label('Approve cancellation')->icon('heroicon-m-check')->color('success')->visible($can('approve', 'cancel_requested'))
                    ->schema([Textarea::make('note')->maxLength(500)])->action($run(fn ($r, $d) => $advances()->approveCancellation($r, $d['note'] ?? null), 'Advance cancelled')),
                Action::make('keepAdvance')->label('Keep advance')->icon('heroicon-m-x-mark')->color('gray')->visible($can('approve', 'cancel_requested'))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => $advances()->rejectCancellation($r, $d['note']), 'Cancellation refused: the advance stays approved')),
                static::dispenseAction(),
                Action::make('refund')->label('Record refund')->icon('heroicon-m-arrow-uturn-left')->color('gray')->visible($can('pay', 'disbursed'))
                    ->schema([TextInput::make('amount')->numeric()->minValue(0.01)->required(), TextInput::make('reference')->label('Payment reference')->required()->maxLength(120),
                        DatePicker::make('on')->label('Received on')->native(false)->default(today())->maxDate(today())->required()])
                    ->action($run(fn ($r, $d) => $advances()->refund($r, (float) $d['amount'], $d['reference'], $d['on']), 'Refund recorded')),
                Action::make('writeOff')->label('Write off')->icon('heroicon-m-archive-box-x-mark')->color('danger')->visible($can('pay', 'disbursed'))
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])->action($run(fn ($r, $d) => $advances()->writeOff($r, $d['reason']), 'Advance written off')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')->visible($can('update', 'requested', 'approved'))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(function (ExpenseAdvance $record, array $data) {
                        try {
                            $after = app(ExpenseAdvances::class)->cancel($record, $data['reason']);
                            Notification::make()->success()->title($after->status === 'cancelled' ? 'Advance cancelled' : 'Cancellation sent for approval')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /** Dispense an approved advance: the outside payment's reference and date (`expense.pay`, never one's own). */
    public static function dispenseAction(): Action
    {
        return Action::make('dispense')->label('Dispense')->icon('heroicon-m-banknotes')
            ->visible(fn (ExpenseAdvance $r) => $r->status === 'approved' && auth()->user()->can('pay', $r))
            ->schema([TextInput::make('reference')->label('Payment reference')->required()->maxLength(120), DatePicker::make('on')->label('Paid on')->native(false)->default(today())->maxDate(today())->required()])
            ->action(function (ExpenseAdvance $record, array $data) {
                try {
                    app(ExpenseAdvances::class)->dispense($record, $data['reference'], $data['on']);
                    Notification::make()->success()->title('Advance dispensed')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenseAdvances::route('/'), 'view' => ViewExpenseAdvance::route('/{record}')];
    }
}
