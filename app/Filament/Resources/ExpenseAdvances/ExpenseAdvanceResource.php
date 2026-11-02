<?php

namespace App\Filament\Resources\ExpenseAdvances;

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\CurrencyRates;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Filament\Pages\AdvanceLedger;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use App\Filament\Resources\ExpenseAdvances\Pages\ViewExpenseAdvance;
use App\Filament\Resources\ExpenseAdvances\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\AdvanceRecoveryActions;
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
use Filament\Schemas\Components\Wizard\Step;
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
 *
 * HROne parity XO-5 (matrix 26 §26.3.1 #9, §26.3.2 #7–#9): the page names the trip (linked where the viewer may open it),
 * who filed an advance requested on the employee's behalf, the dispense note and — with partial dispense on (D-XO8) — what
 * was paid out so far, what is left to pay out or what lapsed; a History tab (the audit trail, read-only, `audit.view`).
 * "Dispense" may carry a note and, with partial dispense on, an amount up to what is left; "Cancel the rest" lets the
 * rest of a partly dispensed advance lapse.
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
                // HROne parity XO-5: the trip, who filed it for the employee, the dispense note and partial payouts (D-XO8).
                TextEntry::make('trip')->label('Trip')->html()->visible(fn (ExpenseAdvance $r) => $r->travel_request_id !== null)
                    ->state(fn (ExpenseAdvance $r) => static::tripLink($r))->placeholder('—'),
                TextEntry::make('filed_by')->label('Requested by')->visible(fn (ExpenseAdvance $r) => (bool) $r->filed_on_behalf)
                    ->state(fn (ExpenseAdvance $r) => ($r->requester?->name ?? 'Someone else').', on the employee\'s behalf'),
                TextEntry::make('dispensed_so_far')->label('Paid out so far')->money($base)->visible(fn (ExpenseAdvance $r) => $r->dispensed_amount !== null)
                    ->state(fn (ExpenseAdvance $r) => $r->dispensedAmount()),
                TextEntry::make('left_to_dispense')->label('Left to pay out')->money($base)->visible(fn (ExpenseAdvance $r) => $r->remainingToDispense() > 0)
                    ->state(fn (ExpenseAdvance $r) => $r->remainingToDispense()),
                TextEntry::make('lapsed')->label('Lapsed, never paid out')->money($base)->visible(fn (ExpenseAdvance $r) => $r->lapsedAmount() > 0)
                    ->state(fn (ExpenseAdvance $r) => $r->lapsedAmount()),
                TextEntry::make('disbursement_note')->label('Dispense note')->columnSpanFull()->visible(fn (ExpenseAdvance $r) => filled($r->disbursement_note)),
                // HROne parity XO-6: recoveries scheduled through payroll (Advance recovery desk) and the employee's ledger.
                TextEntry::make('scheduled_recovery')->label('Recovery scheduled')->visible(fn (ExpenseAdvance $r) => static::scheduledRecovery($r) !== null)
                    ->state(fn (ExpenseAdvance $r) => static::scheduledRecovery($r)),
                TextEntry::make('employee_ledger')->label('Employee\'s advance ledger')->state('Open the ledger')->color('primary')
                    ->visible(fn (ExpenseAdvance $r) => $r->disbursed_on !== null)->url(fn (ExpenseAdvance $r) => AdvanceLedger::getUrl(['employee' => $r->employee_id])),
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
                // HROne parity XO-6 (§26.3.4 #9): how it was paid back (optional; blank = recorded as before), then the amount and reference.
                Action::make('refund')->label('Record refund')->icon('heroicon-m-arrow-uturn-left')->color('gray')->visible($can('pay', 'disbursed'))
                    ->steps([
                        Step::make('How it was paid back')->schema(AdvanceRecoveryActions::modeFields(false)),
                        Step::make('Amount and reference')->schema([TextInput::make('amount')->numeric()->minValue(0.01)->required(), ...AdvanceRecoveryActions::referenceFields()]),
                    ])
                    ->action($run(function ($r, $d) use ($advances) {
                        [$reference, $details] = AdvanceRecoveryActions::refundData($d);
                        $advances()->refund($r, (float) $d['amount'], $reference, $d['on'], null, $details);
                    }, 'Refund recorded')),
                Action::make('writeOff')->label('Write off')->icon('heroicon-m-archive-box-x-mark')->color('danger')->visible($can('pay', 'disbursed'))
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])->action($run(fn ($r, $d) => $advances()->writeOff($r, $d['reason']), 'Advance written off')),
                // HROne parity XO-5 (D-XO8): the rest of a partly dispensed advance, never paid out, lapses.
                Action::make('cancelRest')->label('Cancel the rest')->icon('heroicon-m-scissors')->color('gray')
                    ->visible(fn (ExpenseAdvance $r) => $r->remainingToDispense() > 0 && (auth()->user()->can('update', $r) || auth()->user()->can('pay', $r)))
                    ->modalDescription(fn (ExpenseAdvance $r) => number_format($r->remainingToDispense(), 2).' has not been paid out yet and will lapse. What was paid stays to be settled with expense reports, a refund or recovery.')
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action($run(fn ($r, $d) => $advances()->cancelRemainder($r, $d['reason']), 'The rest of the advance lapsed')),
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

    /**
     * Dispense an approved advance: the outside payment's reference and date (`expense.pay`, never one's own). HROne parity
     * XO-5: an optional note (§26.3.2 #9) and — with partial dispense on (D-XO8) — the amount paid out now, up to what is
     * left (a partly dispensed advance is offered again for the rest).
     */
    public static function dispenseAction(): Action
    {
        $partial = fn () => app(ExpenseAdvances::class)->partialDispense();
        $left = fn (ExpenseAdvance $r): float => $r->status === 'approved' ? round((float) $r->approved_amount, 2) : $r->remainingToDispense();

        return Action::make('dispense')->label(fn (ExpenseAdvance $r) => $r->status === 'disbursed' ? 'Pay out the rest' : 'Dispense')->icon('heroicon-m-banknotes')
            ->visible(fn (ExpenseAdvance $r) => ($r->status === 'approved' || ($r->remainingToDispense() > 0 && $partial())) && auth()->user()->can('pay', $r))
            ->fillForm(fn (ExpenseAdvance $record) => ['amount' => $left($record), 'on' => today()->toDateString()])
            ->schema(fn (ExpenseAdvance $record) => [
                TextInput::make('amount')->label('Amount paid out now')->numeric()->minValue(0.01)->maxValue($left($record))->required()->visible($partial())
                    ->helperText('Up to '.number_format($left($record), 2).'. The rest can be paid out later; what is never paid out lapses once the advance is settled.'),
                TextInput::make('reference')->label('Payment reference')->required()->maxLength(120),
                DatePicker::make('on')->label('Paid on')->native(false)->default(today())->maxDate(today())->required(),
                Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500),
            ])
            ->action(function (ExpenseAdvance $record, array $data) use ($partial) {
                try {
                    $amount = $partial() && filled($data['amount'] ?? null) ? (float) $data['amount'] : null;
                    app(ExpenseAdvances::class)->dispense($record, $data['reference'], $data['on'], null, $amount, $data['note'] ?? null);
                    Notification::make()->success()->title('Advance dispensed')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
                }
            });
    }

    /** HROne parity XO-6: the advance's open recoveries through payroll ("Nov 2026 · 5,000.00"), or null. */
    public static function scheduledRecovery(ExpenseAdvance $advance): ?string
    {
        $open = ExpenseAdvanceRecoverySchedule::query()->where('expense_advance_id', $advance->id)->where('status', 'scheduled')->orderBy('recover_in')->get();

        return $open->isEmpty() ? null : $open->map(fn (ExpenseAdvanceRecoverySchedule $s) => $s->monthLabel().' · '.number_format((float) $s->amount, 2))->implode('; ');
    }

    /** HROne parity XO-5 (§26.3.2 #7): the advance's trip, linked when the viewer may open it. */
    public static function tripLink(ExpenseAdvance $advance): ?HtmlString
    {
        $trip = $advance->travelRequest;
        if ($trip === null) {
            return null;
        }
        $label = e($trip->number.' · '.$trip->start_date?->format('d M Y').' – '.$trip->end_date?->format('d M Y'));

        return new HtmlString(auth()->user()?->can('view', $trip)
            ? '<a class="text-primary-600 underline" href="'.e(TravelRequestResource::getUrl('view', ['record' => $trip])).'">'.$label.'</a>'
            : $label);
    }

    public static function getRelations(): array
    {
        // HROne parity XO-5 (§26.3.1 #9, HROne's "View activity log"): the advance's own audit trail, read-only (audit.view).
        return [EntriesRelationManager::class, AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenseAdvances::route('/'), 'view' => ViewExpenseAdvance::route('/{record}')];
    }
}
