<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Loans\Models\Loan;
use App\Domain\Payroll\Jobs\CalculatePayrollRun;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Csv\SpreadsheetCell;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne parity PO-4 (matrix 25 §25.6.1 #2, #3, #6, #7; §25.6.3 #2, #4, #9; §25.6.4 #1, #12; §25.6.5 #2, #3; §25.6.6 #3, #7;
 * §25.6.10 #1, #2): the run cockpit — one payroll run seen as HROne's salary dashboard steps (inputs → calculate → verify →
 * approve → finalize / publish → pay) with counters, the step tables behind them (with an audited CSV), the run's activity
 * from its audit events, the optional reviewer tick per entry, and calculation in the background or on a set day.
 *
 * Read model only: every figure is read from the run, its entries (what PayrollCalculator recorded in `inputs`, including
 * `input_sources` — the record ids each payroll input source's lines carried), the source registers' own statuses, the
 * salary holds of the month and the payslips. Nothing here prices, recalculates or settles anything: calculation stays in
 * PayrollRuns / PayrollCalculator and the input sources. Everything reads inside the viewer's access scope.
 */
final class PayrollRunCockpit
{
    /** The run as HROne's steps. Verify is the one optional step (HROne's "Non mandatory"). */
    public const STEPS = [
        'inputs' => 'Inputs',
        'calculate' => 'Calculate',
        'verify' => 'Verify',
        'approve' => 'Approve',
        'finalize' => 'Finalize and publish',
        'pay' => 'Pay',
    ];

    /** HROne's side tables of the steps; each opens on the run and downloads as CSV. */
    public const TABLES = [
        'arrears' => 'Attendance arrears',
        'overtime' => 'Overtime',
        'inputs' => 'Payroll inputs in this run',
        'holds' => 'Salary holds of the month',
        'entries' => 'Salaries',
    ];

    /** Payroll input sources (`peopleos.payroll_inputs` keys) by name; any other key on a run is shown under its own name. */
    public const SOURCES = [
        'attendance_arrear' => 'Attendance arrears',
        'variable_pay' => 'Variable pay',
        'reimbursement_claim' => 'Reimbursement claims',
        'loan_disbursement' => 'Loan payouts',
        'loan_recovery' => 'Loan instalments recovered',
        'leave_encashment' => 'Leave encashments',
        'shift_allowance' => 'Shift allowances',
        'transfer_allowance' => 'Transfer allowances',
        'reward_points' => 'Reward points',
        'expense_report' => 'Expense reports',
        'expense_advance_payout' => 'Expense advances paid out',
        'expense_advance_recovery' => 'Expense advance recoveries',
        // HROne parity GO-18: fundraiser pledges deducted once from salary (D-GO3).
        'fundraiser_pledge' => 'Fundraiser pledges',
    ];

    /** Where each run status puts the cockpit: the index of the current step (6 = every step done). */
    private const CURRENT = ['draft' => 0, 'calculated' => 2, 'validated' => 3, 'approved' => 4, 'finalized' => 5, 'paid' => 6];

    /** Rows shown on screen for a step table (the CSV has them all). */
    public const SCREEN_ROWS = 200;

    public function __construct(private readonly AuditRecorder $audit, private readonly PaymentModes $modes) {}

    // --- Steps and counters ------------------------------------------------------------------------------------------

    /**
     * The six steps with their state (done / current / upcoming) and counters (pass counters() already read to avoid reading
     * them twice).
     *
     * @return list<array{key: string, label: string, state: string, optional: bool, counters: list<array{label: string, value: int|float|string|null}>}>
     */
    public function steps(PayrollRun $run, ?array $counters = null): array
    {
        $c = $counters ?? $this->counters($run);
        $current = self::CURRENT[$run->status] ?? 0;
        $counters = [
            'inputs' => [
                ['label' => 'Arrears pending', 'value' => $c['arrears_pending']],
                ['label' => 'Arrears in this run', 'value' => $c['arrears_in_run']],
                ['label' => 'Arrears settled', 'value' => $c['arrears_settled']],
                ['label' => 'Arrears void', 'value' => $c['arrears_void']],
                ['label' => 'Overtime (employees)', 'value' => $c['overtime_employees']],
                ['label' => 'Variable pay', 'value' => $c['variable_in_run']],
                ['label' => 'Claims', 'value' => $c['claims_in_run']],
                ['label' => 'Loans', 'value' => $c['loan_recoveries_in_run'] + $c['loan_payouts_in_run']],
                ['label' => 'Encashments', 'value' => $c['encashments_in_run']],
                ['label' => 'Holds', 'value' => $c['holds_held']],
            ],
            'calculate' => [
                ['label' => 'Calculated', 'value' => $c['calculated']],
                ['label' => 'Not calculated yet', 'value' => $c['not_calculated']],
                ['label' => 'Blocking exceptions', 'value' => $c['exceptions']],
                ['label' => 'Warnings', 'value' => $c['warnings']],
            ],
            'verify' => [
                ['label' => 'Checked', 'value' => $c['reviewed'].' of '.$c['calculated']],
                ['label' => 'Validated', 'value' => $current >= 3 ? 'Yes' : 'No'],
            ],
            'approve' => [
                ['label' => 'Approved by', 'value' => $c['approved_by']],
                ['label' => 'Approved on', 'value' => $run->approved_at?->format('j M Y')],
            ],
            'finalize' => [
                ['label' => 'Payslips issued', 'value' => $c['payslips']],
                ['label' => 'Finalized on', 'value' => $run->finalized_at?->format('j M Y')],
            ],
            'pay' => [
                ['label' => 'Bank transfer', 'value' => $c['pay_bank_transfer']],
                ['label' => 'Cash', 'value' => $c['pay_cash']],
                ['label' => 'Cheque', 'value' => $c['pay_cheque']],
                ['label' => 'Held', 'value' => $c['holds_held']],
                ['label' => 'Released', 'value' => $c['holds_released']],
                ['label' => 'Paid', 'value' => $c['paid']],
            ],
        ];

        $steps = [];
        foreach (array_keys(self::STEPS) as $i => $key) {
            $steps[] = [
                'key' => $key,
                'label' => self::STEPS[$key],
                'state' => $i < $current ? 'done' : ($i === $current ? 'current' : 'upcoming'),
                'optional' => $key === 'verify',
                'counters' => $counters[$key],
            ];
        }

        return $steps;
    }

    /**
     * Every counter of the cockpit, each read from the records it names (in the reader's access scope):
     *
     * - arrears_*: attendance arrears of the run's employees dated before the pay period — `pending` / `held` (what the next
     *   calculation takes / leaves); `in_run` = the arrear ids the run's lines carry; `settled` = settled in this pay period;
     *   `void` = voided since the company's previous finalized payroll (up to this run's finalization).
     * - overtime_*: entries with approved overtime minutes (as recorded by the calculation), their hours, and entries with
     *   the `overtime_unpaid` exception.
     * - *_in_run: record ids the run's lines carry per input source; *_waiting: the source register's approved records of the
     *   run's employees not carried by the run (approved after the calculation, or due in a later month).
     * - adjustments_*: payroll adjustments of the pay period (approved / waiting for approval).
     * - holds_*: salary holds of the pay month for the run's employees (held / released).
     * - pay_*: the salary pay mode in force at the period end (no mode = bank transfer); paid = the run is marked paid and
     *   the employee's pay is not on hold.
     *
     * @return array<string, int|float|string|null>
     */
    public function counters(PayrollRun $run): array
    {
        $run->loadMissing('period');
        $period = $run->period;
        $entries = $this->entryFacts($run);
        $employeeIds = $this->employeeIds($run, $entries);
        $carried = $this->carried($entries);
        $calculated = count($entries);
        $population = $calculated === 0 ? count($employeeIds) : $this->populationCount($run);

        $arrears = fn () => AttendanceArrear::query()->whereIn('employee_id', $employeeIds)->whereDate('date', '<', $period->start_date->toDateString());
        $overtime = array_filter($entries, fn (array $e) => $e['ot_minutes'] > 0);
        $month = $this->payMonth($run);
        $holds = SalaryHold::query()->where('pay_month', $month)->whereIn('employee_id', $employeeIds)->toBase()
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $modes = $this->payModes($run, array_column($entries, 'employee_id'));
        $heldIds = $this->heldEmployeeIds($run);

        $counters = [
            'population' => $population,
            'calculated' => $calculated,
            'not_calculated' => max(0, $population - $calculated),
            'exceptions' => count(array_filter($entries, fn (array $e) => $e['status'] === 'exception')),
            'warnings' => count(array_filter($entries, fn (array $e) => $e['status'] === 'warning')),
            'reviewed' => count(array_filter($entries, fn (array $e) => $e['reviewed'])),

            'arrears_pending' => $arrears()->where('status', 'pending')->count(),
            'arrears_held' => $arrears()->where('status', 'held')->count(),
            'arrears_in_run' => count($carried['attendance_arrear'] ?? []),
            'arrears_settled' => AttendanceArrear::query()->where('status', 'settled')->where('settled_period_id', $period->id)->count(),
            'arrears_void' => $this->voidArrears($run, $employeeIds)->count(),

            'overtime_employees' => count($overtime),
            'overtime_hours' => round(array_sum(array_column($overtime, 'ot_minutes')) / 60, 2),
            'overtime_unpaid' => count(array_filter($entries, fn (array $e) => in_array('overtime_unpaid', $e['exception_types'], true))),

            'variable_in_run' => count($carried['variable_pay'] ?? []),
            'variable_waiting' => $this->notCarried(VariablePayRequest::query()->where('status', 'approved'), $employeeIds, $carried['variable_pay'] ?? []),
            'claims_in_run' => count($carried['reimbursement_claim'] ?? []),
            'claims_waiting' => $this->notCarried(ReimbursementClaim::query()->where('status', 'approved'), $employeeIds, $carried['reimbursement_claim'] ?? []),
            'loan_recoveries_in_run' => count($carried['loan_recovery'] ?? []),
            'loan_payouts_in_run' => count($carried['loan_disbursement'] ?? []),
            'loan_payouts_waiting' => $this->notCarried(Loan::query()->where('status', 'approved')->where('disbursed_through', 'payroll'), $employeeIds, $carried['loan_disbursement'] ?? []),
            'encashments_in_run' => count($carried['leave_encashment'] ?? []),
            'encashments_waiting' => $this->notCarried(LeaveEncashment::query()->where('status', 'approved'), $employeeIds, $carried['leave_encashment'] ?? []),
            'other_inputs_in_run' => array_sum(array_map('count', array_diff_key($carried, array_flip(['attendance_arrear', 'variable_pay', 'reimbursement_claim', 'loan_recovery', 'loan_disbursement', 'leave_encashment'])))),

            'adjustments_approved' => PayrollAdjustment::query()->where('payroll_period_id', $period->id)->where('status', 'approved')->count(),
            'adjustments_waiting' => PayrollAdjustment::query()->where('payroll_period_id', $period->id)->where('status', 'pending')->count(),

            'holds_held' => (int) ($holds['held'] ?? 0),
            'holds_released' => (int) ($holds['released'] ?? 0),

            'approved_by' => $run->approved_by !== null ? User::query()->whereKey($run->approved_by)->value('name') : null,
            'payslips' => Payslip::query()->whereIn('payroll_entry_id', PayrollEntry::query()->where('payroll_run_id', $run->id)->select('id'))->count(),
            'pay_bank_transfer' => count(array_filter($modes, fn (string $m) => $m === 'online_transfer')),
            'pay_cash' => count(array_filter($modes, fn (string $m) => $m === 'cash')),
            'pay_cheque' => count(array_filter($modes, fn (string $m) => $m === 'cheque')),
            'paid' => $run->status === 'paid' ? count(array_filter($entries, fn (array $e) => ! in_array($e['employee_id'], $heldIds, true))) : 0,
        ];

        return $counters;
    }

    /**
     * Input sources the run carries (from the entries), with their counts and labels, HROne's order first.
     *
     * @return array<string, array{label: string, records: int, employees: int}>
     */
    public function sources(PayrollRun $run): array
    {
        $entries = $this->entryFacts($run);
        $out = [];
        foreach ($entries as $entry) {
            foreach ($entry['sources'] as $key => $ids) {
                $out[$key] ??= ['label' => self::SOURCES[$key] ?? Str::headline($key), 'records' => 0, 'employees' => 0];
                $out[$key]['records'] += count($ids);
                $out[$key]['employees']++;
            }
        }
        $rank = fn (string $key) => [array_flip(array_keys(self::SOURCES))[$key] ?? count(self::SOURCES), $key];
        uksort($out, fn (string $a, string $b) => $rank($a) <=> $rank($b));

        return $out;
    }

    // --- Step tables and their CSV ------------------------------------------------------------------------------------

    /**
     * A step table: its columns and rows (every row, or the employees given).
     *
     * @param  list<int>|null  $employeeIds
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>}
     */
    public function table(PayrollRun $run, string $table, ?array $employeeIds = null): array
    {
        if (! array_key_exists($table, self::TABLES)) {
            throw new RuntimeException('Unknown step table.');
        }
        $run->loadMissing('period');

        return match ($table) {
            'arrears' => $this->arrearsTable($run, $employeeIds),
            'overtime' => $this->overtimeTable($run, $employeeIds),
            'inputs' => $this->inputsTable($run, $employeeIds),
            'holds' => $this->holdsTable($run, $employeeIds),
            'entries' => $this->entriesTable($run, $employeeIds),
        };
    }

    /**
     * The step table as CSV (formula-injection-safe cells), audited as an EXPORT of the run (module payroll, metadata
     * `export = payroll_run_step`, the table, rows, which rows — the whole table, the rows a filtered screen table showed or
     * the ones selected — and how many employees they covered). Right: payroll.view, as the run.
     *
     * @param  list<int>|null  $employeeIds  the employees to keep (null = the whole table)
     * @param  string  $selection  all | table (the rows the screen's filters showed) | selected
     */
    public function csv(PayrollRun $run, string $table, User $actor, ?array $employeeIds = null, string $selection = 'all'): string
    {
        if (! $actor->hasPermission('payroll.view')) {
            throw new RuntimeException('You cannot export payroll.');
        }
        $result = $this->table($run, $table, $employeeIds);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_values($result['columns']));
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_map(fn (string $column) => self::cell($row[$column] ?? null), array_keys($result['columns'])));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->record(AuditAction::Export, 'payroll', $run, [], null, actor: $actor, metadata: [
            'export' => 'payroll_run_step', 'table' => $table, 'rows' => count($result['rows']), 'selection' => $employeeIds === null ? 'all' : $selection,
            'employees' => $employeeIds === null ? null : count($employeeIds),
        ]);

        return $csv;
    }

    public function fileName(PayrollRun $run, string $table): string
    {
        $run->loadMissing('period');

        return 'payroll-'.sprintf('%04d-%02d', $run->period->year, $run->period->month).'-'.str_replace('_', '-', $table).'.csv';
    }

    // --- Entry filters (pay mode, paying account, hold) ----------------------------------------------------------------

    /**
     * Each entry's payment facts: the salary pay mode in force at the period end (no mode = bank transfer), the paying
     * account its bank transfer goes from (PaymentModes::payingAccount, as the bank file chooses it; null when the company
     * keeps none or the employee is not paid by transfer) and the hold of the pay month.
     *
     * @param  list<int>|null  $employeeIds
     * @return array<int, array{mode: string, account_id: ?int, account: ?string, hold: ?string}> employee id => facts
     */
    public function payment(PayrollRun $run, ?array $employeeIds = null): array
    {
        $run->loadMissing('period');
        $ids = $employeeIds ?? PayrollEntry::query()->where('payroll_run_id', $run->id)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $modes = $this->modes->forMany($ids, $run->period->end_date);
        $accounts = CompanyBankAccount::query()->where('company_id', $run->company_id)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->get()->keyBy('id');
        $ifsc = $accounts->isEmpty() ? collect() : Employee::query()->whereIn('employees.id', $ids)->with(['bankAccounts' => fn ($q) => $q->where('is_primary', true)])->get(['employees.id'])
            ->mapWithKeys(fn (Employee $e) => [(int) $e->id => $e->bankAccounts->first()?->ifsc]);
        $holds = SalaryHold::query()->where('pay_month', $this->payMonth($run))->whereIn('employee_id', $ids)->orderBy('id')->get(['employee_id', 'status'])
            ->mapWithKeys(fn (SalaryHold $h) => [(int) $h->employee_id => $h->status]);

        $out = [];
        foreach ($ids as $id) {
            /** @var EmployeePaymentMode|null $mode */
            $mode = $modes[$id] ?? null;
            $salaryMode = $mode?->salary_mode ?? 'online_transfer';
            $account = $salaryMode === 'online_transfer'
                ? $this->modes->payingAccount($mode?->salary_bank_account_id !== null ? (int) $mode->salary_bank_account_id : null, $ifsc[$id] ?? null, $accounts)
                : null;
            $out[$id] = ['mode' => $salaryMode, 'account_id' => $account?->id, 'account' => $account?->label(), 'hold' => $holds[$id] ?? null];
        }

        return $out;
    }

    /**
     * Employees of the run matching one payment filter value.
     *
     * @return list<int>
     */
    public function employeesWhere(PayrollRun $run, string $filter, string $value): array
    {
        return array_keys(array_filter($this->payment($run), fn (array $p) => match ($filter) {
            'mode' => $p['mode'] === $value,
            'account' => $value === 'none' ? $p['account_id'] === null : (string) $p['account_id'] === $value,
            'hold' => $value === 'none' ? $p['hold'] === null : $p['hold'] === $value,
            default => false,
        }));
    }

    /** @return array<string, string> the company's active paying accounts, id => label */
    public function payingAccounts(PayrollRun $run): array
    {
        return CompanyBankAccount::query()->where('company_id', $run->company_id)->where('status', 'active')->orderByDesc('is_default')->orderBy('id')->get()
            ->mapWithKeys(fn (CompanyBankAccount $a) => [(string) $a->id => $a->label()])->all();
    }

    // --- Reviewer tick (§25.6.5 #3) ------------------------------------------------------------------------------------

    /**
     * Tick (or clear) entries as checked: who and when, audited as REVIEWED on each entry (metadata: the run, the employee,
     * checked or cleared). Optional and informational — validation, approval and finalization never look at it. A
     * recalculation replaces the run's entries, so every new entry starts unchecked. Refused on finalized payroll (it stays
     * as issued), without payroll.calculate or payroll.approve, and per entry on one's own pay or outside the access scope.
     *
     * @param  list<int>  $entryIds
     * @return array{done: int, unchanged: int, refused: array<int, string>}
     */
    public function review(PayrollRun $run, array $entryIds, User $actor, bool $checked = true): array
    {
        if (! $actor->hasPermission('payroll.calculate') && ! $actor->hasPermission('payroll.approve')) {
            throw new RuntimeException('Only people who calculate or approve payroll can check salaries.');
        }
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) {
            throw new RuntimeException('Choose the salaries to check.');
        }
        $label = $checked ? 'Payroll salaries checked' : 'Payroll salary checks cleared';
        $result = ['done' => 0, 'unchanged' => 0, 'refused' => []];

        $this->audit->operation('payroll', $label, function () use ($run, $entryIds, $actor, $checked, &$result) {
            DB::transaction(function () use ($run, $entryIds, $actor, $checked, &$result) {
                // The run row first (as calculation and finalization lock it), so a tick never lands on entries a
                // recalculation is replacing, nor on a run being finalized.
                $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->isLocked()) {
                    throw new RuntimeException('Finalized payroll stays as issued: its salaries can no longer be ticked or cleared.');
                }
                $entries = PayrollEntry::query()->where('payroll_run_id', $locked->id)->whereKey($entryIds)->with('employee:id,user_id,employee_code')->get()->keyBy('id');
                $scopes = app(AccessScopes::class);
                foreach ($entryIds as $id) {
                    /** @var PayrollEntry|null $entry */
                    $entry = $entries->get($id);
                    if ($entry === null || ! $scopes->allows($actor, $entry)) {
                        $result['refused'][$id] = 'Not in your access, or no longer in this run (it may have been recalculated).';

                        continue;
                    }
                    if ($entry->employee !== null && (int) $entry->employee->user_id === (int) $actor->id) {
                        $result['refused'][$id] = 'You cannot check your own salary.';

                        continue;
                    }
                    $before = ['by' => $entry->getAttributes()['reviewed_by'] ?? null, 'at' => $entry->getAttributes()['reviewed_at'] ?? null];
                    if (($before['by'] !== null) === $checked) {
                        $result['unchanged']++;

                        continue;
                    }
                    $after = $checked ? ['by' => $actor->id, 'at' => now()] : ['by' => null, 'at' => null];
                    $entry->withoutAuditing()->forceFill(['reviewed_by' => $after['by'], 'reviewed_at' => $after['at']])->save();
                    $this->audit->record(AuditAction::Reviewed, 'payroll', $entry, [
                        ['field' => 'reviewed_by', 'before' => $before['by'], 'after' => $after['by']],
                        ['field' => 'reviewed_at', 'before' => $before['at'] === null ? null : Carbon::parse($before['at'])->toIso8601String(), 'after' => $after['at']?->toIso8601String()],
                    ], actor: $actor, metadata: ['payroll_run_id' => $locked->id, 'employee_id' => (int) $entry->employee_id, 'checked' => $checked]);
                    $result['done']++;
                }
            });

            return ['succeeded' => $result['done'], 'failed' => count($result['refused'])];
        });

        return $result;
    }

    // --- Calculation in the background and on a set day (§25.6.1 #3, #6; §25.6.10 #1) --------------------------------

    /**
     * "Calculate in background": queue CalculatePayrollRun for the run, telling the person when it is done (or why not).
     * Refused for a run that cannot be calculated (approved, finalized or paid — finalized payroll is never reopened),
     * without payroll.calculate, and while a calculation of the run is already queued. Audited as SCHEDULED on the run.
     */
    public function queueCalculation(PayrollRun $run, User $actor): void
    {
        if (! $actor->hasPermission('payroll.calculate')) {
            throw new RuntimeException('You cannot calculate payroll.');
        }
        $run = PayrollRun::query()->whereKey($run->getKey())->firstOrFail();
        if (! $run->isEditable()) {
            throw new RuntimeException("A {$run->status} run cannot be recalculated".($run->isLocked() ? ': finalized payroll stays as issued.' : '. Reopen it first.'));
        }
        $job = new CalculatePayrollRun($run->id, $actor->id, notify: true);
        // The job is unique per run: say so rather than queue it silently twice.
        $lock = new UniqueLock(app(Cache::class));
        if (! $lock->acquire($job)) {
            throw new RuntimeException('A calculation of this run is already waiting in the background.');
        }
        $lock->release($job);
        $this->audit->record(AuditAction::Scheduled, 'payroll', $run, [], null, actor: $actor, metadata: ['calculation' => 'background']);
        dispatch($job);
    }

    /**
     * `payroll.auto_calculate_day` (0 = never, the default): on that day of the month (a day past the month's end = its
     * last day) every run that can still be calculated (draft, calculated or validated) is recalculated in the background.
     * Approved, finalized and paid runs are never touched; nothing is validated, approved or finalized, and no run is opened.
     *
     * @return int|null the runs queued, or null when today is not the day
     */
    public function autoCalculate(CarbonInterface $today): ?int
    {
        $day = (int) app(SettingsRepository::class)->get('payroll.auto_calculate_day', 0);
        if (! self::isAutoCalculateDay($day, $today)) {
            return null;
        }
        $queued = 0;
        PayrollRun::query()->with('period')->whereIn('status', PayrollRun::EDITABLE)->orderBy('id')->get()->each(function (PayrollRun $run) use ($day, &$queued) {
            // Streamline review: the scheduled recalculation waits for the same things a person's Calculate does — the
            // month's attendance and the pre-payroll checks (PayrollRunChecklist); a month that is not ready is skipped, with why.
            $blockers = app(PayrollRunChecklist::class)->blockers((int) $run->company_id, (int) $run->period->year, (int) $run->period->month);
            if ($blockers !== []) {
                $this->audit->record(AuditAction::Scheduled, 'payroll', $run, [], null, metadata: ['calculation' => 'automatic', 'day' => $day, 'skipped' => array_slice($blockers, 0, 10)]);

                return;
            }
            $this->audit->record(AuditAction::Scheduled, 'payroll', $run, [], null, metadata: ['calculation' => 'automatic', 'day' => $day]);
            dispatch(new CalculatePayrollRun($run->id));
            $queued++;
        });

        return $queued;
    }

    public static function isAutoCalculateDay(int $day, CarbonInterface $today): bool
    {
        return $day > 0 && min($day, $today->daysInMonth) === (int) $today->day;
    }

    // --- Activity (§25.6.10 #2) ----------------------------------------------------------------------------------------

    /**
     * The run's activity from its audit events (read-only): opened, calculations (who, when, employees, exceptions, the
     * results they replaced), background and automatic calculations queued, validation, approval, return to draft,
     * finalization, payment, the payment date, exports and salary checks. Newest first.
     *
     * @return list<array{when: CarbonInterface, by: string, what: string, detail: ?string}>
     */
    public function activity(PayrollRun $run, int $limit = 30): array
    {
        $events = AuditEvent::query()
            ->where(fn ($q) => $q->where(fn ($r) => $r->where('entity_type', PayrollRun::class)->where('entity_id', (string) $run->id))
                ->orWhere(fn ($r) => $r->where('entity_type', PayrollPeriod::class)->where('entity_id', (string) $run->payroll_period_id)->where('action', AuditAction::Update->value))
                ->orWhere(fn ($r) => $r->where('entity_type', PayrollEntry::class)->where('action', AuditAction::Reviewed->value)->where('metadata->payroll_run_id', $run->id)))
            ->with('fieldChanges')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($limit * 4)
            ->get();

        $out = [];
        $checks = [];
        foreach ($events as $event) {
            /** @var AuditEvent $event */
            if ($event->action === AuditAction::Reviewed) {
                // One line per check action (a bulk tick is one operation).
                $group = $event->operation_id ?? $event->id;
                if (isset($checks[$group])) {
                    $out[$checks[$group]]['count']++;
                    $out[$checks[$group]]['what'] = ($event->metadata['checked'] ?? true) ? 'Salaries checked: '.$out[$checks[$group]]['count'] : 'Salary checks cleared: '.$out[$checks[$group]]['count'];

                    continue;
                }
                $checks[$group] = count($out);
                $out[] = ['when' => $event->occurred_at, 'by' => $event->actor_name ?? 'System', 'what' => ($event->metadata['checked'] ?? true) ? 'Salary checked' : 'Salary check cleared',
                    'detail' => null, 'count' => 1];

                continue;
            }
            $line = $this->describe($event);
            // A transition audited twice — the model's own UPDATE next to the service's event (marked paid, returned to draft,
            // payment date) — is shown once, the newer one.
            $repeat = $line !== null && $event->action === AuditAction::Update && collect(array_slice($out, -3))->contains(fn (array $shown) => $shown['what'] === $line[0]
                && abs($shown['when']->diffInSeconds($event->occurred_at)) < 5);
            if ($line !== null && ! $repeat) {
                $out[] = ['when' => $event->occurred_at, 'by' => $event->actor_name ?? 'System', 'what' => $line[0], 'detail' => $line[1], 'count' => 1];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_map(fn (array $row) => array_diff_key($row, ['count' => true]), array_slice($out, 0, $limit));
    }

    /** @return array{0: string, 1: ?string}|null what an event says on the activity panel (null = not shown) */
    private function describe(AuditEvent $event): ?array
    {
        $m = $event->metadata ?? [];
        $status = $event->fieldChanges->firstWhere('field', 'status');

        return match ($event->action) {
            AuditAction::PayrollStarted => ['Run opened', $m['period'] ?? null],
            AuditAction::PayrollCalculated => ['Calculated', trim(($m['employees'] ?? 0).' employee(s), '.($m['exceptions'] ?? 0).' with exceptions'
                .(isset($m['replaced']['entries']) ? '; replaced '.$m['replaced']['entries'].' earlier result(s)' : ''))],
            AuditAction::Scheduled => match ($m['calculation'] ?? null) {
                'background' => ['Calculation queued in the background', null],
                'automatic' => ['Automatic calculation queued', 'Day '.($m['day'] ?? '?').' of the month'],
                default => null,
            },
            AuditAction::PayrollApproved => ['Approved', $event->reason],
            AuditAction::PayrollReopened => ['Returned to draft', $event->reason],
            AuditAction::PayrollFinalized => ['Finalized', isset($m['payslips']) ? $m['payslips'].' payslip(s) issued' : null],
            AuditAction::Rejected => isset($m['attempt']) ? ['Refused: '.$m['attempt'], $event->reason] : null,
            AuditAction::Export => match ($m['export'] ?? null) {
                'bank_file' => ['Bank file downloaded', ($m['rows'] ?? 0).' transfer(s)'.(isset($m['paying_account']) ? ' · '.$m['paying_account'] : '')],
                'payroll_run_step' => ['Step table downloaded', (self::TABLES[$m['table'] ?? ''] ?? ($m['table'] ?? '')).': '.($m['rows'] ?? 0).' row(s)'],
                default => ['Exported', $m['export'] ?? null],
            },
            AuditAction::Update => match (true) {
                $event->entity_type === PayrollPeriod::class && $event->fieldChanges->contains('field', 'payment_date') => ['Payment date set', ($event->fieldChanges->firstWhere('field', 'payment_date')?->after).($event->reason ? ' · '.$event->reason : '')],
                $status !== null && $status->after === 'validated' => ['Validated', null],
                $status !== null && $status->after === 'paid' => ['Marked as paid', null],
                $status !== null && $status->after === 'draft' => ['Returned to draft', null],
                default => null,
            },
            default => null,
        };
    }

    // --- Internals ------------------------------------------------------------------------------------------------------

    /**
     * The run's entries in the reader's scope, as small facts (the JSON read is narrowed to the keys the cockpit needs).
     *
     * @return list<array{id: int, employee_id: int, status: string, reviewed: bool, ot_minutes: int, sources: array<string, list<int>>, exception_types: list<string>}>
     */
    private function entryFacts(PayrollRun $run): array
    {
        $rows = PayrollEntry::query()->where('payroll_run_id', $run->id)->orderBy('id')->toBase()
            ->get(['id', 'employee_id', 'status', 'reviewed_by', 'exceptions', 'inputs->input_sources as sources', 'inputs->attendance->approved_overtime_minutes as ot_minutes']);

        return $rows->map(function (object $row) {
            $sources = is_string($row->sources) ? json_decode($row->sources, true) : (is_array($row->sources) ? $row->sources : null);
            $exceptions = is_string($row->exceptions) ? json_decode($row->exceptions, true) : null;

            return [
                'id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'status' => (string) $row->status,
                'reviewed' => $row->reviewed_by !== null,
                'ot_minutes' => (int) ($row->ot_minutes ?? 0),
                'sources' => is_array($sources) ? array_map(fn ($ids) => array_map('intval', (array) $ids), $sources) : [],
                'exception_types' => is_array($exceptions) ? array_values(array_filter(array_map(fn ($e) => is_array($e) ? ($e['type'] ?? null) : null, $exceptions))) : [],
            ];
        })->all();
    }

    /**
     * The run's employees: those with an entry once it has been calculated, otherwise the population a calculation would
     * take (PayrollRuns::populationIn, unchanged), both in the reader's scope.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<int>
     */
    private function employeeIds(PayrollRun $run, array $entries): array
    {
        if ($entries !== []) {
            return array_values(array_unique(array_column($entries, 'employee_id')));
        }

        return app(PayrollRuns::class)->populationIn((int) $run->company_id, $run->period->start_date, $run->period->end_date)
            ->pluck('employees.id')->map(fn ($id) => (int) $id)->all();
    }

    private function populationCount(PayrollRun $run): int
    {
        return app(PayrollRuns::class)->populationIn((int) $run->company_id, $run->period->start_date, $run->period->end_date)->count();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, list<int>> source key => the record ids the run's lines carry
     */
    private function carried(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            foreach ($entry['sources'] as $key => $ids) {
                $out[$key] = array_merge($out[$key] ?? [], $ids);
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $out);
    }

    /** @param  list<int>  $employeeIds  @param  list<int>  $carried */
    private function notCarried($query, array $employeeIds, array $carried): int
    {
        return $query->whereIn('employee_id', $employeeIds)->when($carried !== [], fn ($q) => $q->whereKeyNot($carried))->count();
    }

    /** Attendance arrears of the run's employees voided since the company's previous finalized payroll (up to this run's finalization). */
    private function voidArrears(PayrollRun $run, array $employeeIds)
    {
        $since = PayrollRun::query()->where('company_id', $run->company_id)->whereIn('status', ['finalized', 'paid'])->where('id', '!=', $run->id)
            ->whereHas('period', fn ($q) => $q->where('start_date', '<', $run->period->start_date->toDateString()))->max('finalized_at');

        return AttendanceArrear::query()->where('status', 'void')->whereIn('employee_id', $employeeIds)
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>', $since))
            ->when($run->isLocked() && $run->finalized_at !== null, fn ($q) => $q->where('updated_at', '<=', $run->finalized_at));
    }

    private function payMonth(PayrollRun $run): string
    {
        return sprintf('%04d-%02d', $run->period->year, $run->period->month);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, string> employee id => salary pay mode at the period end
     */
    private function payModes(PayrollRun $run, array $employeeIds): array
    {
        return array_map(fn (array $p) => $p['mode'], $this->payment($run, $employeeIds));
    }

    /** @return list<int> employees whose pay for the run's month is on hold (as the bank file reads them) */
    private function heldEmployeeIds(PayrollRun $run): array
    {
        return app(SalaryHolds::class)->heldEmployeeIds($run);
    }

    /** @return array<int, array{code: ?string, name: ?string}> */
    private function people(array $employeeIds): array
    {
        return Employee::query()->whereIn('employees.id', $employeeIds)->with('person')->get()
            ->mapWithKeys(fn (Employee $e) => [(int) $e->id => ['code' => $e->employee_code, 'name' => $e->person?->full_name]])->all();
    }

    /** @param  list<int>|null  $only */
    private function restrict(array $employeeIds, ?array $only): array
    {
        return $only === null ? $employeeIds : array_values(array_intersect($employeeIds, array_map('intval', $only)));
    }

    private function arrearsTable(PayrollRun $run, ?array $only): array
    {
        $entries = $this->entryFacts($run);
        $employeeIds = $this->restrict($this->employeeIds($run, $entries), $only);
        $carried = $this->carried($entries)['attendance_arrear'] ?? [];
        $voidIds = $this->voidArrears($run, $employeeIds)->pluck('id')->all();
        $arrears = AttendanceArrear::query()->whereIn('employee_id', $employeeIds)
            ->where(fn ($q) => $q->where(fn ($p) => $p->whereIn('status', ['pending', 'held'])->whereDate('date', '<', $run->period->start_date->toDateString()))
                ->orWhere(fn ($p) => $p->where('status', 'settled')->where('settled_period_id', $run->payroll_period_id))
                ->orWhereKey($voidIds)
                ->when($carried !== [], fn ($p) => $p->orWhereKey($carried)))
            ->orderBy('employee_id')->orderBy('date')->get();
        $people = $this->people($arrears->pluck('employee_id')->unique()->all());
        $periods = PayrollPeriod::query()->whereKey($arrears->pluck('settled_period_id')->filter()->unique()->all())->get()->keyBy('id');

        return [
            'columns' => ['employee_code' => 'Employee code', 'name' => 'Employee', 'date' => 'Arrear date', 'for_month' => 'Pay arrear (for) month', 'days' => 'Generated arrear days',
                'change' => 'Status before → after', 'status' => 'Status', 'salary_month' => 'Salary month', 'in_run' => 'In this run'],
            'rows' => $arrears->map(fn (AttendanceArrear $a) => [
                'employee_code' => $people[$a->employee_id]['code'] ?? null,
                'name' => $people[$a->employee_id]['name'] ?? null,
                'date' => $a->date->toDateString(),
                'for_month' => $a->date->format('M Y'),
                'days' => (float) $a->delta_days,
                'change' => ($a->status_before ?? '—').' → '.($a->status_after ?? '—'),
                'status' => AttendanceArrear::STATUSES[$a->status] ?? $a->status,
                'salary_month' => $a->settled_period_id !== null ? $periods->get($a->settled_period_id)?->label() : (in_array($a->id, $carried, true) ? $run->period->label() : null),
                'in_run' => in_array($a->id, $carried, true) ? 'Yes' : 'No',
            ])->values()->all(),
        ];
    }

    private function overtimeTable(PayrollRun $run, ?array $only): array
    {
        $entries = array_values(array_filter($this->entryFacts($run), fn (array $e) => ($e['ot_minutes'] > 0 || in_array('overtime_unpaid', $e['exception_types'], true))
            && ($only === null || in_array($e['employee_id'], array_map('intval', $only), true))));
        $lines = PayrollEntryLine::query()->whereIn('payroll_entry_id', array_column($entries, 'id'))->where('type', 'earning')->get(['payroll_entry_id', 'name', 'amount', 'basis'])
            ->filter(fn (PayrollEntryLine $l) => str_contains((string) ($l->basis['formula'] ?? ''), 'overtime_'))->groupBy('payroll_entry_id');
        $people = $this->people(array_column($entries, 'employee_id'));

        return [
            'columns' => ['employee_code' => 'Employee code', 'name' => 'Employee', 'hours' => 'Overtime hours', 'amount' => 'Overtime paid', 'rate' => 'Rate (component formula)', 'unpaid' => 'No overtime component'],
            'rows' => array_map(function (array $e) use ($lines, $people) {
                $ot = $lines->get($e['id'], collect());

                return [
                    'employee_code' => $people[$e['employee_id']]['code'] ?? null,
                    'name' => $people[$e['employee_id']]['name'] ?? null,
                    'hours' => round($e['ot_minutes'] / 60, 2),
                    'amount' => round((float) $ot->sum('amount'), 2),
                    'rate' => $ot->map(fn (PayrollEntryLine $l) => $l->name.': '.($l->basis['formula'] ?? ''))->implode('; ') ?: null,
                    'unpaid' => in_array('overtime_unpaid', $e['exception_types'], true) ? 'Yes' : 'No',
                ];
            }, $entries),
        ];
    }

    private function inputsTable(PayrollRun $run, ?array $only): array
    {
        $entries = PayrollEntry::query()->where('payroll_run_id', $run->id)->when($only !== null, fn ($q) => $q->whereIn('employee_id', $only))->pluck('employee_id', 'id');
        $lines = PayrollEntryLine::query()->whereIn('payroll_entry_id', $entries->keys())->whereNotNull('basis->source->key')->orderBy('payroll_entry_id')->orderBy('sort_order')
            ->get(['payroll_entry_id', 'name', 'type', 'amount', 'basis']);
        $people = $this->people($entries->values()->unique()->all());

        return [
            'columns' => ['employee_code' => 'Employee code', 'name' => 'Employee', 'source' => 'Input', 'line' => 'Pay line', 'type' => 'Type', 'amount' => 'Amount', 'records' => 'Records'],
            'rows' => $lines->map(function (PayrollEntryLine $l) use ($entries, $people) {
                $employeeId = (int) $entries[$l->payroll_entry_id];
                $key = (string) ($l->basis['source']['key'] ?? '');

                return [
                    'employee_code' => $people[$employeeId]['code'] ?? null,
                    'name' => $people[$employeeId]['name'] ?? null,
                    'source' => self::SOURCES[$key] ?? Str::headline($key),
                    'line' => $l->name,
                    'type' => ucfirst((string) $l->type),
                    'amount' => (float) $l->amount,
                    'records' => count((array) ($l->basis['source']['ids'] ?? [])),
                ];
            })->values()->all(),
        ];
    }

    private function holdsTable(PayrollRun $run, ?array $only): array
    {
        $employeeIds = $this->restrict($this->employeeIds($run, $this->entryFacts($run)), $only);
        $holds = SalaryHold::query()->where('pay_month', $this->payMonth($run))->whereIn('employee_id', $employeeIds)->with(['holder:id,name'])->orderBy('id')->get();
        $people = $this->people($holds->pluck('employee_id')->unique()->all());

        return [
            'columns' => ['employee_code' => 'Employee code', 'name' => 'Employee', 'status' => 'Status', 'reason' => 'Reason for hold', 'held_by' => 'Held by', 'held_on' => 'Held on',
                'released_on' => 'Released on', 'reference' => 'Payment reference'],
            'rows' => $holds->map(fn (SalaryHold $h) => [
                'employee_code' => $people[$h->employee_id]['code'] ?? null,
                'name' => $people[$h->employee_id]['name'] ?? null,
                'status' => SalaryHold::STATUSES[$h->status] ?? $h->status,
                'reason' => $h->reason,
                'held_by' => $h->holder?->name,
                'held_on' => $h->created_at?->toDateString(),
                'released_on' => $h->released_at?->toDateString(),
                'reference' => $h->release_reference,
            ])->values()->all(),
        ];
    }

    private function entriesTable(PayrollRun $run, ?array $only): array
    {
        $entries = PayrollEntry::query()->where('payroll_run_id', $run->id)->when($only !== null, fn ($q) => $q->whereIn('employee_id', $only))
            ->with(['employee.person', 'reviewer:id,name'])->orderBy('employee_id')->get();
        $payment = $this->payment($run);

        return [
            'columns' => ['employee_code' => 'Employee code', 'name' => 'Employee', 'pay_mode' => 'Pay mode', 'account' => 'Paying account', 'hold' => 'Hold', 'status' => 'Status',
                'paid_days' => 'Paid days', 'lop_days' => 'LOP days', 'gross' => 'Gross', 'deductions' => 'Deductions', 'net' => 'Net pay', 'issues' => 'Issues', 'checked_by' => 'Checked by', 'checked_at' => 'Checked on'],
            'rows' => $entries->map(fn (PayrollEntry $e) => [
                'employee_code' => $e->employee?->employee_code,
                'name' => $e->employee?->person?->full_name,
                'pay_mode' => EmployeePaymentMode::MODES[$payment[$e->employee_id]['mode'] ?? 'online_transfer'] ?? null,
                'account' => $payment[$e->employee_id]['account'] ?? null,
                'hold' => isset($payment[$e->employee_id]['hold']) ? (SalaryHold::STATUSES[$payment[$e->employee_id]['hold']] ?? $payment[$e->employee_id]['hold']) : null,
                'status' => ucfirst((string) $e->status),
                'paid_days' => (float) $e->paid_days,
                'lop_days' => (float) $e->lop_days,
                'gross' => (float) $e->gross,
                'deductions' => (float) $e->total_deductions,
                'net' => (float) $e->net_pay,
                'issues' => collect($e->exceptions ?? [])->pluck('type')->map(fn ($t) => config("peopleos.payroll.exception_types.{$t}", $t))->implode(', ') ?: null,
                'checked_by' => $e->reviewer?->name,
                'checked_at' => $e->reviewed_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    private static function cell(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return SpreadsheetCell::guard($text);
    }
}
