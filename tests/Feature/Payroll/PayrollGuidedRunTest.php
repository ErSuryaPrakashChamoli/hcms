<?php

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollPrerunCheck;
use App\Domain\Payroll\Models\PayrollReopening;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\MyPay;
use App\Domain\Payroll\Services\PayrollMonthReopen;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Domain\Payroll\Services\PayrollRunChecklist;
use App\Domain\Payroll\Services\PayrollRunCockpit;
use App\Domain\Payroll\Services\PayrollRunReview;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\PayslipPublishing;
use App\Domain\Payroll\Services\VariablePay;
use App\Domain\Platform\Services\SettingsRepository;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/*
| Streamline S2: the guided payroll run. Payslips reach employees only once published (ST-2); the latest closed month can
| be reopened before it is paid or filed, giving back everything closing did (ST-1); the pre-payroll checklist must be
| ticked, ticks lapse when what they counted changes, and attendance comes first (ST-3, ST-4); a leaver whose full and
| final settlement already pays the month is left out of the month's run.
*/

beforeEach(function () {
    $this->travelTo('2026-10-03 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'attendance.*', 'exit.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->employee = salariedEmployee(600000);
    AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => '2026-09-01', 'status' => 'present', 'processed_at' => now()]);
    $this->close = function (int $month = 9): PayrollRun {
        $run = $this->runs->open($this->company, 2026, $month, $this->preparer);

        return $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($run, $this->preparer)), $this->approver, 'ok'), $this->approver);
    };
});

it('keeps payslips from employees until they are published, then tells them once', function () {
    $run = ($this->close)();
    $payslip = Payslip::query()->sole();

    expect($run->payslips_published_at)->toBeNull()
        ->and(Payslip::query()->published()->count())->toBe(0);

    $run = app(PayslipPublishing::class)->publish($run, $this->approver);

    expect($run->payslips_published_at)->not->toBeNull()
        ->and((int) $run->payslips_published_by)->toBe($this->approver->id)
        ->and(Payslip::query()->published()->pluck('id')->all())->toBe([$payslip->id])
        ->and(AuditEvent::query()->where('action', 'PAYSLIPS_PUBLISHED')->count())->toBe(1)
        ->and(fn () => app(PayslipPublishing::class)->publish($run, $this->approver))->toThrow(RuntimeException::class, 'already published');

    // The snapshot stays as issued; only the employee's first view is recorded on it.
    $payslip->update(['viewed_at' => now()]);
    expect(fn () => $payslip->refresh()->update(['number' => 'PS-X']))->toThrow(RuntimeException::class, 'kept as issued and cannot be changed')
        ->and(fn () => $payslip->refresh()->update(['snapshot' => []]))->toThrow(RuntimeException::class, 'kept as issued and cannot be changed');
});

it('publishes payslips as the month closes when the organisation chose so', function () {
    app(SettingsRepository::class)->set('payroll.payslips.release', PayslipPublishing::AT_CLOSE);

    $run = ($this->close)();

    expect($run->payslips_published_at)->not->toBeNull()->and(Payslip::query()->published()->count())->toBe(1);
});

it('refuses to publish an open month', function () {
    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);

    expect(fn () => app(PayslipPublishing::class)->publish($run, $this->approver))->toThrow(RuntimeException::class, 'Close payroll before publishing');
});

it('reopens the latest closed month, gives back what closing did, and re-issues payslips with a revision number', function () {
    $type = LeaveType::query()->orderBy('id')->value('id');
    $encashment = LeaveEncashment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type, 'period_year' => 2026, 'days' => 2, 'status' => 'approved', 'reason' => 'Year end']);
    $run = app(PayslipPublishing::class)->publish(($this->close)(), $this->approver);
    $issued = Payslip::query()->sole();

    expect($encashment->refresh()->status)->toBe('paid')
        ->and(AttendanceRecord::query()->value('is_locked'))->toBeTrue()
        ->and(app(PayrollMonthReopen::class)->blocker($run))->toBeNull()
        ->and(fn () => app(PayrollMonthReopen::class)->reopen($run, '  ', $this->approver))->toThrow(RuntimeException::class, 'A reason is required');

    $run = app(PayrollMonthReopen::class)->reopen($run, 'Missed an overtime adjustment', $this->approver);

    expect($run->status)->toBe('draft')
        ->and($run->reopen_count)->toBe(1)
        ->and($run->payslips_published_at)->toBeNull()
        ->and(PayrollPeriod::query()->whereKey($run->payroll_period_id)->value('status'))->toBe('open')
        ->and(AttendanceRecord::query()->value('is_locked'))->toBeFalse()
        ->and(Payslip::query()->count())->toBe(0)
        ->and($encashment->refresh()->status)->toBe('approved');

    $reopening = PayrollReopening::query()->sole();
    expect($reopening->generation)->toBe(1)
        ->and($reopening->previous_status)->toBe('finalized')
        ->and($reopening->payslips[0]['number'])->toBe($issued->number)
        ->and($reopening->given_back)->toBe(['leave_encashment' => 1])
        ->and(fn () => $reopening->update(['reason' => 'x']))->toThrow(RuntimeException::class, 'kept as it was recorded')
        ->and(AuditEvent::query()->where('action', 'PAYROLL_REOPENED')->sole()->metadata['closed_month'])->toBeTrue();

    // A fresh calculation, an independent approval and a close follow; the encashment is paid again, once.
    $run = $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($run, $this->preparer)), $this->approver, 'ok'), $this->approver);

    expect(Payslip::query()->sole()->number)->toBe($issued->number.'-R1')
        ->and($encashment->refresh()->status)->toBe('paid')
        ->and(PayrollPeriod::query()->whereKey($run->payroll_period_id)->value('status'))->toBe('closed');
});

it('gives back variable pay, an attendance arrear and a loan payout when the month is reopened, and settles them again on the next close', function () {
    $incentive = SalaryComponent::create(['name' => 'Sales incentive', 'code' => 'INCENT', 'type' => 'earning', 'classification' => 'incentive', 'calculation_method' => 'fixed', 'pay_nature' => 'variable', 'taxable' => true,
        'include_in_ctc' => false, 'include_in_gross' => false, 'is_recurring' => false, 'sort_order' => 110, 'status' => 'active']);
    $variable = app(VariablePay::class)->request($this->employee, $incentive, 5000, '2026-09', 'Quarter');
    app(VariablePay::class)->approve($variable, 'ok', $this->approver);
    $arrear = AttendanceArrear::create(['employee_id' => $this->employee->id, 'date' => '2026-08-10', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    $loan = app(Loans::class)->request($this->employee, 'personal', 12000, 6, 'Medical', null, null, '2026-10');
    app(Loans::class)->approve($loan, 'ok', $this->approver);
    $states = fn () => [$variable->refresh()->status, $arrear->refresh()->status, $loan->refresh()->status];

    $run = ($this->close)();
    expect($states())->toBe(['paid', 'settled', 'active']);

    $run = app(PayrollMonthReopen::class)->reopen($run, 'Incentive amount was wrong', $this->approver);
    expect($states())->toBe(['approved', 'pending', 'approved'])
        ->and($variable->payroll_run_id)->toBeNull()->and($arrear->settled_period_id)->toBeNull()->and($loan->disbursed_on)->toBeNull()
        ->and(PayrollReopening::query()->sole()->given_back)->toMatchArray(['variable_pay' => 1, 'attendance_arrear' => 1, 'loan_disbursement' => 1]);

    $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($run, $this->preparer)), $this->approver, 'ok'), $this->approver);
    expect($states())->toBe(['paid', 'settled', 'active']);
});

it('makes a recovered loan instalment due again when its month is reopened, and recovers it once on the next close', function () {
    $loan = app(Loans::class)->request($this->employee, 'personal', 12000, 6, 'Medical', null, null, '2026-10');
    app(Loans::class)->approve($loan, 'ok', $this->approver);
    ($this->close)(); // September pays the loan out
    $paidOut = (float) $loan->refresh()->outstanding;
    AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => '2026-10-01', 'status' => 'present', 'processed_at' => now()]);
    $this->travelTo('2026-11-03 09:00:00');
    $october = ($this->close)(10);
    $first = fn () => $loan->schedule()->orderBy('sequence')->first();

    expect($first()->status)->toBe('recovered')->and($first()->payroll_run_id)->toBe($october->id)
        ->and((float) $loan->refresh()->outstanding)->toBeLessThan($paidOut);

    $october = app(PayrollMonthReopen::class)->reopen($october, 'Recovery should have been deferred', $this->approver);
    expect($first()->status)->toBe('due')->and($first()->payroll_run_id)->toBeNull()->and($first()->recovered_on)->toBeNull()
        ->and((float) $loan->refresh()->outstanding)->toBe($paidOut)->and($loan->status)->toBe('active')
        ->and(PayrollReopening::query()->sole()->given_back)->toMatchArray(['loan_recovery' => 1]);

    $october = $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($october, $this->preparer)), $this->approver, 'ok'), $this->approver);
    expect($first()->status)->toBe('recovered')->and($first()->payroll_run_id)->toBe($october->id)
        ->and($loan->schedule()->where('status', 'recovered')->count())->toBe(1);
});

it('refuses to reopen a paid month, a month with a later closed month, or one already open', function () {
    $september = ($this->close)();
    AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => '2026-10-01', 'status' => 'present', 'processed_at' => now()]);
    $this->travelTo('2026-11-03 09:00:00');
    $october = ($this->close)(10);

    expect(app(PayrollMonthReopen::class)->blocker($september->refresh()))->toContain('Only the latest closed month')
        ->and(fn () => app(PayrollMonthReopen::class)->reopen($september, 'Fix', $this->approver))->toThrow(RuntimeException::class, 'Only the latest closed month');

    $paid = $this->runs->markPaid($october, '2026-10-31', $this->approver);
    expect(fn () => app(PayrollMonthReopen::class)->reopen($paid, 'Fix', $this->approver))->toThrow(RuntimeException::class, 'marked as paid');

    $open = $this->runs->open($this->company, 2026, 11, $this->preparer);
    expect(app(PayrollMonthReopen::class)->blocker($open))->toBe('Only a closed payroll month can be reopened.');
});

it('leaves out of the month\'s run a leaver whose approved full and final settlement already pays the month', function () {
    $leaver = salariedEmployee(480000);
    $leaver->forceFill(['exit_date' => '2026-09-20'])->saveQuietly();
    $case = ExitCase::query()->create(['number' => 'EXIT-S2-1', 'employee_id' => $leaver->id, 'type' => 'resignation', 'status' => 'completed', 'initiated_on' => '2026-08-20', 'last_working_day' => '2026-09-20', 'notice_days' => 0]);
    FinalSettlement::query()->create(['exit_case_id' => $case->id, 'employee_id' => $leaver->id, 'status' => 'approved',
        'inputs' => ['last_working_day' => '2026-09-20', 'salary_period' => 'Sep 2026', 'salary_already_paid' => false]]);
    [$start, $end] = app(PayrollRunChecklist::class)->bounds($this->company->id, 2026, 9);

    expect($this->runs->settledInFinalSettlement($this->company->id, $start, $end))->toBe([$leaver->id])
        ->and($this->runs->populationIn($this->company->id, $start, $end)->pluck('id')->all())->not->toContain($leaver->id);
});

it('gives every check a status, asks for a tick only where there is something to look at, lapses it on a change, and never ticks a blocked one', function () {
    $checklist = app(PayrollRunChecklist::class);
    $type = LeaveType::query()->orderBy('id')->value('id');
    LeaveEncashment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type, 'period_year' => 2026, 'days' => 1, 'status' => 'approved', 'reason' => 'Year end']);
    LeaveEncashment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type, 'period_year' => 2026, 'days' => 1, 'status' => 'pending', 'reason' => 'Waiting']);
    $items = collect($checklist->items($this->company->id, 2026, 9))->keyBy('key');

    expect($items->keys()->all())->toBe(array_keys(PayrollRunChecklist::ITEMS))
        ->and($items->every(fn ($i) => in_array($i['status'], [PayrollRunChecklist::PASS, PayrollRunChecklist::WARNING, PayrollRunChecklist::PENDING_APPROVAL, PayrollRunChecklist::BLOCKED, PayrollRunChecklist::NOT_APPLICABLE], true) && filled($i['module']) && filled($i['as_of'])))->toBeTrue()
        // Nothing to look at: passes without a tick. Approved and waiting encashments: a warning that needs one.
        ->and($items['holds']['status'])->toBe(PayrollRunChecklist::PASS)->and($items['holds']['needs_tick'])->toBeFalse()
        ->and($items['encashments']['status'])->toBe(PayrollRunChecklist::WARNING)->and($items['encashments']['pending'])->toBe(1)
        // No earlier closed month to compare with: last month's check does not apply.
        ->and($items['last_month']['status'])->toBe(PayrollRunChecklist::NOT_APPLICABLE)
        ->and($checklist->blockers($this->company->id, 2026, 9, $items->values()->all()))->toContain('Leave encashment: not checked yet.')
        ->and(fn () => $checklist->tick($this->company->id, 2026, 9, 'holds', $this->preparer))->toThrow(RuntimeException::class, 'nothing to check');

    foreach ($items->where('needs_tick', true) as $key => $item) {
        $checklist->tick($this->company->id, 2026, 9, $key, $this->preparer);
    }
    $items = collect($checklist->items($this->company->id, 2026, 9))->keyBy('key');
    expect($items->where('needs_tick', true)->every(fn ($i) => $i['ticked']))->toBeTrue()
        ->and(PayrollPrerunCheck::query()->count())->toBe($items->where('needs_tick', true)->count())
        ->and(PayrollRunChecklist::summary($items->values()->all())['to_tick'])->toBe(0);

    // Another approved encashment changes what "Leave encashment" counted: its tick lapses.
    LeaveEncashment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type, 'period_year' => 2026, 'days' => 2, 'status' => 'approved', 'reason' => 'More']);
    $encashments = collect($checklist->items($this->company->id, 2026, 9))->firstWhere('key', 'encashments');
    expect($encashments['ticked'])->toBeFalse()->and($encashments['lapsed'])->toBeTrue()->and($encashments['count'])->toBe(3);

    // Only waiting approvals: pending approval.
    Loan::create(['employee_id' => $this->employee->id, 'number' => 'LN-S2-1', 'loan_type' => 'personal', 'principal' => 20000, 'installments' => 10, 'emi' => 2000, 'first_due_month' => '2026-10', 'reason' => 'x', 'status' => 'pending']);
    expect(collect($checklist->items($this->company->id, 2026, 9))->firstWhere('key', 'loans')['status'])->toBe(PayrollRunChecklist::PENDING_APPROVAL);

    // Someone without a salary structure blocks the run, and that check cannot be ticked.
    $unpaid = employeeWithUser();
    forceLifecycle($unpaid, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    $structure = collect($checklist->items($this->company->id, 2026, 9))->firstWhere('key', 'structure_missing');
    expect($structure['status'])->toBe(PayrollRunChecklist::BLOCKED)
        ->and(fn () => $checklist->tick($this->company->id, 2026, 9, 'structure_missing', $this->preparer))->toThrow(RuntimeException::class, 'clear the');
});

it('skips a month that is not ready when payroll recalculates on its set day, and says why', function () {
    app(SettingsRepository::class)->set('payroll.auto_calculate_day', 3);
    $run = $this->runs->open($this->company, 2026, 9, $this->preparer);

    expect(app(PayrollRunCockpit::class)->autoCalculate(now()))->toBe(0)
        ->and($run->refresh()->status)->toBe('draft')
        ->and(AuditEvent::query()->where('action', 'SCHEDULED')->where('entity_type', PayrollRun::class)->where('entity_id', (string) $run->id)->sole()->metadata['skipped'])->not->toBeEmpty();
});

it('reviews a calculated month against the last closed one, with unusual changes', function () {
    $september = ($this->close)();
    AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => '2026-10-01', 'status' => 'present', 'processed_at' => now()]);
    $this->travelTo('2026-11-03 09:00:00');
    PayrollAdjustment::create(['employee_id' => $this->employee->id, 'payroll_period_id' => PayrollPeriod::for($this->company, 2026, 10)->id,
        'type' => 'earning', 'name' => 'Festival bonus', 'amount' => 25000, 'taxable' => true, 'status' => 'approved']);
    $october = $this->runs->calculate($this->runs->open($this->company, 2026, 10, $this->preparer), $this->preparer);
    $review = app(PayrollRunReview::class)->summary($october);

    expect($review['previous']['label'])->toBe('Sep 2026')
        ->and($review['totals']['employees'])->toBe(1)
        ->and($review['change']['net'])->toBeGreaterThan(0)
        ->and($review['variances'])->toHaveCount(1)
        ->and($review['variances'][0]['code'])->toBe($this->employee->employee_code)
        ->and($review['variances'][0]['change'])->toBeGreaterThan(1000.0)
        ->and($review['new_in_run'])->toBe(0)->and($review['missing_from_run'])->toBe(0);
});

it('puts attendance first: the run waits until the month is processed', function () {
    $checklist = app(PayrollRunChecklist::class);
    $attendance = $checklist->attendance($this->company->id, 2026, 9);

    expect($attendance['ended'])->toBeTrue()
        ->and($attendance['done'])->toBe($attendance['incomplete'] === 0 && ! $attendance['running'])
        ->and($checklist->blockers($this->company->id, 2026, 9))->toContain(...($attendance['done'] ? [] : ['Attendance first: '.implode(' ', $attendance['reasons'])]));

    app(SettingsRepository::class)->set('payroll.run.attendance_first', false);
    expect(collect($checklist->blockers($this->company->id, 2026, 9))->filter(fn ($r) => str_starts_with($r, 'Attendance first'))->all())->toBe([]);
});

it('checks the pay month it is asked about, not the one holding today', function () {
    $checks = app(PayrollReadiness::class)->evaluate($this->company->id, payMonth: [2026, 9]);

    expect($checks['months'][0]['label'])->toBe('Sep 2026');
});

it('shows an employee only published payslips on their own pay pages', function () {
    $run = ($this->close)();
    $user = $this->employee->user()->firstOrFail();
    $this->actingAs($user);
    $months = fn () => app(MyPay::class)->payslips($user, $this->employee, app(FinancialYear::class)->label(now()))->count();

    expect($months())->toBe(0);
    app(PayslipPublishing::class)->publish($run, $this->approver);
    expect($months())->toBe(1);
});
