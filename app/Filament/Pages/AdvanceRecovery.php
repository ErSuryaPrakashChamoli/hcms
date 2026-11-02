<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Services\AdvanceRecoveries;
use App\Domain\Experience\Services\BulkRuns;
use App\Filament\Support\AdvanceRecoveryActions;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\RegisterTablePage;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * HROne parity XO-6 (matrix 26 §26.3.4 #1–#4, #6–#10; §26.7 #5 — HROne's "Recover advance" / "Expense recovery"): one row
 * per employee who owes money on dispensed expense advances, for `expense.pay` holders in their access scope (never their
 * own row).
 *
 * - "Unrecovered" (default): outstanding, advances, oldest settle-by, scheduled recovery (month and amount, flagged when its
 *   month was finalized without it), automatic recovery date, last report submitted, recovered so far.
 * - "Recovered": employees with nothing outstanding whose advances were recovered through payroll, in the full and final
 *   settlement, or paid back outside payroll.
 * - Search (WO-2: code, names, work e-mail, designation); filters: pasted codes, organisation group, employee status (WO-2),
 *   "No report submitted for N days", recovery (scheduled, month missed, automatic, none), settle-by passed.
 * - Row actions: "Ledger" (the employee's advance ledger with the running outstanding), "Recover through payroll" (D-XO2),
 *   "Record recovery outside payroll" (bank transfer, cheque, cash); bulk (WO-1, with a per-record summary) for both.
 * - Saved views keep the tab; "Report"; an audited CSV of the tab.
 */
class AdvanceRecovery extends RegisterTablePage
{
    use HasRegisterTools {
        applyRegisterView as applyRegisterToolsView;
        currentRegisterState as registerToolsState;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Expense & travel';

    protected static ?string $navigationLabel = 'Advance recovery';

    protected static ?string $title = 'Advance recovery';

    protected static ?string $slug = 'advance-recovery';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.advance-recovery';

    public const REGISTER = 'advance_recovery';

    public const TABS = ['unrecovered' => 'Unrecovered', 'recovered' => 'Recovered'];

    /** The bulk operations offered on the selected rows and on "Act on all matching". */
    public const BULK = ['advance_recovery.payroll', 'advance_recovery.refund'];

    #[Url(as: 'tab')]
    public string $recoveryTab = 'unrecovered';

    #[Url(as: 'search')]
    public $tableSearch = '';

    /** @var Collection<int, Collection<int, ExpenseAdvanceRecoverySchedule>>|null employee id => open schedules (this request) */
    private ?Collection $openSchedules = null;

    /** @var array<int, bool> schedule id => its month was finalized without it (this request) */
    private array $missed = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('expense.pay');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Employees who owe money on dispensed expense advances. Schedule a recovery through a payroll month, record money paid back outside payroll, or open the employee\'s ledger.';
    }

    public static function registerKey(): string
    {
        return self::REGISTER;
    }

    public static function presetViews(): array
    {
        return [
            'scheduled' => ['label' => 'Recovery scheduled', 'lens' => 'unrecovered', 'filters' => ['recovery' => ['value' => 'scheduled']]],
            'missed' => ['label' => 'Recovery month missed', 'lens' => 'unrecovered', 'filters' => ['recovery' => ['value' => 'missed']]],
            'unscheduled' => ['label' => 'Nothing scheduled', 'lens' => 'unrecovered', 'filters' => ['recovery' => ['value' => 'none']]],
            'overdue' => ['label' => 'Settle-by date passed', 'lens' => 'unrecovered', 'filters' => ['overdue' => ['isActive' => true]]],
            'no_report_60' => ['label' => 'No report for 60 days', 'lens' => 'unrecovered', 'filters' => ['no_report' => ['days' => 60]]],
            'recovered' => ['label' => 'Recovered', 'lens' => 'recovered'],
        ];
    }

    public static function registerReports(): array
    {
        return ['outstanding_expense_advances', 'expense_advance_recovery_register'];
    }

    public static function registerBulkOperations(): array
    {
        return self::BULK;
    }

    // --- Tabs ----------------------------------------------------------------------------------------------------------

    /** @return array<string, string> */
    public function tabsFor(): array
    {
        return self::TABS;
    }

    public function tab(): string
    {
        return array_key_exists($this->recoveryTab, self::TABS) ? $this->recoveryTab : 'unrecovered';
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

    private function switchTab(string $tab): void
    {
        $this->recoveryTab = $tab;
        $this->rebuildRegisterTable();
        $this->deselectAllTableRecords();
    }

    /** A saved view keeps the tab (as its "lens"). */
    public function currentRegisterState(): array
    {
        return ['lens' => $this->tab()] + $this->registerToolsState();
    }

    public function applyRegisterView(array $view, ?string $label = null): void
    {
        $tab = is_string($view['lens'] ?? null) && array_key_exists($view['lens'], self::TABS) ? $view['lens'] : $this->tab();
        if ($tab !== $this->tab()) {
            $this->switchTab($tab);
        }
        $this->applyRegisterToolsView($view, $label);
    }

    // --- The register --------------------------------------------------------------------------------------------------

    /**
     * The employees of a tab: in the viewer's access scope (employees, and their expense advances — CA-9 expense groups too),
     * never the viewer's own record, with the tab's figures as sub-selects.
     */
    public static function rows(string $tab): Builder
    {
        $owing = ExpenseAdvance::query()->select('expense_advances.employee_id')->where('expense_advances.status', 'disbursed')->where('expense_advances.outstanding', '>', 0);
        $userId = auth()->id();
        $query = Employee::query()->with('person')->select('employees.*')
            ->where(fn (Builder $q) => $q->whereNull('employees.user_id')->orWhere('employees.user_id', '!=', $userId ?? 0))
            ->addSelect([
                'last_report_at' => DB::table('expense_reports')->selectRaw('MAX(submitted_at)')->whereColumn('expense_reports.employee_id', 'employees.id')->whereNotNull('submitted_at'),
                'recovered_total' => DB::table('expense_advance_entries')->selectRaw('COALESCE(-SUM(amount), 0)')->whereColumn('expense_advance_entries.employee_id', 'employees.id')
                    ->whereIn('kind', ['recovered', 'refunded']),
            ]);
        if ($tab === 'recovered') {
            return $query
                ->whereIn('employees.id', ExpenseAdvanceEntry::query()->select('expense_advance_entries.employee_id')->whereIn('expense_advance_entries.kind', ['recovered', 'refunded']))
                ->whereNotIn('employees.id', $owing)
                ->addSelect([
                    'payroll_recovered' => DB::table('expense_advance_entries')->selectRaw('COALESCE(-SUM(amount), 0)')->whereColumn('expense_advance_entries.employee_id', 'employees.id')
                        ->where('kind', 'recovered')->whereNull('final_settlement_id'),
                    'settlement_recovered' => DB::table('expense_advance_entries')->selectRaw('COALESCE(-SUM(amount), 0)')->whereColumn('expense_advance_entries.employee_id', 'employees.id')
                        ->where('kind', 'recovered')->whereNotNull('final_settlement_id'),
                    'refunded_total' => DB::table('expense_advance_entries')->selectRaw('COALESCE(-SUM(amount), 0)')->whereColumn('expense_advance_entries.employee_id', 'employees.id')
                        ->where('kind', 'refunded'),
                    'last_recovered_on' => DB::table('expense_advance_entries')->selectRaw('MAX(entry_date)')->whereColumn('expense_advance_entries.employee_id', 'employees.id')
                        ->whereIn('kind', ['recovered', 'refunded']),
                ]);
        }
        $disbursed = fn (string $aggregate) => DB::table('expense_advances')->selectRaw($aggregate)->whereColumn('expense_advances.employee_id', 'employees.id')
            ->where('expense_advances.status', 'disbursed')->where('expense_advances.outstanding', '>', 0);

        return $query->whereIn('employees.id', $owing)->addSelect([
            'outstanding_total' => $disbursed('SUM(outstanding)'),
            'advances_count' => $disbursed('COUNT(*)'),
            'oldest_settle_by' => $disbursed('MIN(settle_by)'),
            // The automatic recovery (EX-E) of the advances with no recovery scheduled.
            'automatic_from' => $disbursed('MIN(recovery_due_on)')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('expense_advance_recovery_schedules')
                ->whereColumn('expense_advance_recovery_schedules.expense_advance_id', 'expense_advances.id')->where('expense_advance_recovery_schedules.status', 'scheduled')),
        ]);
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => static::rows($this->tab()))
            ->filters($this->filters())
            ->paginated([25, 50, 100])
            ->emptyStateHeading($this->tab() === 'recovered' ? 'Nothing recovered yet' : 'Nothing outstanding')
            ->emptyStateDescription($this->tab() === 'recovered'
                ? 'Employees whose advances were recovered through payroll, in the full and final settlement or paid back appear here once nothing is outstanding.'
                : 'Employees with money outstanding on dispensed expense advances appear here.');

        return $this->tab() === 'recovered' ? $this->recoveredTable($table) : $this->unrecoveredTable($table);
    }

    private function unrecoveredTable(Table $table): Table
    {
        $sortBy = fn (string $alias) => fn (Builder $query, string $direction) => $query->orderBy($alias, $direction);

        return $table
            ->columns([
                TextColumn::make('employee_code')->label('Employee code'),
                TextColumn::make('person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search()),
                TextColumn::make('outstanding_total')->label('Outstanding')->numeric(2)->sortable(query: $sortBy('outstanding_total')),
                TextColumn::make('advances_count')->label('Advances')->numeric(),
                TextColumn::make('oldest_settle_by')->label('Oldest settle-by')->date()->sortable(query: $sortBy('oldest_settle_by'))->placeholder('—')
                    ->color(fn (Employee $r) => filled($r->getAttributes()['oldest_settle_by'] ?? null) && CarbonImmutable::parse($r->getAttributes()['oldest_settle_by'])->lt(today()) ? 'danger' : null),
                TextColumn::make('scheduled_recovery')->label('Scheduled recovery')->placeholder('—')->wrap()
                    ->state(fn (Employee $r) => $this->scheduledLabel((int) $r->id))
                    ->description(fn (Employee $r) => $this->missedNote((int) $r->id))
                    ->color(fn (Employee $r) => $this->missedNote((int) $r->id) !== null ? 'danger' : null),
                TextColumn::make('automatic_from')->label('Automatic recovery')->placeholder('—')
                    ->state(fn (Employee $r) => filled($r->getAttributes()['automatic_from'] ?? null) ? 'from '.CarbonImmutable::parse($r->getAttributes()['automatic_from'])->format('d M Y') : null)
                    ->tooltip('Advances with no recovery scheduled are recovered by the payroll on or after this date (the policy\'s recovery date).'),
                TextColumn::make('last_report_at')->label('Last report submitted')->date()->placeholder('Never')->sortable(query: $sortBy('last_report_at')),
                TextColumn::make('recovered_total')->label('Recovered so far')->numeric(2)->toggleable(),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('oldest_settle_by')->orderBy('employees.employee_code'))
            ->recordActions([
                Action::make('ledger')->label('Ledger')->icon('heroicon-m-book-open')->color('gray')
                    ->url(fn (Employee $record) => AdvanceLedger::getUrl(['employee' => $record->id])),
                Action::make('recoverPayroll')->label('Recover through payroll')->icon('heroicon-m-calendar-days')->color('gray')
                    ->modalHeading(fn (Employee $record) => 'Recover through payroll · '.$record->employee_code)
                    ->visible(fn (Employee $record) => AdvanceRecoveryActions::mayRecover($record))
                    ->steps(fn (Employee $record) => AdvanceRecoveryActions::payrollSteps($record))
                    ->action(fn (Employee $record, array $data) => AdvanceRecoveryActions::runPayroll($record, $data)),
                Action::make('recoverOutside')->label('Record recovery outside payroll')->icon('heroicon-m-banknotes')
                    ->modalHeading(fn (Employee $record) => 'Recovery outside payroll · '.$record->employee_code)
                    ->visible(fn (Employee $record) => AdvanceRecoveryActions::mayRecover($record))
                    ->steps(fn (Employee $record) => AdvanceRecoveryActions::outsideSteps($record))
                    ->action(fn (Employee $record, array $data) => AdvanceRecoveryActions::runOutside($record, $data)),
            ])
            ->toolbarActions([BulkRecordActions::selected(self::REGISTER, self::BULK)]);
    }

    private function recoveredTable(Table $table): Table
    {
        $sortBy = fn (string $alias) => fn (Builder $query, string $direction) => $query->orderBy($alias, $direction);

        return $table
            ->columns([
                TextColumn::make('employee_code')->label('Employee code'),
                TextColumn::make('person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search()),
                TextColumn::make('payroll_recovered')->label('Recovered through payroll')->numeric(2),
                TextColumn::make('settlement_recovered')->label('In the full and final settlement')->numeric(2)->toggleable(),
                TextColumn::make('refunded_total')->label('Paid back outside payroll')->numeric(2),
                TextColumn::make('last_recovered_on')->label('Last recovered on')->date()->sortable(query: $sortBy('last_recovered_on')),
                TextColumn::make('last_report_at')->label('Last report submitted')->date()->placeholder('Never')->toggleable(),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('last_recovered_on')->orderBy('employees.employee_code'))
            ->recordActions([
                Action::make('ledger')->label('Ledger')->icon('heroicon-m-book-open')->color('gray')
                    ->url(fn (Employee $record) => AdvanceLedger::getUrl(['employee' => $record->id])),
            ]);
    }

    /** @return list<BaseFilter> the same on both tabs, so a saved view applies to each */
    private function filters(): array
    {
        return [
            EmployeeFilters::codes(),
            EmployeeFilters::organisation(),
            EmployeeFilters::workforce(),
            // HROne "Last expense submitted duration": no expense report submitted in the last N days (or never).
            Filter::make('no_report')->label('No report submitted for')
                ->schema([TextInput::make('days')->label('No expense report submitted for (days)')->numeric()->minValue(1)->maxValue(3650)->placeholder('e.g. 60')])
                ->query(function (Builder $query, array $data): Builder {
                    $days = is_numeric($data['days'] ?? null) ? max(1, min(3650, (int) $data['days'])) : null;

                    return $days === null ? $query : $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('expense_reports')
                        ->whereColumn('expense_reports.employee_id', 'employees.id')->whereNotNull('expense_reports.submitted_at')
                        ->where('expense_reports.submitted_at', '>=', today()->subDays($days)->startOfDay()));
                })
                ->indicateUsing(fn (array $data) => is_numeric($data['days'] ?? null) ? [Indicator::make('No report for '.(int) $data['days'].' days')->removeField('days')] : []),
            SelectFilter::make('recovery')->label('Recovery')
                ->options(['scheduled' => 'Scheduled through payroll', 'missed' => 'Month finalized without it', 'automatic' => 'Automatic (policy date)', 'none' => 'Nothing scheduled'])
                ->query(function (Builder $query, array $data): Builder {
                    $open = fn ($q) => $q->select(DB::raw(1))->from('expense_advance_recovery_schedules')->whereColumn('expense_advance_recovery_schedules.employee_id', 'employees.id')
                        ->where('expense_advance_recovery_schedules.status', 'scheduled');

                    return match ($data['value'] ?? null) {
                        'scheduled' => $query->whereExists($open),
                        'missed' => $query->whereIn('employees.id', $this->missedEmployeeIds()),
                        'automatic' => $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('expense_advances')->whereColumn('expense_advances.employee_id', 'employees.id')
                            ->where('expense_advances.status', 'disbursed')->where('expense_advances.outstanding', '>', 0)->whereNotNull('expense_advances.recovery_due_on')
                            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('expense_advance_recovery_schedules')
                                ->whereColumn('expense_advance_recovery_schedules.expense_advance_id', 'expense_advances.id')->where('expense_advance_recovery_schedules.status', 'scheduled'))),
                        'none' => $query->whereNotExists($open),
                        default => $query,
                    };
                }),
            Filter::make('overdue')->label('Settle-by date passed')->toggle()
                ->query(fn (Builder $query) => $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('expense_advances')->whereColumn('expense_advances.employee_id', 'employees.id')
                    ->where('expense_advances.status', 'disbursed')->where('expense_advances.outstanding', '>', 0)->whereDate('expense_advances.settle_by', '<', today()->toDateString()))),
        ];
    }

    // --- Helpers -------------------------------------------------------------------------------------------------------

    /** @return Collection<int, ExpenseAdvanceRecoverySchedule> the employee's open scheduled recoveries (loaded once per request) */
    private function schedulesOf(int $employeeId): Collection
    {
        $this->openSchedules ??= ExpenseAdvanceRecoverySchedule::query()->where('status', 'scheduled')->whereHas('advance', fn (Builder $q) => $q->where('status', 'disbursed'))
            ->orderBy('recover_in')->orderBy('id')->get()->groupBy('employee_id');

        return $this->openSchedules->get($employeeId, collect());
    }

    private function isMissed(ExpenseAdvanceRecoverySchedule $schedule): bool
    {
        return $this->missed[(int) $schedule->id] ??= app(AdvanceRecoveries::class)->missed($schedule);
    }

    /** "Nov 2026 · 5,000.00; Dec 2026 · 2,000.00" — the open schedules summed by month. */
    public function scheduledLabel(int $employeeId): ?string
    {
        $schedules = $this->schedulesOf($employeeId);
        if ($schedules->isEmpty()) {
            return null;
        }

        return $schedules->groupBy(fn (ExpenseAdvanceRecoverySchedule $s) => $s->recover_in->format('Y-m'))
            ->map(fn (Collection $month) => $month->first()->monthLabel().' · '.number_format((float) $month->sum(fn ($s) => (float) $s->amount), 2))->implode('; ');
    }

    public function missedNote(int $employeeId): ?string
    {
        $missed = $this->schedulesOf($employeeId)->filter(fn (ExpenseAdvanceRecoverySchedule $s) => $this->isMissed($s));

        return $missed->isEmpty() ? null : $missed->map->monthLabel()->unique()->implode(', ').' finalized without it: change or cancel it on the ledger';
    }

    /** @return list<int> employees with an open recovery whose month was finalized without it */
    private function missedEmployeeIds(): array
    {
        $ids = ExpenseAdvanceRecoverySchedule::query()->where('status', 'scheduled')->get()->filter(fn (ExpenseAdvanceRecoverySchedule $s) => $this->isMissed($s))
            ->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $ids === [] ? [0] : $ids;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('downloadCsv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn () => $this->downloadCsv()),
        ];
    }

    /** The tab as CSV, with its filters and search (expense.pay; audited; formula-injection safe). */
    public function downloadCsv(): StreamedResponse
    {
        $user = auth()->user();
        abort_unless(static::canAccess(), 403);
        $rows = $this->getFilteredSortedTableQuery()->limit(BulkRuns::maxRecords())->get();
        $value = fn (Employee $e, string $key) => $e->getAttributes()[$key] ?? null;
        $date = fn (Employee $e, string $key) => filled($value($e, $key)) ? substr((string) $value($e, $key), 0, 10) : null;

        $out = fopen('php://temp', 'w+');
        if ($this->tab() === 'recovered') {
            fputcsv($out, ['Employee code', 'Employee', 'Recovered through payroll', 'In the full and final settlement', 'Paid back outside payroll', 'Last recovered on', 'Last report submitted']);
            foreach ($rows as $e) {
                fputcsv($out, array_map(self::cell(...), [$e->employee_code, $e->person?->display_name, (float) $value($e, 'payroll_recovered'), (float) $value($e, 'settlement_recovered'),
                    (float) $value($e, 'refunded_total'), $date($e, 'last_recovered_on'), $date($e, 'last_report_at')]));
            }
        } else {
            fputcsv($out, ['Employee code', 'Employee', 'Outstanding', 'Advances', 'Oldest settle-by', 'Scheduled recovery', 'Automatic recovery from', 'Last report submitted', 'Recovered so far']);
            foreach ($rows as $e) {
                fputcsv($out, array_map(self::cell(...), [$e->employee_code, $e->person?->display_name, (float) $value($e, 'outstanding_total'), (int) $value($e, 'advances_count'),
                    $date($e, 'oldest_settle_by'), $this->scheduledLabel((int) $e->id), $date($e, 'automatic_from'), $date($e, 'last_report_at'), (float) $value($e, 'recovered_total')]));
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        app(AuditRecorder::class)->record(AuditAction::Export, 'expense', null, [], null, actor: $user, entityLabel: 'Advance recovery — '.self::TABS[$this->tab()], metadata: [
            'export' => self::REGISTER, 'tab' => $this->tab(), 'rows' => $rows->count(),
            'filters' => collect($this->tableFilters ?? [])->filter(fn ($v) => collect((array) $v)->flatten()->filter(fn ($x) => filled($x) && $x !== false)->isNotEmpty())->keys()->values()->all(),
        ]);

        return response()->streamDownload(fn () => print ($csv), 'advance-recovery-'.$this->tab().'-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private static function cell(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return preg_match('/^[=+\-@]/', $text) === 1 && ! is_numeric($text) ? "'".$text : $text;
    }
}
