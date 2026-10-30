<?php

namespace App\Filament\Resources\Loans;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanGuarantor;
use App\Domain\Loans\Services\Loans;
use App\Filament\Resources\Loans\Pages\ListLoans;
use App\Filament\Resources\Loans\Pages\ViewLoan;
use App\Filament\Resources\Loans\RelationManagers\ScheduleRelationManager;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\SendBackActions;
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
use RuntimeException;
use UnitEnum;

/** HROne parity PY-01: loans and salary advances — requests, approval, payout, schedule and recovery. */
class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Loans & advances';

    protected static ?string $modelLabel = 'loan';

    protected static ?int $navigationSort = 70;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('payroll.manage') ? 'Payroll' : 'Me';
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('payroll.manage')) {
            return null;
        }
        $count = Loan::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    /** Without payroll.manage a person only ever sees their own loans (server-side). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'reviewer']);

        return auth()->user()?->can('payroll.manage') ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Loan')->columns(3)->schema([
                TextEntry::make('number'),
                TextEntry::make('employee.person.display_name')->label('Employee'),
                TextEntry::make('loan_type')->label('Type')->formatStateUsing(fn (Loan $record) => $record->typeLabel()),
                TextEntry::make('principal')->money(fn () => config('peopleos.settings.tenant.base_currency', 'INR')),
                TextEntry::make('interest_rate')->suffix('% a year'),
                TextEntry::make('installments')->label('Instalments'),
                TextEntry::make('emi')->label('Monthly recovery')->money(fn () => config('peopleos.settings.tenant.base_currency', 'INR')),
                TextEntry::make('first_due_month')->label('Recovery from'),
                TextEntry::make('outstanding')->money(fn () => config('peopleos.settings.tenant.base_currency', 'INR')),
                TextEntry::make('disbursed_through')->label('Paid out by')->formatStateUsing(fn (string $state) => $state === 'bank' ? 'Bank' : 'Payroll'),
                TextEntry::make('disbursed_on')->date()->placeholder('Not yet'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => Loan::STATUSES[$state] ?? $state),
                TextEntry::make('guarantor.person.display_name')->label('Guarantor')->placeholder('—'),
                TextEntry::make('reason')->columnSpan(2),
                // HROne setup parity BP-9: limits the request is beyond, what was asked when the approver changed it, every guarantor.
                TextEntry::make('exceptions')->label('Beyond the loan policy')->state(fn (Loan $record) => $record->exceptionSummary())->placeholder('—')->columnSpan(3)
                    ->visible(fn (Loan $record) => $record->exceptionSummary() !== null),
                TextEntry::make('requested_terms')->label('Asked for')->columnSpan(3)->visible(fn (Loan $record) => ! empty($record->requested_terms))
                    ->state(fn (Loan $record) => number_format((float) ($record->requested_terms['principal'] ?? 0), 2).' in '.($record->requested_terms['installments'] ?? '?').' instalment(s) from '.($record->requested_terms['first_due_month'] ?? '?').' at '.($record->requested_terms['interest_rate'] ?? 0).'% a year'),
                TextEntry::make('guarantors')->label('Guarantors')->columnSpan(3)->visible(fn (Loan $record) => $record->guarantors()->exists())
                    ->state(fn (Loan $record) => $record->guarantors()->with('employee.person')->get()->map(fn ($g) => ($g->employee?->person?->full_name ?? '—').' ('.strtolower(LoanGuarantor::STATUSES[$g->status] ?? $g->status).')')->implode(', ')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (Loan $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };
        $loans = fn () => app(Loans::class);

        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('loan_type')->label('Type')->badge()->formatStateUsing(fn (Loan $record) => $record->typeLabel()),
                TextColumn::make('principal')->numeric(2)->sortable(),
                TextColumn::make('emi')->label('Monthly')->numeric(2),
                TextColumn::make('installments')->label('Instalments'),
                TextColumn::make('outstanding')->numeric(2),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Loan::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'info', 'closed' => 'success', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(Loan::STATUSES)->multiple(), SelectFilter::make('loan_type')->label('Type')->options(fn () => config('peopleos.loans.types')),
                // HROne parity PO-1: pay group, requested dates and the WO-2 employee filters (payroll side only).
                PayrollRegisters::structure('payroll.manage'),
                ...PayrollRegisters::filters('payroll.manage', ['request_dates' => ['Requested', 'created_at']]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                // HROne setup parity BP-9: the loan policy may let the approver change the amount, instalments, first month and interest.
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible(fn (Loan $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->fillForm(fn (Loan $r) => ['principal' => (float) $r->principal, 'installments' => (int) $r->installments, 'first_due_month' => $r->first_due_month, 'interest_rate' => (float) $r->interest_rate])
                    ->schema(fn (Loan $r) => [
                        ...(self::policySays($r, 'approver_may_edit') ? [
                            TextInput::make('principal')->label('Amount')->numeric()->minValue(1)->required(),
                            TextInput::make('installments')->label('Instalments')->numeric()->minValue(1)->required(),
                            TextInput::make('first_due_month')->label('Recovery from (YYYY-MM)')->regex('/^\d{4}-\d{2}$/')->required(),
                        ] : []),
                        ...(self::policySays($r, 'interest_editable') ? [TextInput::make('interest_rate')->label('Interest a year (%)')->numeric()->minValue(0)->maxValue(100)->required()] : []),
                        Textarea::make('note')->maxLength(500),
                    ])
                    ->action($run(fn ($r, $d) => $loans()->approve($r, $d['note'] ?? null, null, array_intersect_key($d, array_flip(['principal', 'installments', 'first_due_month', 'interest_rate']))), 'Loan approved')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible(fn (Loan $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => $loans()->reject($r, $d['note']), 'Loan rejected')),
                Action::make('paidOut')->label('Mark paid out')->icon('heroicon-m-banknotes')->visible(fn (Loan $r) => $r->status === 'approved' && auth()->user()->can('update', $r))
                    ->schema([DatePicker::make('on')->native(false)->required()->default(today())])->action($run(fn ($r, $d) => $loans()->markDisbursed($r, $d['on'], 'Paid out outside payroll'), 'Loan active')),
                Action::make('prepay')->icon('heroicon-m-forward')->visible(fn (Loan $r) => $r->status === 'active' && auth()->user()->can('update', $r))
                    ->schema([TextInput::make('count')->label('Instalments paid now')->numeric()->minValue(1)->required(), Textarea::make('note')->required()->maxLength(255)])
                    ->action($run(fn ($r, $d) => $loans()->prepay($r, (int) $d['count'], $d['note']), 'Prepayment recorded')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (Loan $r) => in_array($r->status, ['pending', 'approved'], true) && (auth()->user()->can('update', $r) || ($r->status === 'pending' && Employee::query()->whereKey($r->employee_id)->value('user_id') === auth()->id())))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])->action($run(fn ($r, $d) => $loans()->cancel($r, $d['reason']), 'Loan cancelled')),
                // HROne setup parity P3: a request sent back to its requester is resubmitted here.
                SendBackActions::resubmit(),
            ]);
    }

    /** Whether the borrower's loan policy answers yes to a question (approver edits, interest edits). */
    public static function policySays(Loan $loan, string $key): bool
    {
        $employee = Employee::query()->withoutGlobalScope(AccessScope::class)->find($loan->employee_id);
        $value = $employee ? app(PolicyResolver::class)->resolve('loan', $employee, now())?->setting($key) : null;

        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    public static function getRelations(): array
    {
        return [ScheduleRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListLoans::route('/'), 'view' => ViewLoan::route('/{record}')];
    }
}
