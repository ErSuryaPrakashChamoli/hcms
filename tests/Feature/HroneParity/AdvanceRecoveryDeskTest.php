<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Exit\Services\Exits;
use App\Domain\Exit\Services\FinalSettlements;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule;
use App\Domain\Expense\Services\AdvanceRecoveries;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Settlement\ExpenseSettlementSource;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AdvanceLedger;
use App\Filament\Pages\AdvanceRecovery;
use App\Filament\Pages\DispenseAdvances;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity XO-6 (matrix 26 §26.3.4 #1–#4, #6–#10; §26.3.1 #8; §26.7 #5): the Advance recovery desk — one row per
| employee owing money on dispensed advances (outstanding, oldest settle-by, scheduled recovery, last report submitted),
| Unrecovered / Recovered tabs, WO-2 organisation / employee-status filters and "no report for N days", access scope;
| the employee's ledger with the running outstanding; "Recover through payroll" (owner decision D-XO2 / EXP-8: a scheduled
| recovery takes precedence for its advance, read first by ExpenseAdvanceRecoveryInput; no schedule = today's automatic
| recovery) with the never-twice guards; refunds outside payroll by bank transfer, cheque or cash; WO-1 bulk with a summary.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'expense.*', 'exit.*', 'bulk.*', 'audit.view']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['expense.claim']);
    $this->user = $this->employee->user;
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    xo6Policy('expense', ['categories' => [['category' => $this->cab->id]]]);
    xo6Policy('expense_advance', ['enabled' => true, 'max_open' => 10, 'max_amount' => 50000, 'settle_within_days' => 10, 'recovery' => 'payroll', 'recovery_after_days' => 5]);
    $this->advances = app(ExpenseAdvances::class);
    $this->recoveries = app(AdvanceRecoveries::class);
});

/** @param  list<Employee>  $only */
function xo6Policy(string $type, array $settings, array $only = []): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'XO6_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    $conditions = $only === [] ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => array_map(fn (Employee $e) => $e->id, $only)]];
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $only === [] ? 1000 : 50]);

    return $policy;
}

/** @return array{0: User, 1: Employee} an employee of the company (no salary) */
function xo6Person(Company $company, array $permissions = ['expense.claim']): array
{
    $user = tenantUser(test()->tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);

    return [$user, $employee];
}

/** An advance asked for, approved and dispensed (by bank) on $on — travelling there and back. */
function xo6Dispensed(Employee $employee, User $as, float $amount, string $on = '2026-09-21', ?User $payer = null): ExpenseAdvance
{
    $now = now()->toDateTimeString();
    test()->travelTo($on.' 09:00:00');
    $advances = app(ExpenseAdvances::class);
    $advance = $advances->approve($advances->request($employee, ['amount' => $amount, 'purpose' => 'Client visit'], $as), null, null, $payer ?? test()->hr);
    $advance = $advances->dispense($advance->refresh(), 'UTR-'.Str::upper(Str::random(6)), $on, $payer ?? test()->hr);
    test()->travelTo($now);

    return $advance->refresh();
}

/** The month's payroll run of the company, calculated (again). */
function xo6Run(int $month): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $open = PayrollRun::query()->whereHas('period', fn ($q) => $q->where('year', 2026)->where('month', $month))->whereNotIn('status', ['finalized', 'paid'])->first();

    return $runs->calculate($open ?? $runs->open(test()->company, 2026, $month, test()->hr), test()->hr);
}

function xo6Finalize(PayrollRun $run): PayrollRun
{
    $runs = app(PayrollRuns::class);

    return $runs->finalize($runs->approve($runs->validate($run), test()->approver, 'ok'), test()->approver);
}

/** @return list<PayrollEntryLine> the run's advance recovery lines of the employee */
function xo6Lines(PayrollRun $run, ?Employee $employee = null): array
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id)->where('employee_id', ($employee ?? test()->employee)->id))
        ->where('code', 'EXP_ADV_RECOVERY')->orderBy('id')->get()->all();
}

/** @return array<int, array<string, mixed>> the desk's rows (employee id => figures) */
function xo6Rows($page): array
{
    return collect($page->instance()->getTableRecords()->items())->keyBy(fn (Employee $e) => (int) $e->id)
        ->map(fn (Employee $e) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, array_intersect_key($e->getAttributes(),
            array_flip(['outstanding_total', 'advances_count', 'oldest_settle_by', 'last_report_at', 'recovered_total', 'automatic_from', 'payroll_recovered', 'refunded_total', 'settlement_recovered']))))->all();
}

function xo6Csv(AdvanceRecovery $page): string
{
    ob_start();
    $page->downloadCsv()->sendContent();

    return (string) ob_get_clean();
}

// ---- Defaults --------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour without a scheduled recovery: the automatic date recovers the whole outstanding, once, and a plain refund is recorded as before', function () {
    $advance = xo6Dispensed($this->employee, $this->user, 3000, '2026-08-01');
    expect($advance->recovery_due_on->toDateString())->toBe('2026-08-16')->and(ExpenseAdvanceRecoverySchedule::query()->count())->toBe(0);

    // A plain refund (no mode) is recorded exactly as before.
    $this->advances->refund($advance, 500, 'CASH-1', '2026-09-21', $this->hr);
    $refund = ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'refunded')->sole();
    expect($refund->note)->toBe('Refunded by the employee')->and($refund->only(['refund_mode', 'bank_name', 'cheque_number', 'cheque_date']))->toBe(['refund_mode' => null, 'bank_name' => null, 'cheque_number' => null, 'cheque_date' => null])
        ->and($refund->refundModeLabel())->toBeNull();

    $september = xo6Run(9);
    $lines = xo6Lines($september);
    expect($lines)->toHaveCount(1)->and((float) $lines[0]->amount)->toBe(2500.0)
        ->and($lines[0]->basis)->toEqual(['advance' => $advance->number, 'settle_by' => '2026-08-11', 'recovery_due_on' => '2026-08-16', 'source' => ['key' => 'expense_advance_recovery', 'ids' => [$advance->id]]]);
    $september = xo6Finalize($september);
    $advance->refresh();
    expect($advance->status)->toBe('recovered')->and((float) $advance->outstanding)->toBe(0.0)
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->sole()->entry_key)->toBe('payroll-'.$september->id);

    // Never twice.
    $this->travelTo('2026-10-25 09:00:00');
    expect(xo6Lines(xo6Run(10)))->toBe([]);
});

// ---- The desk --------------------------------------------------------------------------------------------------------

it('lists one row per employee owing money, with the tabs, columns, filters, CSV and access scope', function () {
    $south = Company::factory()->create(['name' => 'South']);
    [$bUser, $b] = xo6Person($south);
    [$cUser, $c] = xo6Person($this->company);
    // The HR person's own employee record never appears on their desk.
    $ownEmployee = Employee::factory()->create(['user_id' => $this->hr->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $ownEmployee->id, 'company_id' => $this->company->id, 'effective_from' => '2025-01-01']);
    $payer = tenantUser($this->tenant, ['expense.*']);

    $a1 = xo6Dispensed($this->employee, $this->user, 3000, '2026-08-01');
    $a2 = xo6Dispensed($this->employee, $this->user, 2000, '2026-09-21');
    $b1 = xo6Dispensed($b, $bUser, 1000, '2026-09-15');
    $c1 = xo6Dispensed($c, $cUser, 700, '2026-09-10');
    xo6Dispensed($ownEmployee, $this->hr, 400, '2026-09-10', $payer);
    // C pays everything back: recovered, nothing outstanding.
    $this->advances->refund($c1, 700, 'UTR-C', '2026-09-20', $this->hr);
    // B submitted a report on 10 Sep.
    $this->travelTo('2026-09-10 10:00:00');
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($b, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-09', 'amount' => 100], $bUser);
    $reports->submit($reports->create($b, 'September', [$receipt->id], $bUser), $bUser);
    $this->travelTo('2026-09-21 09:00:00');

    $page = Livewire::test(AdvanceRecovery::class)->assertOk()->assertSet('recoveryTab', 'unrecovered')
        ->assertCanSeeTableRecords([$this->employee, $b])->assertCanNotSeeTableRecords([$c, $ownEmployee])
        ->assertTableColumnExists('outstanding_total')->assertTableColumnExists('oldest_settle_by')->assertTableColumnExists('scheduled_recovery')->assertTableColumnExists('last_report_at')
        ->assertSee('Never')->assertTableActionVisible('recoverPayroll', $this->employee)->assertTableActionVisible('recoverOutside', $this->employee);
    $rows = xo6Rows($page);
    expect($rows[$this->employee->id]['outstanding_total'])->toBe(5000.0)->and($rows[$this->employee->id]['advances_count'])->toBe(2.0)
        ->and(substr((string) $rows[$this->employee->id]['oldest_settle_by'], 0, 10))->toBe('2026-08-11')
        ->and(substr((string) $rows[$this->employee->id]['automatic_from'], 0, 10))->toBe('2026-08-16')
        ->and($rows[$this->employee->id]['last_report_at'])->toBeNull()
        ->and(substr((string) $rows[$b->id]['last_report_at'], 0, 10))->toBe('2026-09-10')->and($rows[$b->id]['outstanding_total'])->toBe(1000.0);

    // A scheduled recovery shows with its month and amount; the automatic date leaves the scheduled advance.
    $this->recoveries->schedule($a1, '2026-11', 1200, null, $this->hr);
    $page = Livewire::test(AdvanceRecovery::class)->assertSee('Nov 2026 · 1,200.00');
    expect(substr((string) xo6Rows($page)[$this->employee->id]['automatic_from'], 0, 10))->toBe('2026-10-06');

    // Search (WO-2), "no report for N days", organisation and employee status, recovery and settle-by filters.
    Livewire::test(AdvanceRecovery::class)->searchTable($b->employee_code)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$this->employee]);
    Livewire::test(AdvanceRecovery::class)->filterTable('no_report', ['days' => 30])->assertCanSeeTableRecords([$this->employee])->assertCanNotSeeTableRecords([$b]);
    Livewire::test(AdvanceRecovery::class)->filterTable('no_report', ['days' => 5])->assertCanSeeTableRecords([$this->employee, $b]);
    Livewire::test(AdvanceRecovery::class)->filterTable('organisation', ['company_id' => [$south->id]])->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$this->employee]);
    forceLifecycle($b, LifecycleState::NoticePeriod);
    Livewire::test(AdvanceRecovery::class)->filterTable('workforce', ['lifecycle_state' => ['notice_period']])->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$this->employee]);
    Livewire::test(AdvanceRecovery::class)->filterTable('recovery', 'scheduled')->assertCanSeeTableRecords([$this->employee])->assertCanNotSeeTableRecords([$b]);
    Livewire::test(AdvanceRecovery::class)->filterTable('recovery', 'none')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$this->employee]);
    Livewire::test(AdvanceRecovery::class)->filterTable('overdue', true)->assertCanSeeTableRecords([$this->employee])->assertCanNotSeeTableRecords([$b]);

    // Recovered: nothing outstanding, money came back.
    $recovered = Livewire::test(AdvanceRecovery::class)->call('setTab', 'recovered')->assertSet('recoveryTab', 'recovered')
        ->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$this->employee, $b, $ownEmployee]);
    expect(xo6Rows($recovered)[$c->id]['refunded_total'])->toBe(700.0)->and(xo6Rows($recovered)[$c->id]['payroll_recovered'])->toBe(0.0);
    Livewire::withQueryParams(['view' => 'recovered'])->test(AdvanceRecovery::class)->assertSet('recoveryTab', 'recovered')->assertCanSeeTableRecords([$c]);
    Livewire::withQueryParams([]);

    // The CSV of the tab, audited.
    $csv = xo6Csv(Livewire::test(AdvanceRecovery::class)->instance());
    expect($csv)->toContain('"Employee code",Employee,Outstanding,Advances,"Oldest settle-by","Scheduled recovery"')->toContain($this->employee->employee_code)->toContain('5000.00')
        ->toContain('Nov 2026 · 1,200.00')
        ->and(AuditEvent::query()->where('action', 'EXPORT')->where('module', 'expense')->latest('id')->first()?->metadata)->toMatchArray(['export' => 'advance_recovery', 'tab' => 'unrecovered', 'rows' => 2]);

    // Access scope: a payer limited to the first company sees only its employees; the desk needs expense.pay.
    $scoped = tenantUser($this->tenant, ['expense.pay', 'employee.view']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $this->actingAs($scoped);
    Livewire::test(AdvanceRecovery::class)->assertCanSeeTableRecords([$this->employee])->assertCanNotSeeTableRecords([$b]);
    expect(fn () => $this->recoveries->schedule($b1, '2026-11', 100, null, $scoped))->toThrow(ExpenseRuleViolation::class, 'You cannot recover this advance.');
    $this->actingAs(tenantUser($this->tenant, ['expense.view']));
    expect(AdvanceRecovery::canAccess())->toBeFalse();
    $this->get(AdvanceRecovery::getUrl())->assertForbidden();
    // One's own advances are never recovered by oneself.
    $this->actingAs($this->hr);
    expect(fn () => $this->recoveries->scheduleForEmployee($ownEmployee, '2026-11', null, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'You cannot recover your own advances.');
});

// ---- Ledger ----------------------------------------------------------------------------------------------------------

it('shows the employee\'s ledger across advances with the running outstanding, to them and to HR in scope', function () {
    $a1 = xo6Dispensed($this->employee, $this->user, 3000, '2026-08-01');
    $a2 = xo6Dispensed($this->employee, $this->user, 2000, '2026-08-20');
    $this->travelTo('2026-09-15 09:00:00');
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-14', 'amount' => 800], $this->user);
    $report = $reports->submit($reports->create($this->employee, 'September', [$receipt->id], $this->user), $this->user);
    $reports->approvePart($report->parts()->sole(), null, null, $this->hr);
    $this->travelTo('2026-09-21 09:00:00');
    $this->advances->refund($a2, 500, 'CHQ-1', '2026-09-20', $this->hr, ['mode' => 'cheque', 'bank_name' => 'HDFC Bank', 'cheque_number' => '004512', 'cheque_date' => '2026-09-19']);

    $rows = $this->recoveries->ledger($this->employee);
    expect(array_map(fn (array $r) => [$r['date'], $r['advance'], $r['opening'], $r['change'], $r['closing']], $rows))->toBe([
        ['2026-08-01', $a1->number, 0.0, 3000.0, 3000.0],
        ['2026-08-20', $a2->number, 3000.0, 2000.0, 5000.0],
        ['2026-09-15', $a1->number, 5000.0, -800.0, 4200.0],
        ['2026-09-20', $a2->number, 4200.0, -500.0, 3700.0],
    ])->and($rows[3]['kind'])->toBe('Refunded')->and($rows[3]['reason'])->toContain('Cheque 004512 of 19 Sep 2026 · HDFC Bank')
        ->and(end($rows)['closing'])->toBe(round((float) ExpenseAdvance::query()->where('employee_id', $this->employee->id)->sum('outstanding'), 2));

    // The page, for HR (from the desk, Dispense advances and the advance page) and for the employee.
    $this->get(AdvanceLedger::getUrl(['employee' => $this->employee->id]))->assertOk()->assertSee('Advance ledger')->assertSee('3,700.00')->assertSee('Cheque 004512');
    Livewire::test(AdvanceRecovery::class)->assertTableActionHasUrl('ledger', AdvanceLedger::getUrl(['employee' => $this->employee->id]), $this->employee);
    Livewire::withQueryParams(['tab' => 'dispensed'])->test(DispenseAdvances::class)->assertSee(AdvanceLedger::getUrl(['employee' => $this->employee->id]), false);
    $this->actingAs($this->user);
    $this->get(AdvanceLedger::getUrl(['employee' => $this->employee->id]))->assertOk()->assertSee('3,700.00')->assertDontSee('Recover through payroll');
    // Another employee's ledger, or an HR person outside the scope, gets nothing.
    [$otherUser, $other] = xo6Person($this->company);
    $this->get(AdvanceLedger::getUrl(['employee' => $other->id]))->assertNotFound();
    $scoped = tenantUser($this->tenant, ['expense.pay', 'employee.view']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'company', 'scope_id' => Company::factory()->create()->id]);
    $this->actingAs($scoped);
    $this->get(AdvanceLedger::getUrl(['employee' => $this->employee->id]))->assertNotFound();
});

// ---- Recover through payroll -----------------------------------------------------------------------------------------

it('recovers a scheduled recovery in exactly its month, once, ahead of the automatic date, which takes over afterwards', function () {
    $advance = xo6Dispensed($this->employee, $this->user, 3000, '2026-09-21');
    expect($advance->recovery_due_on->toDateString())->toBe('2026-10-06');

    Livewire::test(AdvanceRecovery::class)->callTableAction('recoverPayroll', $this->employee, ['month' => '2026-11', 'amount' => 1000, 'note' => 'Agreed with the employee'])
        ->assertHasNoTableActionErrors()->assertNotified('Recovery scheduled');
    $schedule = ExpenseAdvanceRecoverySchedule::query()->sole();
    expect($schedule->only(['expense_advance_id', 'employee_id', 'status', 'note', 'scheduled_by']))->toBe(['expense_advance_id' => $advance->id, 'employee_id' => $this->employee->id, 'status' => 'scheduled', 'note' => 'Agreed with the employee', 'scheduled_by' => $this->hr->id])
        ->and($schedule->recover_in->toDateString())->toBe('2026-11-01')->and((float) $schedule->amount)->toBe(1000.0)
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvanceRecoverySchedule::class)->where('entity_id', $schedule->id)->where('reason', "Recovery of {$advance->number} scheduled: Nov 2026 payroll, 1,000.00")->exists())->toBeTrue();

    // September and October: nothing — October's automatic date waits for the scheduled month.
    xo6Finalize(tap(xo6Run(9), fn (PayrollRun $r) => expect(xo6Lines($r))->toBe([])));
    $this->travelTo('2026-10-25 09:00:00');
    $october = xo6Run(10);
    expect(xo6Lines($october))->toBe([]);
    xo6Finalize($october);

    // November carries it: the scheduled amount, the schedule named; meanwhile nothing moves, cancels or refunds it.
    $this->travelTo('2026-11-25 09:00:00');
    $november = xo6Run(11);
    $lines = xo6Lines($november);
    expect($lines)->toHaveCount(1)->and((float) $lines[0]->amount)->toBe(1000.0)->and($lines[0]->basis['source'])->toEqual(['key' => 'expense_advance_recovery', 'ids' => [$advance->id]])
        ->and($lines[0]->basis['schedule'])->toBe($schedule->id)->and($lines[0]->basis['scheduled_for'])->toBe('2026-11')
        ->and(fn () => $this->recoveries->reschedule($schedule, '2026-12', null, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being recovered in the payroll of Nov 2026: it stays with that payroll')
        ->and(fn () => $this->recoveries->cancel($schedule, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'it stays with that payroll')
        ->and(fn () => $this->recoveries->schedule($advance->refresh(), '2026-12', 500, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being recovered in the payroll of Nov 2026')
        ->and(fn () => $this->advances->refund($advance->refresh(), 100, 'X-1', '2026-11-25', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being recovered');
    $november = xo6Finalize($november);

    $schedule->refresh();
    $advance->refresh();
    $entry = ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->sole();
    expect($schedule->status)->toBe('recovered')->and((float) $schedule->recovered_amount)->toBe(1000.0)->and($schedule->payroll_run_id)->toBe($november->id)
        ->and($schedule->recovered_on)->not->toBeNull()
        ->and((float) $entry->amount)->toBe(-1000.0)->and($entry->entry_key)->toBe('payroll-'.$november->id)
        ->and($advance->status)->toBe('disbursed')->and((float) $advance->outstanding)->toBe(2000.0)
        ->and(fn () => $this->recoveries->cancel($schedule, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'is recovered and cannot be changed');

    // Recovered once: December's run does not take it again; with no open schedule left, the automatic date applies again.
    $this->travelTo('2026-12-25 09:00:00');
    $december = xo6Run(12);
    $lines = xo6Lines($december);
    expect($lines)->toHaveCount(1)->and((float) $lines[0]->amount)->toBe(2000.0)->and($lines[0]->basis)->not->toHaveKey('schedule');
    xo6Finalize($december);
    expect($advance->refresh()->status)->toBe('recovered')->and((float) $advance->outstanding)->toBe(0.0)
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->count())->toBe(2)
        ->and($schedule->refresh()->status)->toBe('recovered');
});

it('keeps a scheduled amount within what is outstanding and not yet scheduled, spreading an employee\'s amount oldest settle-by first', function () {
    $old = xo6Dispensed($this->employee, $this->user, 3000, '2026-08-01');
    $new = xo6Dispensed($this->employee, $this->user, 2000, '2026-09-21');

    expect(fn () => $this->recoveries->schedule($old, '2026-11', 3000.01, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'Recover between 0.01 and what is outstanding on '.$old->number.' and not yet scheduled (3,000.00)')
        ->and(fn () => $this->recoveries->schedule($old, '2026-11', 0, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'Recover between 0.01');
    $this->recoveries->schedule($old, '2026-10', 1000, null, $this->hr);
    expect(fn () => $this->recoveries->schedule($old, '2026-10', 500, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'already has a recovery in Oct 2026')
        ->and(fn () => $this->recoveries->schedule($old, '2026-11', 2500, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'not yet scheduled (2,000.00)');
    $this->recoveries->schedule($old, '2026-11', 2000, null, $this->hr);
    expect($this->recoveries->unscheduledFor($this->employee))->toBe(2000.0);

    // An employee's amount: oldest settle-by first; $old is fully scheduled, so December goes to $new; never above the free amount.
    expect(fn () => $this->recoveries->scheduleForEmployee($this->employee, '2026-12', 2000.01, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'not yet scheduled for Dec 2026 (2,000.00)');
    $schedules = $this->recoveries->scheduleForEmployee($this->employee, '2026-12', 1500, null, $this->hr);
    expect($schedules->map(fn ($s) => [$s->expense_advance_id, (float) $s->amount])->all())->toBe([[$new->id, 1500.0]]);

    // Two advances in one go: blank = everything free, oldest first.
    // Salaried, so the company's payroll runs below have no employee without pay.
    $b = salariedEmployee(300000, ['expense.claim']);
    $bUser = $b->user;
    $b1 = xo6Dispensed($b, $bUser, 600, '2026-08-05');
    $b2 = xo6Dispensed($b, $bUser, 900, '2026-09-01');
    expect(app(AdvanceRecoveries::class)->preview($b, '2026-12', 1000))->toBe([$b1->number => 600.0, $b2->number => 400.0]);
    $schedules = $this->recoveries->scheduleForEmployee($b, '2026-12', 1000, null, $this->hr);
    expect($schedules->map(fn ($s) => [$s->expense_advance_id, (float) $s->amount])->all())->toBe([[$b1->id, 600.0], [$b2->id, 400.0]]);

    // The screen caps the amount at what is free, and refuses more.
    Livewire::test(AdvanceRecovery::class)->callTableAction('recoverPayroll', $b, ['month' => '2027-01', 'amount' => 600])->assertHasTableActionErrors(['amount' => 'max']);

    // A report settling the advance below its scheduled amount: payroll never recovers more than is outstanding.
    $this->travelTo('2026-10-02 09:00:00');
    $reports = app(ExpenseReports::class);
    $receipt = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-10-01', 'amount' => 2500], $this->user);
    $reports->approvePart($reports->submit($reports->create($this->employee, 'October', [$receipt->id], $this->user), $this->user)->parts()->sole(), null, null, $this->hr);
    expect((float) $old->refresh()->outstanding)->toBe(500.0);
    $this->travelTo('2026-10-25 09:00:00');
    xo6Finalize(xo6Run(9));
    $october = xo6Run(10);
    $lines = collect(xo6Lines($october))->keyBy(fn ($l) => $l->basis['source']['ids'][0]);
    expect((float) $lines[$old->id]->amount)->toBe(500.0);
    xo6Finalize($october);
    expect((float) $old->refresh()->outstanding)->toBe(0.0)->and($old->status)->toBe('recovered')
        // The advance closed first: its November recovery closes with it, unrecovered.
        ->and(ExpenseAdvanceRecoverySchedule::query()->where('expense_advance_id', $old->id)->whereDate('recover_in', '2026-11-01')->value('status'))->toBe('closed');
});

it('refuses a finalized month, flags a month finalized without the recovery and keeps it there until moved', function () {
    $advance = xo6Dispensed($this->employee, $this->user, 3000, '2026-09-21');
    $september = xo6Run(9);
    expect(app(PayrollMonths::class)->openFor($this->employee->id)['2026-09'])->toBe('Sep 2026 — payroll calculated: included when it is recalculated');

    // Scheduled for September after its run was calculated; September is finalized without recalculating.
    $schedule = $this->recoveries->schedule($advance, '2026-09', 1000, null, $this->hr);
    xo6Finalize($september);
    $schedule->refresh();
    expect($schedule->status)->toBe('scheduled')->and($this->recoveries->missed($schedule))->toBeTrue()
        ->and(fn () => $this->recoveries->schedule($advance->refresh(), '2026-09', 500, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'The payroll of Sep 2026 is finalized: choose a month not yet finalized')
        ->and(fn () => $this->recoveries->reschedule($schedule, '2026-09', 900, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'The payroll of Sep 2026 is finalized');
    Livewire::test(AdvanceRecovery::class)->assertSee('Sep 2026 finalized without it')->filterTable('recovery', 'missed')->assertCanSeeTableRecords([$this->employee]);
    $this->get(AdvanceLedger::getUrl(['employee' => $this->employee->id]))->assertSee('Sep 2026 finalized without it: change or cancel it');

    // Not silently moved: October takes neither the schedule nor (the automatic date having passed) the advance.
    $this->travelTo('2026-10-25 09:00:00');
    expect(xo6Lines(xo6Run(10)))->toBe([]);

    // Moved to October (its run open), the recalculated run recovers it, once.
    Livewire::withQueryParams(['employee' => $this->employee->id])->test(AdvanceLedger::class)
        ->callAction('reschedule', ['month' => '2026-10', 'amount' => 1000], ['schedule' => $schedule->id])->assertHasNoActionErrors()->assertNotified('Recovery changed');
    expect($schedule->refresh()->recover_in->toDateString())->toBe('2026-10-01')
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvanceRecoverySchedule::class)->where('entity_id', $schedule->id)->where('reason', 'Recovery month changed: Sep 2026 → Oct 2026')->exists())->toBeTrue();
    $october = xo6Finalize(xo6Run(10));
    expect($schedule->refresh()->status)->toBe('recovered')->and($schedule->payroll_run_id)->toBe($october->id)->and((float) $advance->refresh()->outstanding)->toBe(2000.0);
});

it('changes or cancels a scheduled recovery until a run carries it; cancelled, the automatic date applies again', function () {
    $advance = xo6Dispensed($this->employee, $this->user, 3000, '2026-09-21');
    $schedule = $this->recoveries->schedule($advance, '2026-11', 1000, 'First plan', $this->hr);

    foreach (['2026-13' => 'Choose the payroll month', 'Nov 2026' => 'Choose the payroll month', '2026-08' => 'not an open payroll month', '2027-10' => 'not an open payroll month'] as $month => $why) {
        expect(fn () => $this->recoveries->reschedule($schedule, $month, null, null, $this->hr))->toThrow(ExpenseRuleViolation::class, $why);
    }
    expect(fn () => $this->recoveries->reschedule($schedule, '2026-11', 1000, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'is already for Nov 2026 (1,000.00)')
        ->and(fn () => $this->recoveries->reschedule($schedule, '2026-12', 3000.5, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'not yet scheduled (3,000.00)');
    $this->recoveries->reschedule($schedule, '2026-12', 1500, 'Later', $this->hr);
    $schedule->refresh();
    expect($schedule->month())->toBe('2026-12')->and((float) $schedule->amount)->toBe(1500.0)->and($schedule->note)->toBe('Later')
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvanceRecoverySchedule::class)->where('entity_id', $schedule->id)->where('reason', 'Recovery month changed: Nov 2026 → Dec 2026; 1,000.00 → 1,500.00')->exists())->toBeTrue();
    $this->recoveries->reschedule($schedule, '2026-12', 1200, null, $this->hr);
    expect(AuditEvent::query()->where('entity_type', ExpenseAdvanceRecoverySchedule::class)->where('entity_id', $schedule->id)->where('reason', 'Recovery amount changed (Dec 2026): 1,500.00 → 1,200.00')->exists())->toBeTrue();

    // Cancelled from the ledger: the advance goes back to its automatic date (6 Oct) — October recovers it in full.
    Livewire::withQueryParams(['employee' => $this->employee->id])->test(AdvanceLedger::class)->assertActionVisible('cancelSchedule', ['schedule' => $schedule->id])
        ->callAction('cancelSchedule', ['reason' => 'Paying by cheque'], ['schedule' => $schedule->id])->assertNotified('Recovery cancelled');
    $schedule->refresh();
    expect($schedule->status)->toBe('cancelled')->and($schedule->cancelled_by)->toBe($this->hr->id)->and($schedule->cancel_reason)->toBe('Paying by cheque')
        ->and(fn () => $this->recoveries->reschedule($schedule, '2026-11', null, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'is cancelled and cannot be changed');
    xo6Finalize(xo6Run(9));
    $this->travelTo('2026-10-25 09:00:00');
    $lines = xo6Lines(xo6Run(10));
    expect($lines)->toHaveCount(1)->and((float) $lines[0]->amount)->toBe(3000.0)->and($lines[0]->basis)->not->toHaveKey('schedule');
});

// ---- Outside payroll ---------------------------------------------------------------------------------------------------

it('records recoveries outside payroll by bank transfer, cheque or cash, with every refusal', function () {
    $advance = xo6Dispensed($this->employee, $this->user, 3000, '2026-08-01');
    $refund = fn (array $details, string $ref = 'R-1') => $this->advances->refund($advance->refresh(), 100, $ref, '2026-09-20', $this->hr, $details);

    foreach ([
        [['mode' => 'card'], 'Choose how it was paid back'],
        [['bank_name' => 'HDFC'], 'Say how it was paid back before'],
        [['mode' => 'cash', 'bank_name' => 'HDFC'], 'A cash refund has no bank'],
        [['mode' => 'cheque', 'cheque_number' => '1', 'cheque_date' => '2026-09-19'], 'Give the bank of the cheque'],
        [['mode' => 'cheque', 'bank_name' => 'HDFC', 'cheque_date' => '2026-09-19'], 'Give the cheque number'],
        [['mode' => 'cheque', 'bank_name' => 'HDFC', 'cheque_number' => str_repeat('9', 31), 'cheque_date' => '2026-09-19'], 'Give the cheque number (at most 30 characters)'],
        [['mode' => 'cheque', 'bank_name' => 'HDFC', 'cheque_number' => '12'], 'Give the cheque date'],
        [['mode' => 'bank_transfer', 'cheque_number' => '12'], 'Cheque number and date go with a refund by cheque'],
        [['mode' => 'cash', 'note' => str_repeat('x', 256)], 'Keep the note to 255 characters'],
    ] as [$details, $why]) {
        expect(fn () => $refund($details))->toThrow(ExpenseRuleViolation::class, $why);
    }
    expect(ExpenseAdvanceEntry::query()->where('kind', 'refunded')->count())->toBe(0);

    $refund(['mode' => 'bank_transfer', 'bank_name' => 'ICICI Bank', 'note' => 'Returned the unused part'], 'UTR-77');
    $refund(['mode' => 'cheque', 'bank_name' => 'HDFC Bank', 'cheque_number' => '004512', 'cheque_date' => '2026-09-19'], 'Cheque 004512');
    $refund(['mode' => 'cash'], 'Cash 20 Sep 2026');
    $entries = ExpenseAdvanceEntry::query()->where('kind', 'refunded')->orderBy('id')->get();
    expect($entries->map(fn (ExpenseAdvanceEntry $e) => [$e->refund_mode, $e->bank_name, $e->cheque_number, $e->cheque_date?->toDateString(), $e->note])->all())->toBe([
        ['bank_transfer', 'ICICI Bank', null, null, 'Returned the unused part'],
        ['cheque', 'HDFC Bank', '004512', '2026-09-19', 'Refunded by the employee'],
        ['cash', null, null, null, 'Refunded by the employee'],
    ])->and($entries->map->refundModeLabel()->all())->toBe(['Bank transfer · ICICI Bank', 'Cheque 004512 of 19 Sep 2026 · HDFC Bank', 'Cash'])
        ->and((float) $advance->refresh()->outstanding)->toBe(2700.0)
        // Audited with the ledger entry.
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvanceEntry::class)->where('entity_id', $entries[1]->id)->exists())->toBeTrue();

    // From the desk: a cheque, spread oldest first; a blank reference becomes "Cheque <number>".
    $second = xo6Dispensed($this->employee, $this->user, 1000, '2026-09-10');
    Livewire::test(AdvanceRecovery::class)->callTableAction('recoverOutside', $this->employee, ['mode' => 'cheque', 'bank_name' => 'SBI', 'cheque_number' => '778899', 'cheque_date' => '2026-09-18',
        'amount' => 3000, 'reference' => '', 'on' => '2026-09-21'])->assertHasNoTableActionErrors()->assertNotified('Recovery recorded');
    expect((float) $advance->refresh()->outstanding)->toBe(0.0)->and($advance->status)->toBe('settled')->and((float) $second->refresh()->outstanding)->toBe(700.0)
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $second->id)->where('kind', 'refunded')->sole()->only(['reference', 'refund_mode', 'cheque_number']))
        ->toBe(['reference' => 'Cheque 778899', 'refund_mode' => 'cheque', 'cheque_number' => '778899']);
    // The desk asks for the mode and refuses more than is outstanding.
    Livewire::test(AdvanceRecovery::class)->callTableAction('recoverOutside', $this->employee, ['mode' => null, 'amount' => 100, 'reference' => 'X', 'on' => '2026-09-21'])->assertHasTableActionErrors(['mode' => 'required']);
    expect(fn () => $this->recoveries->refundForEmployee($this->employee, 700.01, 'X-9', '2026-09-21', ['mode' => 'cash'], $this->hr))->toThrow(ExpenseRuleViolation::class, 'Recover between 0.01 and what is outstanding (700.00)');

    // The advance page's "Record refund" keeps the mode optional (as before) and shows how on the ledger.
    Livewire::test(ListExpenseAdvances::class)->callTableAction('refund', $second, ['amount' => 200, 'reference' => 'UTR-PLAIN', 'on' => '2026-09-21'])->assertHasNoTableActionErrors();
    expect(ExpenseAdvanceEntry::query()->where('reference', 'UTR-PLAIN')->sole()->only(['refund_mode', 'note']))->toBe(['refund_mode' => null, 'note' => 'Refunded by the employee']);
});

// ---- Bulk ------------------------------------------------------------------------------------------------------------

it('recovers the selected employees in bulk through the WO-1 framework, with a per-employee summary', function () {
    [$bUser, $b] = xo6Person($this->company);
    [$cUser, $c] = xo6Person($this->company);
    $a1 = xo6Dispensed($this->employee, $this->user, 3000, '2026-09-21');
    $b1 = xo6Dispensed($b, $bUser, 800, '2026-09-21');
    $ownEmployee = Employee::factory()->create(['user_id' => $this->hr->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $ownEmployee->id, 'company_id' => $this->company->id, 'effective_from' => '2025-01-01']);

    // From the desk's toolbar: through payroll, at most 1,000 each.
    Livewire::test(AdvanceRecovery::class)->callTableBulkAction('bulk_advance_recovery_payroll', [$this->employee, $b], ['month' => '2026-11', 'amount' => 1000, 'note' => 'Bulk'])
        ->assertHasNoTableBulkActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->only(['register', 'operation', 'status', 'total', 'done']))->toBe(['register' => 'advance_recovery', 'operation' => 'advance_recovery.payroll', 'status' => 'completed', 'total' => 2, 'done' => 2])
        ->and(BulkRunItem::query()->where('bulk_run_id', $run->id)->pluck('message', 'record_id')->all())->toEqual([$this->employee->id => 'Nov 2026: 1,000.00 (1 advance)', $b->id => 'Nov 2026: 800.00 (1 advance)'])
        ->and((float) ExpenseAdvanceRecoverySchedule::query()->where('expense_advance_id', $a1->id)->value('amount'))->toBe(1000.0)
        ->and((float) ExpenseAdvanceRecoverySchedule::query()->where('expense_advance_id', $b1->id)->value('amount'))->toBe(800.0)
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvanceRecoverySchedule::class)->where('operation_id', $run->operation_id)->count())->toBe(2);

    // Refused per employee, with the reason: nothing outstanding, one's own, everything already scheduled.
    $run = app(BulkRuns::class)->start($this->hr, 'advance_recovery', 'advance_recovery.payroll', [$this->employee->id, $b->id, $c->id, $ownEmployee->id], ['month' => '2026-12']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->refresh()->only(['done', 'refused']))->toBe(['done' => 1, 'refused' => 3])
        ->and($items[$this->employee->id]->message)->toBe('Dec 2026: 2,000.00 (1 advance)')
        ->and($items[$b->id]->message)->toContain('nothing outstanding is left to schedule for Dec 2026')
        ->and($items[$c->id]->message)->toContain('has nothing outstanding on expense advances')
        ->and($items[$ownEmployee->id]->message)->toBe('Your own advances are recovered by someone else.');

    // Outside payroll in bulk: each employee's whole outstanding, with the mode and reference.
    $run = app(BulkRuns::class)->start($this->hr, 'advance_recovery', 'advance_recovery.refund', [$this->employee->id, $b->id], ['mode' => 'cash', 'reference' => 'CASH-BULK', 'on' => '2026-09-21']);
    expect($run->refresh()->only(['status', 'done']))->toBe(['status' => 'completed', 'done' => 2])
        ->and(BulkRunItem::query()->where('bulk_run_id', $run->id)->pluck('message', 'record_id')->all())->toEqual([$this->employee->id => 'Recovered 3,000.00 ('.$a1->number.')', $b->id => 'Recovered 800.00 ('.$b1->number.')'])
        ->and($a1->refresh()->status)->toBe('settled')->and($b1->refresh()->status)->toBe('settled')
        // The advances closed: their scheduled recoveries close with them.
        ->and(ExpenseAdvanceRecoverySchedule::query()->where('status', 'scheduled')->count())->toBe(0)
        ->and(ExpenseAdvanceRecoverySchedule::query()->where('status', 'closed')->count())->toBe(3)
        ->and(ExpenseAdvanceEntry::query()->where('reference', 'CASH-BULK')->pluck('refund_mode')->unique()->all())->toBe(['cash']);
    expect(fn () => app(BulkRuns::class)->start($this->hr, 'advance_recovery', 'advance_recovery.refund', [$b->id], ['reference' => 'X', 'on' => '2026-09-21']))
        ->toThrow(RuntimeException::class, 'Choose how it was paid back');

    // Bulk needs bulk.run and expense.pay.
    $this->actingAs(tenantUser($this->tenant, ['expense.pay', 'employee.view']));
    Livewire::test(AdvanceRecovery::class)->assertTableBulkActionHidden('bulk_advance_recovery_payroll');
});

// ---- Partial dispense (XO-5) -----------------------------------------------------------------------------------------

it('counts a partly dispensed advance by what was paid out, schedules only that, and lets the rest lapse once recovered', function () {
    app(SettingsRepository::class)->set('expense.advance.partial_dispense', true, 'test');
    $advance = $this->advances->approve($this->advances->request($this->employee, ['amount' => 10000, 'purpose' => 'Project'], $this->user), null, null, $this->hr);
    $advance = $this->advances->dispense($advance->refresh(), 'UTR-P1', '2026-09-21', $this->hr, 4000);
    expect((float) $advance->outstanding)->toBe(4000.0)->and($advance->remainingToDispense())->toBe(6000.0);

    $page = Livewire::test(AdvanceRecovery::class)->assertCanSeeTableRecords([$this->employee]);
    expect(xo6Rows($page)[$this->employee->id]['outstanding_total'])->toBe(4000.0)
        ->and(fn () => $this->recoveries->schedule($advance, '2026-10', 5000, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'not yet scheduled (4,000.00)');
    $this->recoveries->schedule($advance, '2026-10', 4000, null, $this->hr);

    // A further payout (no run carries the advance yet) adds to what can be scheduled; the schedule stays as chosen.
    $this->advances->dispense($advance->refresh(), 'UTR-P2', '2026-09-21', $this->hr, 2000);
    expect((float) $advance->refresh()->outstanding)->toBe(6000.0)->and($this->recoveries->unscheduledFor($this->employee))->toBe(2000.0);
    $this->get(AdvanceLedger::getUrl(['employee' => $this->employee->id]))->assertOk()->assertSee('6,000.00');

    xo6Finalize(xo6Run(9));
    $this->travelTo('2026-10-25 09:00:00');
    $october = xo6Run(10);
    $lines = xo6Lines($october);
    expect($lines)->toHaveCount(1)->and((float) $lines[0]->amount)->toBe(4000.0)
        ->and(fn () => $this->advances->dispense($advance->refresh(), 'UTR-P3', '2026-10-25', $this->hr, 1000))->toThrow(ExpenseRuleViolation::class, 'already being recovered');
    xo6Finalize($october);
    expect((float) $advance->refresh()->outstanding)->toBe(2000.0)->and($advance->status)->toBe('disbursed')->and($advance->remainingToDispense())->toBe(4000.0);

    // Paid back outside payroll: settled, and the part never paid out lapses.
    $this->recoveries->refundForEmployee($this->employee, null, 'UTR-BACK', '2026-10-25', ['mode' => 'bank_transfer'], $this->hr);
    $advance->refresh();
    $rows = $this->recoveries->ledger($this->employee);
    expect($advance->status)->toBe('settled')->and($advance->lapsedAmount())->toBe(4000.0)
        ->and(end($rows)['closing'])->toBe(0.0)->and(collect($rows)->pluck('change')->sort()->values()->all())->toBe([-4000.0, -2000.0, 2000.0, 4000.0]);
    Livewire::test(AdvanceRecovery::class)->assertCanNotSeeTableRecords([$this->employee])->call('setTab', 'recovered')->assertCanSeeTableRecords([$this->employee]);
});

// ---- Exit --------------------------------------------------------------------------------------------------------------

it('recovers the rest of an advance in the final settlement when the final salary carries only its scheduled part', function () {
    $advance = xo6Dispensed($this->employee, $this->user, 1000, '2026-09-21');
    $schedule = $this->recoveries->schedule($advance, '2026-10', 400, null, $this->hr);

    $exits = app(Exits::class);
    $case = $exits->initiate($this->employee, 'resignation', 'Moving', '2026-09-21', '2026-10-01', 30, $this->hr);
    $exits->startClearance($case);
    foreach ($case->clearances()->get() as $stage) {
        $exits->markNotApplicable($stage, $this->hr, 'n/a');
    }
    $settlements = app(FinalSettlements::class);
    $settlement = $settlements->calculate($case->refresh(), $this->hr);
    $lines = $settlement->lines()->get();
    // The final salary (October) deducts the scheduled 400; the balance line recovers the other 600 of the same advance.
    $salary = $lines->firstWhere('code', 'SAL_EXP_ADV_RECOVERY');
    $balance = $lines->firstWhere('code', ExpenseSettlementSource::BALANCE);
    expect((float) $salary->amount)->toBe(400.0)->and($salary->basis['schedule'])->toBe($schedule->id)
        ->and((float) $balance->amount)->toBe(600.0)->and($balance->basis['advances'])->toEqual([['id' => $advance->id, 'number' => $advance->number, 'amount' => 600.0, 'settle_by' => '2026-10-01']]);

    $settlements->approve($settlement, $this->hr, 'ok');
    $settlements->markPaid($settlement->refresh(), 'NEFT-X', $this->hr);
    $entry = ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->sole();
    expect($advance->refresh()->status)->toBe('recovered')->and((float) $advance->outstanding)->toBe(0.0)
        ->and((float) $entry->amount)->toBe(-1000.0)->and($entry->entry_key)->toBe('fnf-'.$settlement->id)
        ->and($schedule->refresh()->status)->toBe('recovered')->and((float) $schedule->recovered_amount)->toBe(400.0)->and($schedule->final_settlement_id)->toBe($settlement->id);
});
