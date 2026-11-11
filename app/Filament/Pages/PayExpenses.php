<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\ExpenseRegisters;
use App\Filament\Support\ExpenseReview;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use App\Support\Csv\SpreadsheetCell;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * HROne setup parity EX-D (Expense → Pay expenses): approved expense reports with an amount still owed to the employee,
 * for `expense.pay` holders to record the outside payment (reference and date) or — EX-E, the payment step — to pay
 * them with payroll (the next payroll repays them and marks them paid when it is finalized). A report a payroll run
 * not yet finalized or an unpaid final settlement already carries is paid by it. One's own reports are never paid here.
 *
 * HROne parity XO-4 (matrix 26 §26.4.4 #2, #3, #5, #6, #9–#12, #16, #18, #19): three tabs — "To pay" (one row per report,
 * as before), "To pay, by employee" (one row per employee: the sum of the employee's reports matching the filters; paying
 * the row pays each of them) and "Paid" (channel, date, reference, mode, paying account, cheque, note); employee code,
 * reimbursable and non-reimbursable (information only — never paid to the employee) columns; payment status, period,
 * exceptional and WO-2 organisation / employee filters; saved views (a view keeps its tab), a report link and an audited
 * CSV. "Pay with payroll" may name the payroll month (owner decision D-XO1; blank = the next payroll, as before); a
 * scheduled report may move to another month or leave payroll until a run carries it. "Record payment" may add the mode
 * (bank transfer, cheque with number and date, cash), the company's paying account and a note.
 */
class PayExpenses extends RegisterTablePage
{
    use HasRegisterTools {
        applyRegisterView as applyRegisterToolsView;
        currentRegisterState as registerToolsState;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Pay expenses';

    protected static ?string $title = 'Pay expenses';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.pay-expenses';

    public const REGISTER = 'pay_expenses';

    /** HROne parity XO-4: the page's tabs ("To pay" is the page as it was). */
    public const TABS = ['reports' => 'To pay', 'employees' => 'To pay, by employee', 'paid' => 'Paid'];

    #[Url(as: 'tab')]
    public string $payTab = 'reports';

    /** The search travels in the URL, so an employee row's "Reports" opens "To pay" searched for that employee. */
    #[Url(as: 'search')]
    public $tableSearch = '';

    /** @var array<int, array<string, string>> employee id => open payroll months (this request) */
    private array $monthsFor = [];

    /** @var array<int, string>|null company bank account id => label (this request) */
    private ?array $accountLabels = null;

    /** @var array<int, bool> report id => its chosen month was finalized without it (this request) */
    private array $missed = [];

    public function getSubheading(): ?string
    {
        return 'Approved expense reports still owed to employees. Record each payment made outside payroll.';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('expense.pay');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function presetViews(): array
    {
        return [
            'waiting' => ['label' => 'Waiting for payment', 'lens' => 'reports', 'filters' => ['payment' => ['values' => ['waiting']]]],
            'scheduled' => ['label' => 'Scheduled for payroll', 'lens' => 'reports', 'filters' => ['payment' => ['values' => ['scheduled']]]],
            'exceptional' => ['label' => 'Exceptional reports to pay', 'lens' => 'reports', 'filters' => ['exceptional' => ['isActive' => true]]],
            'by_employee' => ['label' => 'To pay, by employee', 'lens' => 'employees'],
            'paid' => ['label' => 'Paid', 'lens' => 'paid'],
        ];
    }

    public static function registerReports(): array
    {
        return ['expense_payment_register'];
    }

    // --- Tabs ----------------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    public function tabsFor(): array
    {
        return self::TABS;
    }

    public function tab(): string
    {
        return array_key_exists($this->payTab, self::TABS) ? $this->payTab : 'reports';
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS) || $tab === $this->tab()) {
            return;
        }
        $this->switchTab($tab);
        $this->tableSort = null;
        $this->resetPage();
    }

    /** The filters are the same on every tab, so they stay; the columns, actions and sort follow the tab. */
    private function switchTab(string $tab): void
    {
        $this->payTab = $tab;
        $this->rebuildRegisterTable();
        $this->deselectAllTableRecords();
    }

    /** A saved view keeps the tab (as its "lens"). */
    public function currentRegisterState(): array
    {
        return ['lens' => $this->tab()] + $this->registerToolsState();
    }

    /** @param  array<string, mixed>  $view  a view without a tab keeps the tab shown */
    public function applyRegisterView(array $view, ?string $label = null): void
    {
        $tab = is_string($view['lens'] ?? null) && array_key_exists($view['lens'], self::TABS) ? $view['lens'] : $this->tab();
        if ($tab !== $this->tab()) {
            $this->switchTab($tab);
        }
        $this->applyRegisterToolsView($view, $label);
    }

    // --- The register --------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        $table = $table
            ->filters($this->filters())
            ->paginated([25, 50, 100])
            ->emptyStateHeading($this->tab() === 'paid' ? 'No paid reports' : 'Nothing to pay')
            ->emptyStateDescription($this->tab() === 'paid'
                ? 'Reports paid outside payroll, with payroll or in the full and final settlement appear here.'
                : 'Approved expense reports with an amount owed to the employee appear here.');

        return match ($this->tab()) {
            'employees' => $this->employeesTable($table),
            'paid' => $this->paidTable($table),
            default => $this->reportsTable($table),
        };
    }

    /** Approved (fully or partly), with money owed to the employee, not yet paid: what Pay expenses pays (EX-D). */
    public static function payable(): Builder
    {
        return ExpenseReport::query()->whereIn('expense_reports.status', ['approved', 'partly_approved'])->where('expense_reports.net_payable', '>', 0)->whereNull('expense_reports.paid_on');
    }

    private function reportsTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::payable()->with('employee.person'))
            ->columns([
                TextColumn::make('number')->searchable(query: ExpenseReview::numberSearch())->url(fn (ExpenseReport $r) => ExpenseReportResource::getUrl('view', ['record' => $r])),
                TextColumn::make('employee.employee_code')->label('Employee code'),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('title')->limit(40),
                TextColumn::make('decided_at')->label('Approved')->date(),
                TextColumn::make('total_approved')->label('Approved amount')->numeric(2),
                TextColumn::make('reimbursable_approved')->label('Reimbursable')->numeric(2),
                TextColumn::make('non_reimbursable')->label('Non-reimbursable')->numeric(2)->tooltip('Paid by the company: shown for information, never paid to the employee')
                    ->state(fn (ExpenseReport $r) => round((float) $r->total_approved - (float) $r->reimbursable_approved, 2)),
                TextColumn::make('advance_adjusted')->label('Advances settled')->numeric(2),
                TextColumn::make('net_payable')->label('To pay')->numeric(2),
                TextColumn::make('payment')->label('Payment')->badge()->state(fn (ExpenseReport $r) => $r->paymentStatus())
                    ->description(fn (ExpenseReport $r) => $this->scheduleNote($r))
                    ->color(fn (ExpenseReport $r) => match (true) {
                        $this->missed($r) => 'danger',
                        $r->isScheduledForPayroll() => 'info',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('decided_at')
            ->recordActions([
                Action::make('payWithPayroll')->label('Pay with payroll')->icon('heroicon-m-calendar-days')->color('gray')
                    ->modalHeading('Pay with payroll')
                    ->modalDescription(fn (ExpenseReport $record) => 'Adds '.$record->currency.' '.number_format((float) $record->net_payable, 2).' to the payroll of the month you choose (blank: the next payroll), repaid non-taxable; the report is marked paid when that payroll is finalized.')
                    ->schema(fn (ExpenseReport $record) => $this->scheduleFields($this->monthsFor((int) $record->employee_id)))
                    ->visible(fn (ExpenseReport $r) => ! $r->isScheduledForPayroll() && auth()->user()->can('pay', $r))
                    ->action(function (ExpenseReport $record, array $data) {
                        try {
                            app(ExpenseReports::class)->scheduleForPayroll($record, null, $data['month'] ?? null, $data['note'] ?? null);
                            Notification::make()->success()->title('Scheduled for payroll')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not scheduled')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('changePayMonth')->label('Change pay month')->icon('heroicon-m-arrow-path')->color('gray')
                    ->modalDescription('Only the payroll of the month chosen repays it (blank: the next payroll). Not possible once a payroll run carries it.')
                    ->schema(fn (ExpenseReport $record) => $this->scheduleFields($this->monthsFor((int) $record->employee_id)))
                    ->fillForm(fn (ExpenseReport $record) => ['month' => $record->payMonth()])
                    ->visible(fn (ExpenseReport $r) => $r->isScheduledForPayroll() && auth()->user()->can('pay', $r))
                    ->action(function (ExpenseReport $record, array $data) {
                        try {
                            app(ExpenseReports::class)->reschedulePayroll($record, $data['month'] ?? null, null, $data['note'] ?? null);
                            Notification::make()->success()->title('Pay month changed')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('cancelPayrollSchedule')->label('Take off payroll')->icon('heroicon-m-x-circle')->color('gray')
                    ->modalDescription('The report goes back to waiting for payment here. Not possible once a payroll run carries it.')
                    ->schema([Textarea::make('reason')->label('Why (optional)')->rows(2)->maxLength(500)])
                    ->visible(fn (ExpenseReport $r) => $r->isScheduledForPayroll() && auth()->user()->can('pay', $r))
                    ->action(function (ExpenseReport $record, array $data) {
                        try {
                            app(ExpenseReports::class)->cancelPayrollSchedule($record, $data['reason'] ?? null);
                            Notification::make()->success()->title('Taken off payroll')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('pay')->label('Record payment')->icon('heroicon-m-banknotes')->visible(fn (ExpenseReport $r) => auth()->user()->can('pay', $r))
                    ->steps(fn (ExpenseReport $record) => $this->paymentSteps((int) $record->employee_id))
                    ->action(function (ExpenseReport $record, array $data) {
                        try {
                            app(ExpenseReports::class)->markPaid($record, $data['reference'], $data['paid_on'], null, self::paymentData($data));
                            Notification::make()->success()->title('Payment recorded')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('payAllWithPayroll')->label('Pay the selected with payroll')->icon('heroicon-m-calendar-days')
                    ->modalDescription('The payroll of the month you choose (blank: the next payroll) repays each report; the month must be open for each employee\'s company.')
                    ->schema(fn () => $this->scheduleFields(app(PayrollMonths::class)->upcoming()))
                    ->action(fn (Collection $records, array $data) => $this->scheduleEach($records, $data)),
                BulkAction::make('payAll')->label('Record one payment for the selected')->icon('heroicon-m-banknotes')
                    ->steps(fn () => $this->paymentSteps(null))
                    ->action(fn (Collection $records, array $data) => $this->payEach($records, $data)),
            ]);
    }

    /** One row per employee: the employee's payable reports matching the filters, summed (the row's key is the first report). */
    private function employeesTable(Table $table): Table
    {
        $employee = fn (ExpenseReport $r): int => (int) $r->getAttributes()['employee_id'];

        return $table
            ->query(fn (): Builder => static::payable()->with('employee.person')->select([
                'expense_reports.employee_id',
                DB::raw('MIN(expense_reports.id) as id'),
                DB::raw('COUNT(*) as reports_count'),
                DB::raw("SUM(CASE WHEN expense_reports.payment_channel = 'payroll' THEN 1 ELSE 0 END) as scheduled_count"),
                DB::raw('SUM(expense_reports.total_approved) as sum_approved'),
                DB::raw('SUM(expense_reports.reimbursable_approved) as sum_reimbursable'),
                DB::raw('SUM(expense_reports.total_approved - expense_reports.reimbursable_approved) as sum_non_reimbursable'),
                DB::raw('SUM(expense_reports.advance_adjusted) as sum_advances'),
                DB::raw('SUM(expense_reports.net_payable) as sum_to_pay'),
                DB::raw('MIN(expense_reports.decided_at) as first_decided_at'),
            ])->groupBy('expense_reports.employee_id'))
            ->columns([
                TextColumn::make('employee.employee_code')->label('Employee code'),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('reports_count')->label('Reports')->numeric(),
                TextColumn::make('first_decided_at')->label('Oldest approval')->date()->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('first_decided_at', $direction)),
                TextColumn::make('sum_approved')->label('Approved amount')->numeric(2),
                TextColumn::make('sum_reimbursable')->label('Reimbursable')->numeric(2),
                TextColumn::make('sum_non_reimbursable')->label('Non-reimbursable')->numeric(2)->tooltip('Paid by the company: shown for information, never paid to the employee'),
                TextColumn::make('sum_advances')->label('Advances settled')->numeric(2),
                TextColumn::make('sum_to_pay')->label('To pay')->numeric(2)->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('sum_to_pay', $direction)),
                TextColumn::make('payment')->label('Payment')->badge()
                    ->state(fn (ExpenseReport $r) => self::employeePayment((int) $r->getAttributes()['reports_count'], (int) $r->getAttributes()['scheduled_count']))
                    ->color(fn (ExpenseReport $r) => (int) $r->getAttributes()['scheduled_count'] > 0 ? 'info' : 'warning'),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('first_decided_at')->orderBy('expense_reports.employee_id'))
            ->defaultKeySort(false)
            ->selectCurrentPageOnly()
            ->recordActions([
                Action::make('openReports')->label('Reports')->icon('heroicon-m-queue-list')->color('gray')
                    ->url(fn (ExpenseReport $record) => static::getUrl(['tab' => 'reports', 'search' => $record->employee?->employee_code])),
                Action::make('payEmployeeWithPayroll')->label('Pay with payroll')->icon('heroicon-m-calendar-days')->color('gray')
                    ->modalHeading('Pay with payroll')
                    ->modalDescription('Each of the employee\'s reports listed here and not yet scheduled is added to the payroll of the month you choose (blank: the next payroll); each is marked paid when that payroll is finalized.')
                    ->schema(fn (ExpenseReport $record) => $this->scheduleFields($this->monthsFor($employee($record))))
                    ->visible(fn (ExpenseReport $r) => (int) $r->getAttributes()['scheduled_count'] < (int) $r->getAttributes()['reports_count'] && auth()->user()->can('pay', $r))
                    ->action(fn (ExpenseReport $record, array $data) => $this->scheduleEach($this->employeeReports([$employee($record)]), $data)),
                Action::make('payEmployee')->label('Record payment')->icon('heroicon-m-banknotes')
                    ->visible(fn (ExpenseReport $r) => auth()->user()->can('pay', $r))
                    ->steps(fn (ExpenseReport $record) => $this->paymentSteps($employee($record)))
                    ->action(fn (ExpenseReport $record, array $data) => $this->payEach($this->employeeReports([$employee($record)]), $data)),
            ])
            ->toolbarActions([
                BulkAction::make('payEmployeesWithPayroll')->label('Pay the selected with payroll')->icon('heroicon-m-calendar-days')
                    ->modalDescription('Each report of the selected employees listed here and not yet scheduled is added to the payroll of the month you choose (blank: the next payroll).')
                    ->schema(fn () => $this->scheduleFields(app(PayrollMonths::class)->upcoming()))
                    ->action(fn (Collection $records, array $data) => $this->scheduleEach($this->employeeReports($records->map($employee)->all()), $data)),
                BulkAction::make('payEmployees')->label('Record one payment for the selected')->icon('heroicon-m-banknotes')
                    ->steps(fn () => $this->paymentSteps(null))
                    ->action(fn (Collection $records, array $data) => $this->payEach($this->employeeReports($records->map($employee)->all()), $data)),
            ]);
    }

    private function paidTable(Table $table): Table
    {
        $nonReimbursable = fn (ExpenseReport $r) => round((float) $r->total_approved - (float) $r->reimbursable_approved, 2);

        return $table
            ->query(fn (): Builder => ExpenseReport::query()->with(['employee.person', 'payer'])->whereNotNull('expense_reports.paid_on'))
            ->columns([
                TextColumn::make('number')->searchable(query: ExpenseReview::numberSearch())->url(fn (ExpenseReport $r) => ExpenseReportResource::getUrl('view', ['record' => $r])),
                TextColumn::make('employee.employee_code')->label('Employee code'),
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('title')->limit(40)->toggleable(),
                TextColumn::make('reimbursable_approved')->label('Reimbursable')->numeric(2),
                TextColumn::make('non_reimbursable')->label('Non-reimbursable')->numeric(2)->tooltip('Paid by the company: shown for information, never paid to the employee')
                    ->state($nonReimbursable)->toggleable(),
                TextColumn::make('advance_adjusted')->label('Advances settled')->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_payable')->label('Paid amount')->numeric(2),
                TextColumn::make('paid_on')->label('Paid on')->date()->sortable(),
                TextColumn::make('channel')->label('How')->state(fn (ExpenseReport $r) => ExpenseReport::CHANNELS[$r->payment_channel ?? 'bank'] ?? $r->payment_channel)
                    ->description(fn (ExpenseReport $r) => $r->paymentModeLabel()),
                TextColumn::make('payment_reference')->label('Reference')->placeholder('—'),
                TextColumn::make('paying_account')->label('Paying account')->placeholder('—')->state(fn (ExpenseReport $r) => $this->accountLabel($r->paid_from_account_id)),
                TextColumn::make('pay_month')->label('Pay month')->placeholder('—')->toggleable()
                    ->state(fn (ExpenseReport $r) => $r->payment_channel === 'payroll' ? ($r->payment_scheduled_for?->format('M Y') ?? $r->payment_reference) : null),
                TextColumn::make('payment_note')->label('Payment note')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('payer.name')->label('Recorded by')->placeholder('Payroll / settlement')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('paid_on', 'desc');
    }

    /** @return list<BaseFilter> the same on every tab, so a saved view applies to each */
    private function filters(): array
    {
        $hr = fn () => true;

        return [
            ExpenseReview::paymentFilter($hr),
            ExpenseReview::periodFilter($hr),
            // HROne "Exceptional Report": reports with an exceptional part (an expense filed as exceptional).
            Filter::make('exceptional')->label('Exceptional reports')->toggle()
                ->query(fn (Builder $query) => $query->whereHas('receipts', fn (Builder $r) => $r->where($r->getModel()->qualifyColumn('is_exceptional'), true))),
            ...ExpenseRegisters::filters($hr, ['decided' => ['Approved', 'expense_reports.decided_at'], 'paid' => ['Paid on', 'expense_reports.paid_on']]),
        ];
    }

    // --- Payments ------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, string>  $months  "Y-m" => label
     * @return list<Field>
     */
    private function scheduleFields(array $months): array
    {
        return [
            Select::make('month')->label('Pay month')->options($months)->placeholder('Next payroll')->native(false)
                ->helperText('Blank: the next payroll, as before. Only an open month whose payroll is not finalized can be chosen.'),
            Textarea::make('note')->label('Payment note (optional)')->rows(2)->maxLength(500),
        ];
    }

    /** "Record payment": how it was paid, then the reference — two steps. The paying accounts are the employee's company's (all, for several). */
    private function paymentSteps(?int $employeeId): array
    {
        return [
            Step::make('How it was paid')->schema([
                Select::make('mode')->label('Payment mode')->options(ExpenseReport::PAYMENT_MODES)->placeholder('Not recorded')->native(false)->live(),
                Select::make('account_id')->label('Paying account')->placeholder('Not recorded')->native(false)
                    ->options(fn () => app(PayrollMonths::class)->payingAccounts($employeeId))
                    ->visible(fn (Get $get) => in_array($get('mode'), ['bank_transfer', 'cheque'], true)),
                TextInput::make('cheque_number')->label('Cheque number')->maxLength(30)->required()->visible(fn (Get $get) => $get('mode') === 'cheque'),
                DatePicker::make('cheque_date')->label('Cheque date')->native(false)->required()->visible(fn (Get $get) => $get('mode') === 'cheque'),
            ]),
            Step::make('Reference')->schema([
                TextInput::make('reference')->label('Payment reference')->required()->maxLength(120),
                DatePicker::make('paid_on')->label('Paid on')->native(false)->default(today())->maxDate(today())->required(),
                Textarea::make('note')->label('Payment note (optional)')->rows(2)->maxLength(500),
            ]),
        ];
    }

    /** @return array{mode: ?string, account_id: mixed, cheque_number: mixed, cheque_date: mixed, note: ?string} */
    private static function paymentData(array $data): array
    {
        $mode = filled($data['mode'] ?? null) ? (string) $data['mode'] : null;

        return [
            'mode' => $mode,
            'account_id' => in_array($mode, ['bank_transfer', 'cheque'], true) ? ($data['account_id'] ?? null) : null,
            'cheque_number' => $mode === 'cheque' ? ($data['cheque_number'] ?? null) : null,
            'cheque_date' => $mode === 'cheque' ? ($data['cheque_date'] ?? null) : null,
            'note' => $data['note'] ?? null,
        ];
    }

    /** Schedules each report not yet scheduled; refusals are listed. */
    private function scheduleEach(iterable $reports, array $data): void
    {
        $scheduled = 0;
        $skipped = 0;
        $refused = [];
        foreach ($reports as $report) {
            if ($report->isScheduledForPayroll()) {
                $skipped++;

                continue;
            }
            try {
                app(ExpenseReports::class)->scheduleForPayroll($report, null, $data['month'] ?? null, $data['note'] ?? null);
                $scheduled++;
            } catch (RuntimeException $e) {
                $refused[] = $report->number.': '.$e->getMessage();
            }
        }
        $body = array_values(array_filter([$skipped > 0 ? "{$skipped} already scheduled." : null, ...$refused]));
        Notification::make()->{$refused === [] ? 'success' : 'warning'}()->title("{$scheduled} report(s) scheduled for payroll")->body($body === [] ? null : implode(' ', $body))->send();
    }

    /** Records one outside payment for each report; refusals are listed. */
    private function payEach(iterable $reports, array $data): void
    {
        $paid = 0;
        $refused = [];
        foreach ($reports as $report) {
            try {
                app(ExpenseReports::class)->markPaid($report, $data['reference'], $data['paid_on'], null, self::paymentData($data));
                $paid++;
            } catch (RuntimeException $e) {
                $refused[] = $report->number.': '.$e->getMessage();
            }
        }
        Notification::make()->{$refused === [] ? 'success' : 'warning'}()->title("{$paid} report(s) marked paid")->body($refused === [] ? null : implode(' ', $refused))->send();
    }

    /**
     * The payable reports of these employees that the register's filters and search keep — what their rows add up.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, ExpenseReport>
     */
    public function employeeReports(array $employeeIds): Collection
    {
        $query = static::payable()->whereIn('expense_reports.employee_id', $employeeIds === [] ? [0] : $employeeIds);
        $this->applyFiltersToTableQuery($query);
        $this->applySearchToTableQuery($query);

        return $query->orderBy('expense_reports.employee_id')->orderBy('expense_reports.id')->get();
    }

    // --- Helpers -------------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    private function monthsFor(int $employeeId): array
    {
        return $this->monthsFor[$employeeId] ??= app(PayrollMonths::class)->openFor($employeeId);
    }

    private function missed(ExpenseReport $report): bool
    {
        return $this->missed[(int) $report->id] ??= app(ExpenseReports::class)->payMonthMissed($report);
    }

    /** Under the payment status: the month chosen ("Dec 2026 payroll"), or "since …" for the next payroll (as before). */
    private function scheduleNote(ExpenseReport $report): ?string
    {
        if (! $report->isScheduledForPayroll()) {
            return null;
        }
        if ($report->payment_scheduled_for === null) {
            return 'since '.$report->payment_scheduled_at?->format('d M Y');
        }

        return $report->payment_scheduled_for->format('M Y').' payroll'.($this->missed($report) ? ' — finalized without it: change the pay month' : '');
    }

    private static function employeePayment(int $reports, int $scheduled): string
    {
        $waiting = $reports - $scheduled;

        return implode(' · ', array_filter([
            $waiting > 0 ? "{$waiting} waiting for payment" : null,
            $scheduled > 0 ? "{$scheduled} scheduled for payroll" : null,
        ]));
    }

    private function accountLabel(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $this->accountLabels ??= CompanyBankAccount::query()->get()->mapWithKeys(fn (CompanyBankAccount $a) => [(int) $a->id => $a->label()])->all();

        return $this->accountLabels[(int) $id] ?? null;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('downloadCsv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->downloadCsv()),
        ];
    }

    /** HROne's export: the tab as CSV, with its filters and search (expense.pay; audited; formula-injection safe). */
    public function downloadCsv(): StreamedResponse
    {
        $user = auth()->user();
        abort_unless(static::canAccess(), 403);
        $rows = $this->getFilteredSortedTableQuery()->limit(BulkRuns::maxRecords())->get();
        $nonReimbursable = fn (ExpenseReport $r) => round((float) $r->total_approved - (float) $r->reimbursable_approved, 2);

        $out = fopen('php://temp', 'w+');
        if ($this->tab() === 'employees') {
            fputcsv($out, ['Employee code', 'Employee', 'Reports', 'Oldest approval', 'Approved amount', 'Reimbursable', 'Non-reimbursable', 'Advances settled', 'To pay', 'Payment']);
            foreach ($rows as $row) {
                $a = $row->getAttributes();
                fputcsv($out, array_map(self::cell(...), [$row->employee?->employee_code, $row->employee?->person?->display_name, (int) $a['reports_count'],
                    filled($a['first_decided_at'] ?? null) ? substr((string) $a['first_decided_at'], 0, 10) : null,
                    (float) $a['sum_approved'], (float) $a['sum_reimbursable'], (float) $a['sum_non_reimbursable'], (float) $a['sum_advances'], (float) $a['sum_to_pay'],
                    self::employeePayment((int) $a['reports_count'], (int) $a['scheduled_count'])]));
            }
        } elseif ($this->tab() === 'paid') {
            fputcsv($out, ['Report', 'Employee code', 'Employee', 'Title', 'Reimbursable', 'Non-reimbursable', 'Advances settled', 'Paid amount', 'Paid on', 'How', 'Payment mode', 'Cheque number', 'Cheque date', 'Reference', 'Paying account', 'Pay month', 'Payment note']);
            foreach ($rows as $r) {
                fputcsv($out, array_map(self::cell(...), [$r->number, $r->employee?->employee_code, $r->employee?->person?->display_name, $r->title,
                    (float) $r->reimbursable_approved, $nonReimbursable($r), (float) $r->advance_adjusted, (float) $r->net_payable,
                    $r->paid_on?->toDateString(), ExpenseReport::CHANNELS[$r->payment_channel ?? 'bank'] ?? $r->payment_channel, ExpenseReport::PAYMENT_MODES[$r->payment_mode ?? ''] ?? null,
                    $r->cheque_number, $r->cheque_date?->toDateString(), $r->payment_reference, $this->accountLabel($r->paid_from_account_id),
                    $r->payment_channel === 'payroll' ? $r->payment_scheduled_for?->format('M Y') : null, $r->payment_note]));
            }
        } else {
            fputcsv($out, ['Report', 'Employee code', 'Employee', 'Title', 'Approved on', 'Approved amount', 'Reimbursable', 'Non-reimbursable', 'Advances settled', 'To pay', 'Payment', 'Pay month']);
            foreach ($rows as $r) {
                fputcsv($out, array_map(self::cell(...), [$r->number, $r->employee?->employee_code, $r->employee?->person?->display_name, $r->title, $r->decided_at?->toDateString(),
                    (float) $r->total_approved, (float) $r->reimbursable_approved, $nonReimbursable($r), (float) $r->advance_adjusted,
                    (float) $r->net_payable, $r->paymentStatus(), $r->payMonthLabel()]));
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        app(AuditRecorder::class)->record(AuditAction::Export, 'expense', null, [], null, actor: $user, entityLabel: 'Pay expenses — '.self::TABS[$this->tab()], metadata: [
            'export' => self::REGISTER, 'tab' => $this->tab(), 'rows' => $rows->count(),
            'filters' => collect($this->tableFilters ?? [])->filter(fn ($v) => collect((array) $v)->flatten()->filter(fn ($x) => filled($x) && $x !== false)->isNotEmpty())->keys()->values()->all(),
        ]);

        return response()->streamDownload(fn () => print ($csv), 'pay-expenses-'.$this->tab().'-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private static function cell(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return SpreadsheetCell::guard($text);
    }
}
