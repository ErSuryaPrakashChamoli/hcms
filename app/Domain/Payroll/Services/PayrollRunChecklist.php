<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Services\AttendanceMonthDesk;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Loans\Models\Loan;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollPrerunCheck;
use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Streamline S2 (decisions ST-3, ST-4): what must be true before a company's pay month is run, the way HROne's process
 * salary screen lists it (warning card, mandatory steps, reminders) — attendance first, then a checklist of everything
 * payroll depends on. Each item has a status (PASS, WARNING, PENDING_APPROVAL, BLOCKED, NOT_APPLICABLE), the module it
 * comes from, a count and the people concerned. BLOCKED stops the run until cleared; WARNING and PENDING_APPROVAL are
 * acknowledged by ticking (when the organisation requires it), and the tick lapses as soon as what it counted changes;
 * PASS and NOT_APPLICABLE need nothing. Read-only apart from the ticks (payroll_prerun_checks), which are audited.
 */
final class PayrollRunChecklist
{
    public const PASS = 'pass';

    public const WARNING = 'warning';

    public const PENDING_APPROVAL = 'pending_approval';

    public const BLOCKED = 'blocked';

    public const NOT_APPLICABLE = 'not_applicable';

    /** key => [label, what it covers, block|review, source module] in the order HR works through them */
    public const ITEMS = [
        'new_joiners' => ['New joiners', 'People who joined during the month: check their salary structure, bank account and statutory details before their first payroll.', 'review', 'Workforce'],
        'exits' => ['Exits and people leaving', 'People who left or leave this month, are serving notice, have a pending exit, are suspended or on long leave.', 'review', 'Workforce'],
        'org_changes' => ['Transfers and moves', 'People whose company, department, location or designation changed during the month (pay, statutory state and cost centre may follow).', 'review', 'Workforce'],
        'final_settlements' => ['Full and final settlements', 'Leavers of the month whose settlement is not finished, and settlements that already pay this month (they are left out of the run).', 'review', 'Exit'],
        'uploads' => ['Salary uploads', 'Payroll uploads that are not imported yet: variable pay, arrears, claims, investments, loans, statutory details …', 'review', 'Payroll'],
        'one_time_inputs' => ['One-time pay inputs', 'Adjustments for this month and variable pay waiting for approval.', 'review', 'Payroll'],
        'salary_revisions' => ['Salary revisions', 'Revisions effective in or before this month that are not in force yet.', 'review', 'Compensation'],
        'structure_missing' => ['Salary structure missing', 'People without a salary structure for the month. Payroll cannot pay them: assign a structure first.', 'block', 'Compensation'],
        'pay_checks' => ['CTC and wage checks', 'CTC that does not add up, and wages below the minimum wage, under the wage share or over the ESI ceiling (where a verified rule exists).', 'review', 'Compensation'],
        'leave' => ['Leave and unpaid days', 'Leave still waiting for a decision on the month\'s days, and approved unpaid leave that reduces pay.', 'review', 'Leave'],
        'arrears' => ['Arrears from earlier months', 'Attendance corrections to earlier months, and arrear, recovery or correction adjustments this month carries.', 'review', 'Time office'],
        'statutory' => ['Statutory details', 'PAN, Aadhaar, UAN, ESI numbers or statutory details missing, and statutory rules the establishments need that are not verified.', 'review', 'Compliance'],
        'tax_regime' => ['Tax regime and declarations', 'People with no tax declaration for the financial year: TDS uses the default regime and no exemptions.', 'review', 'Tax'],
        'bank' => ['Bank details', 'People without a primary bank account to pay into.', 'review', 'Payroll'],
        'loans' => ['Loans and advances', 'Loan requests waiting for approval, and approved loans this run pays out.', 'review', 'Payroll'],
        'claims' => ['Reimbursements and claims', 'Claims waiting for approval (approved claims are paid by this run).', 'review', 'Payroll'],
        'holds' => ['Salary on hold', 'People whose salary is held: release it, or keep it held for this month.', 'review', 'Payroll'],
        'declarations' => ['Investment declarations and proofs', 'Declarations and proofs waiting for HR; they change this month\'s TDS.', 'review', 'Tax'],
        'encashments' => ['Leave encashment', 'Encashments waiting for approval, and approved ones this run pays.', 'review', 'Leave'],
        'last_month' => ['Last month\'s payroll', 'People who were not in last month\'s closed payroll.', 'review', 'Payroll'],
    ];

    /** Readiness checks behind an item: when none of them could run (no verified rule, nothing to compare with), it does not apply. */
    private const READINESS_KEYS = ['pay_checks' => ['unbalanced_ctc', 'minimum_wage', 'wage_share', 'esi_ceiling'], 'last_month' => ['salary_unprocessed']];

    /** Rows shown per item (the full list is in the register the item links to). */
    public const ROWS = 50;

    public function __construct(
        private readonly PayrollReadiness $readiness,
        private readonly PayrollRuns $runs,
        private readonly AuditRecorder $audit,
        private readonly SettingsRepository $settings,
    ) {}

    public function attendanceFirst(): bool
    {
        return (bool) $this->settings->get('payroll.run.attendance_first', true);
    }

    public function checklistRequired(): bool
    {
        return (bool) $this->settings->get('payroll.run.checklist_required', true);
    }

    /**
     * Where the month's attendance stands, and whether payroll may start on it.
     *
     * @return array{label: string, ended: bool, employees: int, incomplete: int, missing_days: int, pending: int, pending_by_kind: array<string, int>, close_enabled: bool, closed: bool, running: bool, done: bool, reasons: list<string>}
     */
    public function attendance(int $companyId, int $year, int $month): array
    {
        $o = app(AttendanceMonthDesk::class)->overview($companyId, $year, $month);
        $pending = array_sum($o['pending']);
        $running = $o['processing']['runs']->contains(fn ($r) => $r->status === 'running');
        $reasons = [];
        if (! $o['ended']) {
            $reasons[] = "{$o['label']} has not ended yet: attendance is processed up to yesterday, and the last days still have to come in.";
        }
        if ($o['processing']['incomplete'] > 0) {
            $reasons[] = "{$o['processing']['incomplete']} of {$o['processing']['total']} people have days without processed attendance ({$o['processing']['missing_days']} days).";
        }
        if ($running) {
            $reasons[] = 'Attendance is being processed in the background.';
        }
        if ($o['close']['enabled'] && $o['close']['status'] !== 'closed' && ! $o['close']['final']) {
            $reasons[] = 'Attendance month close is in use and the month is not closed yet.';
        }

        return [
            'label' => $o['label'], 'ended' => (bool) $o['ended'], 'employees' => (int) $o['processing']['total'], 'incomplete' => (int) $o['processing']['incomplete'],
            'missing_days' => (int) $o['processing']['missing_days'], 'pending' => (int) $pending, 'pending_by_kind' => array_map('intval', $o['pending']),
            'close_enabled' => (bool) $o['close']['enabled'], 'closed' => $o['close']['status'] === 'closed' || (bool) $o['close']['final'], 'running' => $running,
            'done' => $reasons === [], 'reasons' => $reasons,
            'status' => $reasons === [] ? self::PASS : ($this->attendanceFirst() ? self::BLOCKED : self::WARNING),
            'as_of' => now()->format('j M Y, H:i'),
        ];
    }

    /**
     * Every checklist item for the company's pay month, with its tick.
     *
     * @return list<array{key: string, label: string, description: string, kind: string, count: int, rows: list<array{code: string, name: string, detail: string}>, signature: string, ticked: bool, lapsed: bool, checked_by: ?string, checked_at: ?string}>
     */
    public function items(int $companyId, int $year, int $month): array
    {
        $company = Company::query()->findOrFail($companyId);
        [$start, $end] = $this->bounds($companyId, $year, $month);
        $population = $this->runs->populationIn($companyId, $start, $end)->with('person')->orderBy('employee_code')->get();
        $ids = $population->pluck('id')->map(fn ($id) => (int) $id)->all();
        $byId = $population->keyBy('id');
        $readiness = $this->readiness->evaluate($companyId, activeOnly: false, payMonth: [$year, $month])['checks'];
        $person = fn (Employee $e, string $detail, bool $pending = false) => ['code' => (string) $e->employee_code, 'name' => (string) ($e->person?->full_name ?? $e->employee_code), 'detail' => $detail, 'pending' => $pending];
        $fromReadiness = fn (array $keys) => collect($keys)->flatMap(fn (string $k) => collect($readiness[$k]['rows'] ?? [])->map(fn (array $r) => [
            'code' => (string) ($r['employee_code'] ?? $r['rule'] ?? ''), 'name' => (string) ($r['name'] ?? $r['state'] ?? ''), 'detail' => (string) ($r['detail'] ?? $r['status'] ?? $readiness[$k]['label']),
        ]))->values();
        $ofRecords = fn (Collection $records, callable $detail, ?callable $pending = null) => $records->map(fn ($r) => isset($byId[$r->employee_id]) ? $person($byId[$r->employee_id], $detail($r), $pending !== null && (bool) $pending($r)) : null)->filter()->values();

        $rows = [];
        $rows['new_joiners'] = $population->filter(fn (Employee $e) => $e->joining_date !== null && $e->joining_date->betweenIncluded($start, $end))
            ->map(fn (Employee $e) => $person($e, 'Joined '.$e->joining_date->format('j M Y')))->values();
        $rows['exits'] = $fromReadiness(['special_states']);
        $settledHere = $this->runs->settledInFinalSettlement($companyId, $start, $end);
        $leavers = Employee::query()->with('person')->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('id', $settledHere))
            ->whereDate('exit_date', '>=', $start->toDateString())->whereDate('exit_date', '<=', $end->toDateString())->orderBy('employee_code')->get();
        $settlements = FinalSettlement::query()->whereIn('employee_id', $leavers->pluck('id'))->get(['id', 'employee_id', 'status'])->keyBy('employee_id');
        $rows['final_settlements'] = $leavers->map(fn (Employee $e) => match (true) {
            in_array((int) $e->id, $settledHere, true) => $person($e, 'Paid this month in the full and final settlement: left out of the run'),
            ! isset($settlements[$e->id]) => $person($e, 'Leaves '.$e->exit_date->format('j M').': no settlement started'),
            ! in_array($settlements[$e->id]->status, ['approved', 'paid'], true) => $person($e, 'Leaves '.$e->exit_date->format('j M').': settlement '.$settlements[$e->id]->status),
            default => null,
        })->filter()->values();
        $payrollTypes = array_keys(array_filter(RowImportTypes::TYPES, fn (array $t) => ($t[3] ?? null) === 'payroll'));
        $rows['uploads'] = EmployeeImport::query()->whereIn('type', $payrollTypes)->whereNotIn('status', ['imported', 'failed', 'discarded'])->orderBy('id')->get(['id', 'type', 'original_name', 'status'])
            ->map(fn (EmployeeImport $i) => ['code' => '#'.$i->id, 'name' => (string) (RowImportTypes::TYPES[$i->type][1] ?? $i->type), 'detail' => ($i->original_name ? $i->original_name.' · ' : '').ucfirst((string) $i->status).', not imported'])->values();
        $period = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->first();
        $payMonth = sprintf('%04d-%02d', $year, $month);
        $rows['one_time_inputs'] = $ofRecords(PayrollAdjustment::query()->whereIn('employee_id', $ids)->when($period, fn ($q) => $q->where('payroll_period_id', $period->id), fn ($q) => $q->whereRaw('1 = 0'))->where('status', '!=', 'approved')->get(), fn ($a) => ($a->name ?: 'Adjustment').' waiting for approval', fn () => true)
            ->merge($ofRecords(VariablePayRequest::query()->whereIn('employee_id', $ids)->where('status', 'pending')->where('pay_month', '<=', $payMonth)->get(), fn ($r) => 'Variable pay of '.number_format((float) $r->amount, 2).' waiting for approval', fn () => true))->values();
        $rows['salary_revisions'] = collect(app(CompensationOutput::class)->pendingChanges($ids, $end))
            ->map(fn (array $c) => isset($byId[$c['employee_id']]) ? $person($byId[$c['employee_id']], 'Revision from '.($c['effective_from'] ? CarbonImmutable::parse($c['effective_from'])->format('j M Y') : '—').' is '.str_replace('_', ' ', $c['status']), in_array($c['status'], ['submitted', 'under_review'], true)) : null)
            ->filter()->values();
        $rows['structure_missing'] = $fromReadiness(['structure_missing']);
        $rows['pay_checks'] = $fromReadiness(['unbalanced_ctc', 'minimum_wage', 'wage_share', 'esi_ceiling']);
        $rows['statutory'] = $fromReadiness(['statutory_details_missing', 'pan_missing', 'aadhaar_missing', 'uan_missing', 'esi_ip_missing', 'statutory_setup']);
        $rows['bank'] = $this->runs->populationIn($companyId, $start, $end)->whereDoesntHave('bankAccounts', fn ($q) => $q->where('is_primary', true))->with('person')->orderBy('employee_code')->get()
            ->map(fn (Employee $e) => $person($e, 'No primary bank account'))->values();
        $rows['loans'] = $ofRecords(Loan::query()->whereIn('employee_id', $ids)->whereIn('status', ['pending', 'approved'])->get(), fn ($l) => 'Loan '.$l->number.($l->status === 'pending' ? ' waiting for approval' : ' approved, to pay out'), fn ($l) => $l->status === 'pending');
        $rows['claims'] = $ofRecords(ReimbursementClaim::query()->whereIn('employee_id', $ids)->where('status', 'pending')->get(), fn ($c) => 'Claim of '.$c->claim_date?->format('j M Y').' waiting for approval', fn () => true);
        $rows['holds'] = $ofRecords(SalaryHold::query()->whereIn('employee_id', $ids)->where('status', 'held')->get(), fn ($h) => 'Held for '.$h->pay_month.($h->reason ? ': '.$h->reason : ''));
        $rows['declarations'] = $ofRecords(EmployeeTaxDeclaration::query()->whereIn('employee_id', $ids)->where('status', 'submitted')->get(), fn () => 'Declaration waiting for HR', fn () => true)
            ->merge($ofRecords(TdsEmployeeInvestment::query()->whereIn('employee_id', $ids)->where('proof_status', 'pending')->get(), fn () => 'Proof waiting for verification', fn () => true))->values();
        $rows['encashments'] = $ofRecords(LeaveEncashment::query()->whereIn('employee_id', $ids)->whereIn('status', ['pending', 'approved'])->get(), fn ($e) => $e->status === 'pending' ? 'Encashment waiting for approval' : 'Approved: paid by this run', fn ($e) => $e->status === 'pending');
        $rows['last_month'] = $fromReadiness(['salary_unprocessed']);
        $from = $start->toDateString();
        $to = $end->toDateString();
        // Moves inside the month: a position that took effect after the person's first day (joiners have their first).
        $rows['org_changes'] = $ofRecords(EmployeePosition::query()->whereIn('employee_id', $ids)->whereDate('effective_from', '>=', $from)->whereDate('effective_from', '<=', $to)
            ->whereHas('employee', fn ($q) => $q->where(fn ($j) => $j->whereNull('joining_date')->orWhereColumn('joining_date', '<', 'employee_positions.effective_from')))
            ->with(['department:id,name', 'location:id,name'])->orderBy('effective_from')->get(),
            fn (EmployeePosition $p) => 'Moved on '.$p->effective_from?->format('j M').' to '.collect([$p->department?->name, $p->location?->name])->filter()->implode(', '));
        $unpaidTypes = LeaveType::query()->where('is_paid', false)->pluck('id');
        $leave = LeaveRequest::query()->whereIn('employee_id', $ids)->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($u) => $u->where('status', 'approved')->whereIn('leave_type_id', $unpaidTypes)))->orderBy('from_date')->get();
        $rows['leave'] = $ofRecords($leave, fn (LeaveRequest $r) => ($r->status === 'pending' ? 'Leave waiting for a decision: ' : 'Unpaid leave: ').$r->from_date?->format('j M').($r->to_date && ! $r->to_date->isSameDay($r->from_date) ? ' – '.$r->to_date->format('j M') : '').' ('.rtrim(rtrim(number_format((float) $r->days, 1), '0'), '.').' day(s))',
            fn (LeaveRequest $r) => $r->status === 'pending');
        $rows['arrears'] = $ofRecords(AttendanceArrear::query()->whereIn('employee_id', $ids)->where('status', 'pending')->whereDate('date', '<', $from)->orderBy('date')->get(),
            fn (AttendanceArrear $a) => 'Attendance of '.$a->date?->format('j M Y').' corrected ('.((float) $a->delta_days > 0 ? '+' : '').rtrim(rtrim(number_format((float) $a->delta_days, 2), '0'), '.').' day)')
            ->merge($ofRecords(PayrollAdjustment::query()->whereIn('employee_id', $ids)->when($period, fn ($q) => $q->where('payroll_period_id', $period->id), fn ($q) => $q->whereRaw('1 = 0'))
                ->whereIn('type', ['arrear', 'recovery', 'correction'])->get(), fn (PayrollAdjustment $a) => ucfirst((string) $a->type).': '.($a->name ?: 'adjustment').' '.number_format((float) $a->amount, 2)))->values();
        $taxYear = app(FinancialYear::class)->label($end);
        $declared = EmployeeTaxDeclaration::query()->whereIn('employee_id', $ids)->where('financial_year', $taxYear)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        $rows['tax_regime'] = $population->reject(fn (Employee $e) => in_array((int) $e->id, $declared, true))
            ->map(fn (Employee $e) => $person($e, 'No tax declaration for '.$taxYear.': the default regime applies'))->values();

        $ticks = PayrollPrerunCheck::query()->where('company_id', $companyId)->where('year', $year)->where('month', $month)->with('checker:id,name')->get()->keyBy('check_key');
        $asOf = now();
        $out = [];
        foreach (self::ITEMS as $key => [$label, $description, $kind, $module]) {
            $list = collect($rows[$key] ?? [])->values();
            $signature = sha1($list->map(fn (array $r) => $r['code'].'|'.$r['detail'])->sort()->implode("\n"));
            $tick = $ticks[$key] ?? null;
            $current = $tick !== null && $tick->seen_signature === $signature;
            $notApplicable = isset(self::READINESS_KEYS[$key]) && collect(self::READINESS_KEYS[$key])->every(fn (string $k) => in_array($readiness[$k]['state'] ?? null, ['no_rule', 'not_run'], true));
            $status = match (true) {
                $list->isEmpty() => $notApplicable ? self::NOT_APPLICABLE : self::PASS,
                $kind === 'block' => self::BLOCKED,
                $list->every(fn (array $r) => $r['pending'] ?? false) => self::PENDING_APPROVAL,
                default => self::WARNING,
            };
            $out[] = [
                'key' => $key, 'label' => $label, 'description' => $description, 'kind' => $kind, 'module' => $module, 'status' => $status, 'count' => $list->count(),
                'pending' => $list->filter(fn (array $r) => $r['pending'] ?? false)->count(),
                'rows' => $list->take(self::ROWS)->all(), 'signature' => $signature,
                // Only an item that has something to look at needs a tick.
                'needs_tick' => in_array($status, [self::WARNING, self::PENDING_APPROVAL], true),
                'ticked' => $current, 'lapsed' => $tick !== null && ! $current,
                'checked_by' => $tick?->checker?->name, 'checked_at' => $tick?->checked_at?->format('j M Y, H:i'), 'as_of' => $asOf->format('j M Y, H:i'),
            ];
        }

        return $out;
    }

    /** Whether the run may start, with the reasons it may not. @return list<string> */
    public function blockers(int $companyId, int $year, int $month, ?array $items = null): array
    {
        $reasons = [];
        if ($this->attendanceFirst()) {
            $attendance = $this->attendance($companyId, $year, $month);
            if (! $attendance['done']) {
                $reasons[] = 'Attendance first: '.implode(' ', $attendance['reasons']);
            }
        }
        $items ??= $this->items($companyId, $year, $month);
        foreach ($items as $item) {
            if ($item['status'] === self::BLOCKED) {
                $reasons[] = "{$item['label']}: {$item['count']} to clear.";
            } elseif ($this->checklistRequired() && $item['needs_tick'] && ! $item['ticked']) {
                $reasons[] = "{$item['label']}: ".($item['lapsed'] ? 'changed since it was checked; check it again.' : 'not checked yet.');
            }
        }

        return $reasons;
    }

    /**
     * How many items stand in each status, and how many still need a tick.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    public static function summary(array $items): array
    {
        $out = array_fill_keys([self::BLOCKED, self::WARNING, self::PENDING_APPROVAL, self::PASS, self::NOT_APPLICABLE], 0);
        foreach ($items as $item) {
            $out[$item['status']]++;
        }

        return $out + ['to_tick' => count(array_filter($items, fn (array $i) => $i['needs_tick'] && ! $i['ticked']))];
    }

    /** Tick an item as checked, as it stands now (its count and signature are what the tick vouches for). */
    public function tick(int $companyId, int $year, int $month, string $key, User $actor, ?string $note = null): PayrollPrerunCheck
    {
        if (! array_key_exists($key, self::ITEMS)) {
            throw new RuntimeException('Unknown pre-payroll check.');
        }
        $item = collect($this->items($companyId, $year, $month))->firstWhere('key', $key);
        if ($item['status'] === self::BLOCKED) {
            throw new RuntimeException("{$item['label']}: clear the {$item['count']} first; this check cannot be ticked while payroll cannot pay them.");
        }
        if (! $item['needs_tick']) {
            throw new RuntimeException("{$item['label']}: nothing to check this month.");
        }
        $check = PayrollPrerunCheck::query()->updateOrCreate(
            ['company_id' => $companyId, 'year' => $year, 'month' => $month, 'check_key' => $key],
            ['seen_count' => $item['count'], 'seen_signature' => $item['signature'], 'checked_by' => $actor->id, 'checked_at' => now(), 'note' => $note !== null ? mb_substr($note, 0, 255) : null],
        );
        $this->audit->record(AuditAction::Update, 'payroll', $check, [], $note, actor: $actor, entityLabel: 'Pre-payroll check: '.$item['label'], metadata: [
            'check' => $key, 'pay_month' => sprintf('%04d-%02d', $year, $month), 'company_id' => $companyId, 'count' => $item['count'],
        ]);

        return $check;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the pay month's dates (its fixed period's when one exists, else the pay cycle's) */
    public function bounds(int $companyId, int $year, int $month): array
    {
        return app(AttendanceMonthCalendar::class)->window($companyId, $year, $month);
    }
}
