<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationComponentLine;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Compliance\Services\RequiredRules;
use App\Domain\Compliance\Services\StatutoryContexts;
use App\Domain\Compliance\Support\StatutoryContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Identity\Models\User;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Support\FormulaVariables;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * HROne parity PO-3 (matrix 25 §25.6.2, the salary dashboard's warning card): read-only checks a payroll team runs before
 * calculating a month, per company and its current pay month (cycle-aware), over exactly the people a run of that month
 * would calculate (PayrollRuns::populationIn) — or, with "active employees only" (the default), only those still
 * employed. Each check gives a count and the list behind it; the list can be exported as CSV (audited).
 *
 * Rules this service keeps:
 * - It never writes payroll (runs, entries, lines, periods) and never blocks a run: it informs. Calculation, validation and
 *   finalize keep their own checks (unverified statutory rules still block finalize while enforcement is on).
 * - Thresholds are statutory values and come ONLY from VERIFIED, intact compliance rule versions with no open regulatory
 *   notice: the wage-share minimum from `WAGES.min_wage_share`, the minimum wage from `MIN_WAGE.monthly_minimum` for the
 *   employee's state, the ESI ceiling from `ESI.wage_ceiling`. Without such a version the check says so and does not run
 *   — there is no fallback number.
 * - Compensation is read through CompensationOutput (and the structure's own formulas, as the salary annexure does);
 *   attendance through AttendanceOutput. Identifiers (PAN, Aadhaar, UAN, ESI IP number) are tested for presence in SQL and
 *   never decrypted or shown. Amounts appear only when the caller asks for them (readers allowed compensation).
 * - Everything is read inside the reader's access scope (employees, statutory details, entries, attendance are scoped).
 */
final class PayrollReadiness
{
    /** key => [label, what it checks] in the order the panel shows them. */
    public const CHECKS = [
        'statutory_setup' => ['Statutory rules for the month', 'Statutory rules your establishments need this month that are not VERIFIED (finalize refuses them while enforcement is on).'],
        'salary_unprocessed' => ['Salary not processed last month', 'In last month\'s payroll population but without an entry in its finalized run.'],
        'attendance_unprocessed' => ['Attendance not processed', 'Days of this pay month so far (up to yesterday) without a processed attendance record.'],
        'statutory_details_missing' => ['Statutory details missing', 'No statutory details (PAN, PF, ESI, PT choices) recorded at all.'],
        'structure_missing' => ['Salary structure missing', 'No approved compensation in force at the end of the pay month (or on the last working day).'],
        'pan_missing' => ['PAN missing', 'No PAN on file (TDS applies at the higher rate).'],
        'aadhaar_missing' => ['Aadhaar missing', 'No Aadhaar reference on file.'],
        'uan_missing' => ['UAN missing', 'PF applies to the employee but no UAN is on file.'],
        'esi_ip_missing' => ['ESI IP number missing', 'ESI was deducted in the employee\'s latest calculated payroll but no ESI IP number is on file.'],
        'wage_share' => ['Wages below the wage-share minimum', 'Basic and DA as a share of gross pay in the salary breakup, against the verified wage rule (Code on Wages).'],
        'minimum_wage' => ['Below the minimum wage', 'Monthly wages (basic and DA) in the salary breakup, against the verified minimum wage of the employee\'s state.'],
        'esi_ceiling' => ['Above the ESI wage ceiling', 'ESI is marked for the employee but the ESI wages in the salary breakup are above the verified ESI ceiling (no ESI is deducted then).'],
        'unbalanced_ctc' => ['Breakup does not match the CTC', 'The salary structure\'s breakup does not add up to the employee\'s CTC, or cannot be worked out.'],
        'special_states' => ['Employees in special states', 'Serving notice or with a pending resignation, suspended, on long leave, or leaving within this pay month.'],
    ];

    /** Rule code and parameter each threshold check reads (statutory values: never in code). */
    public const RULES = [
        'wage_share' => ['WAGES', 'min_wage_share'],
        'minimum_wage' => ['MIN_WAGE', 'monthly_minimum'],
        'esi_ceiling' => ['ESI', 'wage_ceiling'],
    ];

    /** @var array<string, ComplianceRule|bool|null> rule lookups of this evaluation */
    private array $verified = [];

    /** @var array<int, bool> structure version id => any formula reads employee attributes */
    private array $needsAttributes = [];

    public function __construct(
        private readonly CompensationOutput $compensation,
        private readonly StructureFormulas $formulas,
        private readonly FormulaEngine $engine,
        private readonly ComplianceRules $rules,
        private readonly RequiredRules $requiredRules,
        private readonly StatutoryContexts $contexts,
        private readonly PayCycle $cycle,
        private readonly PayrollRuns $runs,
        private readonly AttendanceOutput $attendance,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    /** Lifecycle states counted with "active employees only": payroll-eligible and still employed (not exited). */
    public static function activeStates(): array
    {
        return array_values(array_map(fn (LifecycleState $s) => $s->value, array_filter(LifecycleState::cases(), fn (LifecycleState $s) => $s->isPayrollEligible() && $s->isEmployed())));
    }

    /**
     * Every check for one company (or every company) and its current pay month.
     *
     * State per check: `ok` (ran, nothing found), `attention` (ran, see the rows), `no_rule` (a threshold check with no
     * verified rule: it did not run) or `not_run` (nothing to compare with, e.g. no finalized payroll last month).
     *
     * @return array{months: list<array{company: string, label: string, dates: string}>, notes: list<string>, checks: array<string, array{key: string, label: string, description: string, state: string, count: ?int, notes: list<string>, columns: array<string, string>, rows: list<array<string, mixed>>}>}
     */
    public function evaluate(?int $companyId = null, bool $activeOnly = true, bool $withAmounts = false, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? today())->startOfDay();
        $checks = [];
        foreach (self::CHECKS as $key => [$label, $description]) {
            $checks[$key] = ['key' => $key, 'label' => $label, 'description' => $description, 'state' => 'ok', 'count' => 0, 'notes' => [], 'columns' => self::columns($key, $withAmounts), 'rows' => [], 'ran' => false, 'skipped' => 0];
        }
        $months = [];
        $notes = [];
        $latestEnd = null;

        $companies = Company::query()->when($companyId !== null, fn ($q) => $q->whereKey($companyId))->orderBy('name')->get();
        foreach ($companies as $company) {
            try {
                [$year, $month, $start, $end] = $this->month((int) $company->id, $today);
            } catch (RuntimeException $e) {
                $notes[] = $company->name.': '.$e->getMessage();

                continue;
            }
            $months[] = ['company' => $company->name, 'label' => Carbon::create($year, $month, 1)->format('M Y'), 'dates' => $start->format('j M').' – '.$end->format('j M Y')];
            $latestEnd = $latestEnd === null || $end->gt($latestEnd) ? $end : $latestEnd;

            $employees = $this->population((int) $company->id, $start, $end, $activeOnly)->with(['person', 'statutoryDetail'])->orderBy('employee_code')->get();
            $this->lastMonth($checks, $company, $year, $month, $activeOnly);
            $this->thresholdRules($checks, $end);
            if ($employees->isEmpty()) {
                continue;
            }
            $period = $this->periodFor($company, $year, $month, $start, $end);
            $this->attendanceChecks($checks, $company, $employees, $start, $end, $today);
            $this->statutoryChecks($checks, $company, $employees, $period);
            $this->compensationChecks($checks, $company, $employees, $period, $withAmounts);
            $this->specialStates($checks, $company, $employees, $start, $end);
        }

        $this->statutorySetup($checks, $latestEnd ?? $today);

        foreach ($checks as $key => $check) {
            $count = count($check['rows']);
            $state = match (true) {
                $count > 0 => 'attention',
                ! $check['ran'] && isset(self::RULES[$key]) => 'no_rule',
                ! $check['ran'] && in_array($key, ['salary_unprocessed', 'attendance_unprocessed'], true) => 'not_run',
                default => 'ok',
            };
            if ($check['skipped'] > 0) {
                $check['notes'][] = $check['skipped'].' '.($check['skipped'] === 1 ? 'employee was' : 'employees were').' not checked: '.match ($key) {
                    'minimum_wage' => 'no verified minimum wage (MIN_WAGE with a monthly minimum) for their state.',
                    'esi_ceiling', 'wage_share' => 'no verified rule for their company\'s pay month.',
                    default => 'nothing to compare with.',
                };
            }
            $checks[$key] = ['state' => $state, 'count' => in_array($state, ['no_rule', 'not_run'], true) ? null : $count, 'notes' => array_values(array_unique($check['notes']))] + array_diff_key($check, ['ran' => true, 'skipped' => true]);
        }

        return ['months' => $months, 'notes' => $notes, 'checks' => $checks];
    }

    /**
     * One check's list as CSV, audited as a payroll export (the identifiers are never in it, only whether they are missing).
     */
    public function csv(string $check, User $actor, ?int $companyId = null, bool $activeOnly = true, bool $withAmounts = false): string
    {
        if (! array_key_exists($check, self::CHECKS)) {
            throw new RuntimeException('Unknown readiness check.');
        }
        $result = $this->evaluate($companyId, $activeOnly, $withAmounts)['checks'][$check];
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_values($result['columns']));
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_map(fn (string $column) => self::cell($row[$column] ?? null), array_keys($result['columns'])));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $this->audit->record(AuditAction::Export, 'payroll', null, [], null, actor: $actor, entityLabel: 'Payroll readiness: '.self::CHECKS[$check][0], metadata: [
            'export' => 'payroll_readiness', 'check' => $check, 'rows' => count($result['rows']), 'company_id' => $companyId, 'active_only' => $activeOnly, 'amounts' => $withAmounts,
        ]);

        return $csv;
    }

    /** @return array<string, string> column key => heading of a check's list */
    public static function columns(string $check, bool $withAmounts = false): array
    {
        if ($check === 'statutory_setup') {
            return ['rule' => 'Statutory rule', 'state' => 'State', 'status' => 'Status', 'establishments' => 'Establishments'];
        }
        $base = ['employee_code' => 'Employee code', 'name' => 'Employee', 'company' => 'Company', 'detail' => 'Why'];

        return $base + match ($check) {
            'wage_share' => ['wage_share' => 'Wage share (%)', 'threshold' => 'Minimum (%)'],
            'minimum_wage' => ($withAmounts ? ['wages' => 'Monthly wages'] : []) + ['threshold' => 'Minimum wage', 'state' => 'State'],
            'esi_ceiling' => ($withAmounts ? ['wages' => 'ESI wages'] : []) + ['threshold' => 'ESI ceiling'],
            'unbalanced_ctc' => $withAmounts ? ['breakup' => 'Breakup a month', 'ctc' => 'CTC a month'] : [],
            'attendance_unprocessed' => ['days' => 'Days without attendance'],
            default => [],
        };
    }

    // --- Months and population ------------------------------------------------------------------------------------

    /**
     * The pay month holding the date for a company: its fixed period's dates when a run exists, else the pay cycle's
     * (never creates a period).
     *
     * @return array{0: int, 1: int, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function month(int $companyId, CarbonInterface $date): array
    {
        [$year, $month] = $this->cycle->monthContaining($companyId, $date);
        [$start, $end] = $this->bounds($companyId, $year, $month);

        return [$year, $month, $start, $end];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function bounds(int $companyId, int $year, int $month): array
    {
        $period = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->first();
        if ($period?->isFixed()) {
            return [CarbonImmutable::parse($period->start_date)->startOfDay(), CarbonImmutable::parse($period->end_date)->startOfDay()];
        }

        return $this->cycle->bounds($companyId, $year, $month);
    }

    private function periodFor(Company $company, int $year, int $month, CarbonInterface $start, CarbonInterface $end): PayrollPeriod
    {
        // An unsaved stand-in when the month has no period row yet: the statutory context only reads its company and dates.
        return PayrollPeriod::query()->where('company_id', $company->id)->where('year', $year)->where('month', $month)->first()
            ?? new PayrollPeriod(['company_id' => $company->id, 'year' => $year, 'month' => $month, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'status' => 'open']);
    }

    private function population(int $companyId, CarbonInterface $start, CarbonInterface $end, bool $activeOnly)
    {
        return $this->runs->populationIn($companyId, $start, $end)
            ->when($activeOnly, fn ($q) => $q->whereIn('lifecycle_state', self::activeStates()));
    }

    /** @return array<string, mixed> */
    private static function employeeRow(Employee $employee, Company $company, string $detail, array $extra = []): array
    {
        return ['employee_id' => (int) $employee->id, 'employee_code' => (string) $employee->employee_code, 'name' => (string) ($employee->person?->display_name ?? ''), 'company' => (string) $company->name, 'detail' => $detail] + $extra;
    }

    // --- Checks ---------------------------------------------------------------------------------------------------

    /** Employees in last month's population without an entry in that month's finalized (or paid) run. */
    private function lastMonth(array &$checks, Company $company, int $year, int $month, bool $activeOnly): void
    {
        $previous = Carbon::create($year, $month, 1)->subMonthNoOverflow();
        $period = PayrollPeriod::query()->where('company_id', $company->id)->where('year', $previous->year)->where('month', $previous->month)->first();
        $runIds = $period === null ? [] : PayrollRun::query()->where('payroll_period_id', $period->id)->whereIn('status', ['finalized', 'paid'])->pluck('id')->all();
        if ($runIds === []) {
            $checks['salary_unprocessed']['notes'][] = $company->name.': no finalized payroll for '.$previous->format('M Y').' to compare with.';

            return;
        }
        $checks['salary_unprocessed']['ran'] = true;
        $start = CarbonImmutable::parse($period->start_date)->startOfDay();
        $end = CarbonImmutable::parse($period->end_date)->startOfDay();
        $missed = $this->population((int) $company->id, $start, $end, $activeOnly)
            ->whereDoesntHave('payrollEntries', fn ($q) => $q->whereIn('payroll_run_id', $runIds))
            ->with('person')->orderBy('employee_code')->get();
        foreach ($missed as $employee) {
            $checks['salary_unprocessed']['rows'][] = self::employeeRow($employee, $company, 'Not in the finalized payroll of '.$previous->format('M Y'));
        }
    }

    /** Days of the pay month so far (up to yesterday, inside the employee's joining and exit) without processed attendance. */
    private function attendanceChecks(array &$checks, Company $company, Collection $employees, CarbonInterface $start, CarbonInterface $end, CarbonInterface $today): void
    {
        $until = CarbonImmutable::parse($end)->min(CarbonImmutable::parse($today)->subDay());
        if ($until->lt($start)) {
            $checks['attendance_unprocessed']['notes'][] = $company->name.': the pay month has not started yet.';

            return;
        }
        $checks['attendance_unprocessed']['ran'] = true;
        if (! (bool) $this->settings->get('payroll.lop_from_attendance', true)) {
            $checks['attendance_unprocessed']['notes'][] = 'Payroll does not take loss of pay from attendance in this organisation, so missing days do not change pay.';
        }
        $processed = $this->attendance->processedDates($employees->pluck('id')->all(), $start, $until);
        foreach ($employees as $employee) {
            $from = CarbonImmutable::parse($start)->max($employee->joining_date ? CarbonImmutable::parse($employee->joining_date)->startOfDay() : $start);
            $to = $until->min($employee->exit_date ? CarbonImmutable::parse($employee->exit_date)->startOfDay() : $until);
            if ($to->lt($from)) {
                continue;
            }
            $have = array_filter($processed[(int) $employee->id] ?? [], fn (string $d) => $d >= $from->toDateString() && $d <= $to->toDateString());
            $missing = (int) $from->diffInDays($to) + 1 - count($have);
            if ($missing > 0) {
                $checks['attendance_unprocessed']['rows'][] = self::employeeRow($employee, $company, $missing.' of '.((int) $from->diffInDays($to) + 1).' days up to '.$to->format('j M').' have no processed attendance', ['days' => $missing]);
            }
        }
    }

    /** Statutory details, PAN, Aadhaar, UAN (PF applies) and ESI IP number (ESI deducted): presence only, tested in SQL. */
    private function statutoryChecks(array &$checks, Company $company, Collection $employees, PayrollPeriod $period): void
    {
        foreach (['statutory_details_missing', 'pan_missing', 'aadhaar_missing', 'uan_missing', 'esi_ip_missing'] as $key) {
            $checks[$key]['ran'] = true;
        }
        // Never decrypted: only whether each identifier column holds a value.
        $details = EmployeeStatutoryDetail::query()->whereIn('employee_id', $employees->pluck('id')->all())->toBase()
            ->selectRaw('employee_id, pan IS NULL AS no_pan, aadhaar_reference IS NULL AS no_aadhaar, uan IS NULL AS no_uan, esic_number IS NULL AS no_esic, pf_applicable')
            ->get()->keyBy(fn ($row) => (int) $row->employee_id);

        $noEsic = [];
        foreach ($employees as $employee) {
            $detail = $details->get((int) $employee->id);
            if ($detail === null) {
                $checks['statutory_details_missing']['rows'][] = self::employeeRow($employee, $company, 'No statutory details recorded');
            }
            if ($detail === null || (bool) $detail->no_pan) {
                $checks['pan_missing']['rows'][] = self::employeeRow($employee, $company, 'No PAN on file');
            }
            if ($detail === null || (bool) $detail->no_aadhaar) {
                $checks['aadhaar_missing']['rows'][] = self::employeeRow($employee, $company, 'No Aadhaar reference on file');
            }
            $pfChosen = $detail === null || $detail->pf_applicable === null || (bool) $detail->pf_applicable;
            if (($detail === null || (bool) $detail->no_uan) && $pfChosen && $this->context($employee, $period)->applies('EPF')) {
                $checks['uan_missing']['rows'][] = self::employeeRow($employee, $company, 'PF applies; no UAN on file');
            }
            if ($detail === null || (bool) $detail->no_esic) {
                $noEsic[(int) $employee->id] = $employee;
            }
        }

        // ESI deducted in the employee's latest calculated payroll (any run status: entries exist only once calculated).
        if ($noEsic !== []) {
            $latest = PayrollEntry::query()->whereIn('employee_id', array_keys($noEsic))->toBase()->selectRaw('employee_id, MAX(id) AS id')->groupBy('employee_id')->pluck('id', 'employee_id');
            $withEsi = $latest->isEmpty() ? [] : PayrollEntryLine::query()->whereIn('payroll_entry_id', $latest->values()->all())->where('code', 'ESI_EE')->where('amount', '>', 0)->pluck('payroll_entry_id')->all();
            foreach ($latest as $employeeId => $entryId) {
                if (in_array((int) $entryId, array_map('intval', $withEsi), true)) {
                    $checks['esi_ip_missing']['rows'][] = self::employeeRow($noEsic[(int) $employeeId], $company, 'ESI deducted in the latest payroll; no ESI IP number on file');
                }
            }
        }
    }

    /**
     * Salary structure missing, and the breakup checks: breakup against the CTC, wage share, minimum wage, ESI ceiling —
     * each threshold only from a verified rule version.
     */
    private function compensationChecks(array &$checks, Company $company, Collection $employees, PayrollPeriod $period, bool $withAmounts): void
    {
        $end = CarbonImmutable::parse($period->end_date)->startOfDay();
        $checks['structure_missing']['ran'] = true;
        $checks['unbalanced_ctc']['ran'] = true;

        // The date each employee's compensation is read on: the pay month's end, or the last working day inside it.
        $on = fn (Employee $e) => $e->exit_date && CarbonImmutable::parse($e->exit_date)->lt($end) ? CarbonImmutable::parse($e->exit_date)->startOfDay() : $end;
        $coveredAtEnd = array_flip(array_map('intval', $this->compensation->coveredEmployeeIds($end)->whereIn('employee_id', $employees->pluck('id')->all())->pluck('employee_id')->all()));

        $wageRule = $this->verifiedThreshold('wage_share', $end);
        $esiRule = $this->verifiedThreshold('esi_ceiling', $end);
        $minimumWageAnywhere = $this->minimumWageAnywhere();
        $classes = (array) config('peopleos.payroll.guardrails.wage_share.classifications', ['basic', 'da']);

        foreach ($employees as $employee) {
            $date = $on($employee);
            $covered = $date->eq($end) ? isset($coveredAtEnd[(int) $employee->id]) : $this->compensation->coveredEmployeeIds($date)->where('employee_id', $employee->id)->exists();
            if (! $covered) {
                $checks['structure_missing']['rows'][] = self::employeeRow($employee, $company, 'No approved compensation in force on '.$date->format('j M Y'));

                continue;
            }

            $breakup = $this->breakup($employee, $date);
            if ($breakup['error'] !== null) {
                $checks['unbalanced_ctc']['rows'][] = self::employeeRow($employee, $company, $breakup['error']);

                continue;
            }
            if ($breakup['ctc_mode'] !== 'components' && abs($breakup['difference']) >= 1) {
                $checks['unbalanced_ctc']['rows'][] = self::employeeRow($employee, $company, $breakup['difference'] > 0 ? 'The breakup comes to less than the CTC' : 'The breakup comes to more than the CTC',
                    $withAmounts ? ['breakup' => $breakup['breakup'], 'ctc' => $breakup['ctc_monthly']] : []);
            }

            // Wage share: basic + DA as a share of gross, against the verified minimum share.
            $wages = $breakup['sum'](fn (SalaryComponent $c) => $c->isEarning() && in_array($c->classification, $classes, true));
            if ($wageRule !== null && $breakup['gross'] > 0) {
                $share = round($wages / $breakup['gross'] * 100, 1);
                $minimum = (float) $wageRule->param(self::RULES['wage_share'][1]);
                if ($share < $minimum) {
                    $checks['wage_share']['rows'][] = self::employeeRow($employee, $company, 'Basic and DA are '.$share.'% of gross pay ('.$wageRule->label().')', ['wage_share' => $share, 'threshold' => $minimum]);
                }
            }

            $detail = $employee->statutoryDetail;
            // Minimum wage: the employee's state's verified rule with one monthly figure (a rule by skill category or zone
            // needs those on the employee first, BP-15); otherwise the employee is not checked.
            if ($minimumWageAnywhere) {
                $context = $this->context($employee, $period);
                $state = $context->establishment?->state ?? $context->ptState;
                $rule = $state ? $this->verifiedThreshold('minimum_wage', $date, $state) : null;
                if ($rule === null) {
                    $checks['minimum_wage']['skipped']++;
                } else {
                    $checks['minimum_wage']['ran'] = true;
                    $minimum = (float) $rule->param(self::RULES['minimum_wage'][1]);
                    if ($wages < $minimum) {
                        $checks['minimum_wage']['rows'][] = self::employeeRow($employee, $company, 'Monthly wages are below the minimum wage of '.$state.' ('.$rule->label().')',
                            ($withAmounts ? ['wages' => round($wages, 2)] : []) + ['threshold' => $minimum, 'state' => $state]);
                    }
                }
            }

            // ESI ceiling: ESI marked for the employee, the ESI wages of the breakup above the verified ceiling.
            $esiChosen = $detail === null || $detail->esic_applicable === null || (bool) $detail->esic_applicable;
            if ($esiRule !== null && $esiChosen && $this->context($employee, $period)->applies('ESI')) {
                $ceiling = (float) $esiRule->param(self::RULES['esi_ceiling'][1]);
                $esiWages = $breakup['sum'](fn (SalaryComponent $c) => $c->isEarning() && (bool) ($c->esi_eligibility_wages ?? $c->esi_applicable));
                if ($esiWages > $ceiling) {
                    $checks['esi_ceiling']['rows'][] = self::employeeRow($employee, $company, 'ESI is marked but the ESI wages are above the ceiling ('.$esiRule->label().')',
                        ($withAmounts ? ['wages' => round($esiWages, 2)] : []) + ['threshold' => $ceiling]);
                }
            }
        }
    }

    /** Whether the threshold checks have a verified rule for the pay month (said even when nobody is in the population). */
    private function thresholdRules(array &$checks, CarbonInterface $end): void
    {
        foreach (['wage_share' => 'No verified wage rule (WAGES with a minimum wage share)', 'esi_ceiling' => 'No verified ESI rule with a wage ceiling'] as $check => $missing) {
            if ($this->verifiedThreshold($check, $end) !== null) {
                $checks[$check]['ran'] = true;
            } else {
                $checks[$check]['notes'][] = $missing.' for '.$end->format('M Y').': the check does not run.';
            }
        }
        if (! $this->minimumWageAnywhere()) {
            $checks['minimum_wage']['notes'][] = 'No verified minimum wage rule (MIN_WAGE) exists yet: the check does not run.';
        }
    }

    /** Any verified, active MIN_WAGE version at all (before resolving each employee's state). */
    private function minimumWageAnywhere(): bool
    {
        return $this->verified['MIN_WAGE|any'] ??= ComplianceRule::query()->where('code', self::RULES['minimum_wage'][0])->where('status', ComplianceRule::ACTIVE)
            ->where('verification_status', ComplianceRule::VERIFIED)->exists();
    }

    /** Notice period or a pending resignation, suspended, on long leave, or leaving within the pay month. */
    private function specialStates(array &$checks, Company $company, Collection $employees, CarbonInterface $start, CarbonInterface $end): void
    {
        $checks['special_states']['ran'] = true;
        $openExit = array_flip(array_map('intval', ExitCase::query()->whereIn('employee_id', $employees->pluck('id')->all())->whereIn('status', ExitCase::OPEN)->pluck('employee_id')->all()));
        foreach ($employees as $employee) {
            $state = $employee->lifecycle_state instanceof LifecycleState ? $employee->lifecycle_state : LifecycleState::tryFrom((string) $employee->lifecycle_state);
            $reasons = array_values(array_filter([
                $state === LifecycleState::NoticePeriod ? 'Serving notice' : (isset($openExit[(int) $employee->id]) ? 'Resignation or exit pending' : null),
                $state === LifecycleState::Suspended ? 'Suspended' : null,
                $state === LifecycleState::OnLeave ? 'On long leave' : null,
                $state === LifecycleState::Exited ? 'Exited' : null,
                $employee->exit_date && CarbonImmutable::parse($employee->exit_date)->betweenIncluded(CarbonImmutable::parse($start), CarbonImmutable::parse($end))
                    ? 'Last working day '.CarbonImmutable::parse($employee->exit_date)->format('j M Y') : null,
            ]));
            if ($reasons !== []) {
                $checks['special_states']['rows'][] = self::employeeRow($employee, $company, implode(' · ', $reasons));
            }
        }
    }

    /** The statutory rules the tenant's establishments need on the date that are not VERIFIED (missing, draft, in review, notice open). */
    private function statutorySetup(array &$checks, CarbonInterface $on): void
    {
        $checks['statutory_setup']['ran'] = true;
        $required = $this->requiredRules->on($on);
        if ($required->isEmpty()) {
            $checks['statutory_setup']['notes'][] = 'No active establishment has statutory settings yet, so no rule is required.';
        }
        foreach ($required as $need) {
            if ($need['status'] === ComplianceRule::VERIFIED) {
                continue;
            }
            $checks['statutory_setup']['rows'][] = [
                'rule' => $need['rule']?->label() ?? $need['statute'], 'state' => (string) ($need['state'] ?? '—'),
                'status' => match ($need['status']) {
                    'missing' => 'No rule', 'notice_open' => 'Regulatory notice open'.($need['notice'] ? ': '.$need['notice'] : ''), default => ucfirst((string) $need['status']),
                },
                'establishments' => implode(', ', $need['establishments']),
            ];
        }
        if (! ComplianceRules::enforced()) {
            $checks['statutory_setup']['notes'][] = 'Enforcement of verified rules is off in this environment.';
        }
    }

    // --- Helpers --------------------------------------------------------------------------------------------------

    /** A VERIFIED, intact rule version in force with no open notice, whose parameter is a number; else null (no fallback). */
    private function verifiedThreshold(string $check, CarbonInterface $on, ?string $state = null): ?ComplianceRule
    {
        [$code, $parameter] = self::RULES[$check];
        $key = $code.'|'.$state.'|'.$on->toDateString();
        if (! array_key_exists($key, $this->verified)) {
            $rule = $this->rules->resolve($code, $on, $state);
            $usable = $rule !== null && $rule->isVerified() && $rule->checksumIntact() && $this->rules->pendingNotice($rule, $on) === null
                && is_numeric($rule->param($parameter)) && (float) $rule->param($parameter) > 0;
            $this->verified[$key] = $usable ? $rule : null;
        }

        return $this->verified[$key];
    }

    /** Employee → establishment (on the pay month's end) → state and the statutes that apply there, as payroll resolves it. */
    private function context(Employee $employee, PayrollPeriod $period): StatutoryContext
    {
        return $this->contexts->for($employee, $period);
    }

    /**
     * The monthly breakup of the employee's approved compensation on the date, with the structure's own formulas (as the
     * salary annexure works it out).
     *
     * @return array{error: ?string, ctc_mode: string, difference: float, breakup: float, ctc_monthly: float, gross: float, sum: \Closure(\Closure(SalaryComponent): bool): float}
     */
    private function breakup(Employee $employee, CarbonInterface $on): array
    {
        $none = fn (string $error) => ['error' => $error, 'ctc_mode' => 'ctc', 'difference' => 0.0, 'breakup' => 0.0, 'ctc_monthly' => 0.0, 'gross' => 0.0, 'sum' => fn () => 0.0];
        $segment = $this->compensation->forPayroll($employee, $on, $on)->last();
        if (! $segment instanceof CompensationSegment || $segment->components->isEmpty()) {
            return $none('The salary structure has no approved components on '.Carbon::parse($on)->format('j M Y'));
        }
        $lines = $segment->components->map(fn (CompensationComponentLine $line) => ['component' => $line->component, 'formula_override' => $line->formulaOverride, 'sort_order' => $line->sortOrder])->all();
        try {
            $preview = $this->formulas->preview($lines, $segment->parameters, [
                'ctc_annual' => $segment->compensation->ctcAnnual, 'variable_target_annual' => $segment->compensation->variableTargetAnnual,
                'values' => $segment->compensation->componentValues, 'ctc_mode' => $segment->ctcMode,
                'attributes' => $this->usesAttributes($segment) ? FormulaVariables::employeeAttributes($employee, $on) : [],
                'days' => 30, 'pf_applies' => $employee->statutoryDetail?->pf_applicable ?? true, 'pf_restrict_to_ceiling' => true, 'on' => $on,
            ]);
        } catch (Throwable $e) {
            return $none('The breakup cannot be worked out: '.$e->getMessage());
        }
        if ($preview['errors'] !== []) {
            return $none('The breakup cannot be worked out: '.$preview['errors'][0]);
        }
        $byCode = $segment->components->mapWithKeys(fn (CompensationComponentLine $line) => [$line->component->code => $line->component]);
        $rows = collect($preview['rows'])->filter(fn (array $r) => $r['monthly'] !== null && $byCode->has($r['code']));
        $sum = fn (\Closure $where) => round((float) $rows->filter(fn (array $r) => $where($byCode[$r['code']]))->sum('monthly'), 2);

        return [
            'error' => null, 'ctc_mode' => $segment->ctcMode, 'difference' => (float) $preview['totals']['difference'], 'breakup' => (float) $preview['totals']['breakup'],
            'ctc_monthly' => (float) $preview['totals']['ctc_monthly'], 'gross' => (float) $preview['totals']['gross'], 'sum' => $sum,
        ];
    }

    /** Whether any formula of the structure version reads employee attributes (grade, location …): only then are they loaded. */
    private function usesAttributes(CompensationSegment $segment): bool
    {
        $key = (int) ($segment->compensation->structureVersionId ?? 0);

        return $this->needsAttributes[$key] ??= $segment->components->contains(function (CompensationComponentLine $line) {
            $formula = $line->component->formulaFor($line->formulaOverride);
            if ($formula === null) {
                return false;
            }
            try {
                return array_intersect($this->engine->variablesIn($formula), array_keys(FormulaVariables::EMPLOYEE)) !== [];
            } catch (Throwable) {
                return true;
            }
        });
    }

    private static function cell(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) ($value ?? '');

        // Spreadsheet formula injection: a cell starting with = + - @ is read as text.
        return preg_match('/^[=+\-@]/', $text) === 1 && ! is_numeric($text) ? "'".$text : $text;
    }
}
