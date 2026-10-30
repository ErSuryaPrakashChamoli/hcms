<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Events\PayrollEvent;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The run pipeline (§31): draft → calculated → validated → approved → finalized → paid; an approved run may return to draft. */
final class PayrollRuns
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly Payslips $payslips,
        private readonly AuditRecorder $audit,
        private readonly ComplianceRules $complianceRules,
        private readonly PayrollReconciliation $reconciliation,
        private readonly CompensationOutput $compensation,
    ) {}

    public function open(Company $company, int $year, int $month, ?User $actor = null): PayrollRun
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.open');
        $period = PayrollPeriod::for($company, $year, $month);

        if ($period->status === 'closed') {
            throw new RuntimeException("{$period->label()} is closed: its payroll is finalized and stays as issued. Correct it with an arrear or a recovery in an open month.");
        }

        $existing = $period->runs()->whereNotIn('status', ['finalized', 'paid'])->first();
        if ($existing) {
            return $existing;
        }

        $run = PayrollRun::create(['company_id' => $company->id, 'payroll_period_id' => $period->id, 'status' => 'draft', 'created_by' => $actor?->id ?? auth()->id()]);
        $this->audit->record(AuditAction::PayrollStarted, 'payroll', $run, [], null, metadata: ['period' => $period->label()]);

        return $run;
    }

    /** Employees whose current position sits in the run's company and who were employed at any point in the period. */
    public function population(PayrollRun $run): Collection
    {
        return $this->populationQuery($run)->orderBy('employee_code')->get();
    }

    /**
     * Same population, streamed in chunks for large runs (Phase 4 §55).
     *
     * SaaS.2: eligibility is a positive list of lifecycle states (LifecycleState::isPayrollEligible), so an
     * employee who has not joined yet (pre-employee, preboarding, onboarding) is never in a run, and a state
     * added later is excluded until it is classified. The earlier exclusion list let preboarding employees
     * through (RMS pre-employees have no joining date, which also passed the date filter) and named two
     * states that do not exist.
     */
    public function populationQuery(PayrollRun $run)
    {
        $period = $run->period;

        return $this->populationIn((int) $run->company_id, $period->start_date, $period->end_date)
            ->with(['person', 'statutoryDetail', 'bankAccounts']);
    }

    /**
     * The same population for a company and pay period, without a run (HROne parity PO-3: the pre-run readiness checks
     * look at exactly the people a run of that month would calculate). Read-only; the reader's access scope applies.
     */
    public function populationIn(int $companyId, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return Employee::query()
            ->whereHas('positions', fn ($q) => $q->where('company_id', $companyId)->effectiveOn($end))
            ->where(fn ($q) => $q->whereNull('joining_date')->orWhere('joining_date', '<=', $end->toDateString().' 23:59:59'))
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhere('exit_date', '>=', $start->toDateString()))
            ->whereIn('lifecycle_state', LifecycleState::payrollEligibleValues());
    }

    public function calculate(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate');
        $run->loadMissing('period');

        return DB::transaction(function () use ($run, $actor) {
            // One calculation at a time per run; a finalized run is never recalculated.
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail()->loadMissing('period');
            $this->assertEditable($run);
            $this->complianceRules->forget(); // never calculate on a stale rule status
            // The results replaced here were never issued (the run is editable); the audit keeps what they were.
            $replaced = ['entries' => $run->entries()->count(), 'net' => round((float) $run->entries()->sum('net_pay'), 2), 'calculated_at' => $run->calculated_at?->toIso8601String(), 'calculation_version' => $run->calculation_version];
            $run->entries()->delete();
            $ruleVersions = [];
            $totals = ['employees' => 0, 'gross' => 0.0, 'earnings' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'employer_cost' => 0.0, 'pf_employee' => 0.0, 'pf_employer' => 0.0, 'esi' => 0.0, 'pt' => 0.0, 'tds' => 0.0, 'lop_days' => 0.0];
            $exceptions = 0;

            foreach ($this->populationQuery($run)->orderBy('id')->lazyById(200) as $employee) {
                $c = $this->calculator->calculate($employee, $run->period);
                foreach ([...array_column($c->lines, 'basis'), ...array_values($c->inputs['rules_consulted'] ?? [])] as $basis) {
                    if (isset($basis['rule_id'])) {
                        $ruleVersions[$basis['rule_id']] = collect($basis)->only(['rule_code', 'rule_version', 'jurisdiction', 'state', 'effective_from', 'verification_status', 'rule_checksum'])->all();
                    }
                }
                $entry = PayrollEntry::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'employee_salary_assignment_id' => $c->compensation?->compensation->assignmentId,
                    'legal_entity_id' => $c->inputs['statutory_context']['legal_entity_id'] ?? null,
                    'establishment_id' => $c->inputs['statutory_context']['establishment_id'] ?? null,
                    'days_in_period' => $c->daysInPeriod,
                    'paid_days' => $c->paidDays,
                    'lop_days' => $c->lopDays,
                    'gross' => $c->gross(),
                    'total_earnings' => $c->earnings(),
                    'total_deductions' => $c->deductions(),
                    'net_pay' => $c->net(),
                    'employer_cost' => round($c->earnings() + $c->employerContributions(), 2),
                    'taxable_earnings' => $c->taxableEarnings(),
                    'status' => $c->blocking() ? 'exception' : ($c->exceptions ? 'warning' : 'ok'),
                    'exceptions' => $c->exceptions,
                    'inputs' => $c->inputs,
                ]);

                foreach ($c->lines as $line) {
                    PayrollEntryLine::create(['payroll_entry_id' => $entry->id] + collect($line)->only(['salary_component_id', 'code', 'name', 'type', 'classification', 'amount', 'taxable', 'basis', 'sort_order'])->all());
                }

                $totals['employees']++;
                $totals['gross'] += $c->gross();
                $totals['earnings'] += $c->earnings();
                $totals['deductions'] += $c->deductions();
                $totals['net'] += $c->net();
                $totals['employer_cost'] += $c->earnings() + $c->employerContributions();
                $totals['pf_employee'] += $c->amount('PF_EE');
                $totals['pf_employer'] += $c->amount('PF_ER') + $c->amount('PF_ADMIN');
                $totals['esi'] += $c->amount('ESI_EE') + $c->amount('ESI_ER');
                $totals['pt'] += $c->amount('PT');
                $totals['tds'] += $c->amount('TDS');
                $totals['lop_days'] += $c->lopDays;

                if ($c->exceptions) {
                    $exceptions++;
                }
            }

            $run->update(['status' => 'calculated', 'calculation_version' => PayrollCalculator::VERSION, 'rule_versions' => $ruleVersions, 'totals' => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $totals), 'exception_count' => $exceptions, 'calculated_at' => now(), 'operation_id' => Context::get('audit.operation_id')]);
            $run->update(['reconciliation' => $this->reconciliation->reconcile($run)]);
            $this->audit->record(AuditAction::PayrollCalculated, 'payroll', $run, [], null, metadata: ['employees' => $totals['employees'], 'net' => round($totals['net'], 2), 'exceptions' => $exceptions] + ($replaced['entries'] > 0 ? ['replaced' => $replaced] : []), actor: $actor);
            PayrollEvent::dispatch('payroll.calculated', $run, ['period' => $run->period->label(), 'employees' => $totals['employees'], 'exceptions' => $exceptions]);

            return $run->refresh();
        });
    }

    /** Validation gate: no blocking exceptions. */
    public function validate(PayrollRun $run): PayrollRun
    {
        $run->refresh();
        if ($run->status !== 'calculated') {
            throw new RuntimeException('Only a calculated run can be validated.');
        }

        $blocking = $run->entries()->where('status', 'exception')->count();
        if ($blocking > 0) {
            throw new RuntimeException("{$blocking} employee(s) have blocking exceptions. Fix the inputs and recalculate.");
        }

        if ($run->entries()->count() === 0) {
            throw new RuntimeException('The run has no employees.');
        }

        $run->update(['status' => 'validated']);

        return $run;
    }

    /** Separation of duties: the approver may not be the person who created or calculated the run. */
    public function approve(PayrollRun $run, User $approver, ?string $note = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $approver, $note) {
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail()->loadMissing('period');

            return $this->approveLocked($run, $approver, $note);
        });
    }

    private function approveLocked(PayrollRun $run, User $approver, ?string $note): PayrollRun
    {
        if ($run->status !== 'validated') {
            throw new RuntimeException('Only a validated run can be approved.');
        }

        // SaaS.8: no exception for platform operators (an operator inside a tenant could prepare and approve alone).
        if ($run->created_by !== null && $run->created_by === $approver->id) {
            throw new RuntimeException('The person who prepared the run cannot approve it.');
        }

        $run->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'notes' => $note ?? $run->notes]);
        $this->audit->record(AuditAction::PayrollApproved, 'payroll', $run, [], $note, actor: $approver, metadata: ['net' => $run->total('net')]);
        PayrollEvent::dispatch('payroll.approved', $run, ['period' => $run->period->label()]);

        return $run;
    }

    /** Locks attendance for the period, closes the period and generates payslips. */
    public function finalize(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        // SaaS.3: shadow entitlement observation (never blocks; see Entitlements).
        app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.finalize');
        if ($run->status !== 'approved') {
            throw new RuntimeException('Only an approved run can be finalized.');
        }

        return DB::transaction(function () use ($run, $actor) {
            // Two finalizations cannot race: lock, then re-check everything on the locked row.
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail()->loadMissing('period');
            if ($run->status !== 'approved') {
                throw new RuntimeException('Only an approved run can be finalized.');
            }
            if ($run->calculation_version !== PayrollCalculator::VERSION) {
                throw new RuntimeException('The run was calculated with engine '.($run->calculation_version ?? 'unknown').'; recalculate with '.PayrollCalculator::VERSION.' before finalizing.');
            }
            // Phase 5 Part F: every statutory rule version the run used must be VERIFIED and intact.
            $this->complianceRules->assertRunVerified($run);
            if ($run->entries()->where('status', 'exception')->exists()) {
                throw new RuntimeException('The run has blocking exceptions.');
            }
            $reconciliation = $this->reconciliation->reconcile($run);
            if (! $reconciliation['balanced']) {
                throw new RuntimeException('Run totals do not reconcile with its entries; recalculate before finalizing.');
            }
            // Phase 11: every entry must still match the approved compensation it was calculated on.
            // Compensation locks this run row before writing compensation for a period it covers, so
            // such a write has either committed (and is seen here) or waits and then sees the run final.
            $this->assertCompensationUnchanged($run);
            $employeeIds = $run->entries()->pluck('employee_id');

            AttendanceRecord::query()->whereIn('employee_id', $employeeIds)
                ->whereBetween('date', [$run->period->start_date->toDateString(), $run->period->end_date->toDateString()])
                ->update(['is_locked' => true, 'finalized_at' => now()]);

            $run->update(['reconciliation' => $reconciliation]);
            $run->update(['status' => 'finalized', 'finalized_by' => $actor?->id ?? auth()->id(), 'finalized_at' => now()]);
            $run->period->update(['status' => 'closed']);

            $count = $this->payslips->generateForRun($run);

            $this->audit->record(AuditAction::PayrollFinalized, 'payroll', $run, [], null, actor: $actor, metadata: ['payslips' => $count, 'net' => $run->total('net')]);
            PayrollEvent::dispatch('payroll.finalized', $run, ['period' => $run->period->label(), 'payslips' => $count, 'net' => $run->total('net')]);

            return $run->refresh();
        });
    }

    private function assertCompensationUnchanged(PayrollRun $run): void
    {
        $entries = $run->entries()->withoutGlobalScope(AccessScope::class)->get(['id', 'employee_id', 'inputs']);
        $current = $this->compensation->fingerprints($entries->pluck('employee_id')->map(fn ($id) => (int) $id)->all(), $run->period->start_date, $run->period->end_date);
        $stale = $entries->filter(fn (PayrollEntry $e) => ($e->inputs['compensation']['fingerprint'] ?? null) !== ($current[(int) $e->employee_id] ?? null))->count();
        if ($stale > 0) {
            throw new RuntimeException("Compensation changed for {$stale} employee(s) since the run was calculated; recalculate before finalizing.");
        }
    }

    /**
     * Phase 7: record the salary payment date of the run's period. It decides the salary TDS rule and
     * tax year, so it can change only while the run is editable, and a calculated run returns to draft.
     */
    public function setPaymentDate(PayrollRun $run, CarbonInterface|string $paymentDate, string $reason, ?User $actor = null): PayrollRun
    {
        if (! $run->isEditable()) {
            throw new RuntimeException("The payment date of a {$run->status} run cannot change. Reopen it first.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to set the payment date.');
        }

        return DB::transaction(function () use ($run, $paymentDate, $reason, $actor) {
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail()->loadMissing('period');
            $before = $run->period->payment_date?->toDateString();
            $run->period->update(['payment_date' => Carbon::parse($paymentDate)->toDateString()]);
            if ($run->status !== 'draft') {
                $run->update(['status' => 'draft']);
            }
            $this->audit->record(AuditAction::Update, 'payroll', $run->period, [['field' => 'payment_date', 'before' => $before, 'after' => Carbon::parse($paymentDate)->toDateString()]], $reason, actor: $actor);

            return $run->refresh();
        });
    }

    public function markPaid(PayrollRun $run, CarbonInterface|string|null $paidOn = null, ?User $actor = null): PayrollRun
    {
        if ($run->status !== 'finalized') {
            throw new RuntimeException('Only a finalized run can be marked as paid.');
        }

        $run->update(['status' => 'paid', 'paid_at' => Carbon::parse($paidOn ?? now())]);
        $this->audit->record(AuditAction::Update, 'payroll', $run, [['field' => 'status', 'before' => 'finalized', 'after' => 'paid']], null, actor: $actor);
        PayrollEvent::dispatch('payroll.paid', $run, ['period' => $run->period->label(), 'net' => $run->total('net')]);

        return $run;
    }

    /**
     * Phase 5: return an approved run that is not finalized yet to draft, when it can no longer be finalized as approved
     * (the engine version changed, a rule was superseded). Nothing was locked or issued, and it needs a new calculation
     * and an independent approval again. Always audited with a reason.
     *
     * Finalized and paid payroll is never reopened: its entries, lines and payslips stay as issued, because returns,
     * ledgers and compliance packs were built from them. A pay mistake is corrected with an ordinary arrear or recovery
     * in an open month (owner, 2026-10-28). A correction caused by a wrong statutory profile is settled as decision
     * C12-D1 decides (open; reopening is its option D). A refused attempt is audited too.
     */
    public function reopen(PayrollRun $run, string $reason, ?User $actor = null): PayrollRun
    {
        // Decided on the locked row, so a finalization that commits first is seen here and never overwritten from a stale copy.
        [$reopened, $refused] = DB::transaction(function () use ($run, $reason, $actor) {
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isLocked()) {
                return [null, $locked->status];
            }
            if ($locked->status !== 'approved') {
                throw new RuntimeException('Only an approved run that is not finalized yet can be reopened.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('A reason is required to reopen payroll.');
            }

            $locked->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
            $this->audit->record(AuditAction::PayrollReopened, 'payroll', $locked, [['field' => 'status', 'before' => 'approved', 'after' => 'draft']], $reason, actor: $actor);

            return [$locked->refresh(), null];
        });

        if ($refused !== null) {
            $this->audit->record(AuditAction::Rejected, 'payroll', $run, [], trim($reason) === '' ? null : $reason, actor: $actor, metadata: ['attempt' => 'reopen', 'status' => $refused]);

            throw new RuntimeException("A {$refused} run cannot be reopened: finalized payroll stays as issued. Correct a pay mistake with an arrear or a recovery in an open month; a correction caused by a wrong statutory profile waits for decision C12-D1.");
        }

        return $reopened;
    }

    private function assertEditable(PayrollRun $run): void
    {
        if (! $run->isEditable()) {
            throw new RuntimeException("A {$run->status} run cannot be recalculated. Reopen it first.");
        }
    }
}
