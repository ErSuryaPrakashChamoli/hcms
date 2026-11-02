<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Expense\Contracts\PayrollMonths;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\SavedViews;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\PayExpenses;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\ExpenseReports\Pages\ViewExpenseReport;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity XO-4 (matrix 26 §26.4.4 #2, #3, #5, #6, #9–#12, #16, #18, #19; §26.4.3 #22): Pay expenses by report, by
| employee and paid; an optional payroll month among the open, not finalized months (owner decision D-XO1 / EXP-7; blank =
| the next payroll, as before) read only by that month's payroll; moving a report to another month or off payroll until a
| run carries it; the outside payment's mode, paying account, cheque and note. A report is paid once: by exactly one
| finalized run of its month, or outside payroll — never both, never in two months. Held lines (XO-3) stay out.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'expense.*', 'exit.*', 'analytics.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['expense.claim']);
    $this->user = $this->employee->user;
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $categories = app(ExpenseCategories::class);
    $this->cab = $categories->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    $this->stay = $categories->save(null, ['type' => 'amount', 'name' => 'Stay', 'code' => 'STAY', 'reimbursable' => false]);
    xo4Policy(['categories' => [['category' => $this->cab->id], ['category' => $this->stay->id]]]);
    $this->reports = app(ExpenseReports::class);
    $this->runs = app(PayrollRuns::class);
});

function xo4Policy(array $settings): void
{
    $policy = Policy::create(['type' => 'expense', 'name' => 'Expense '.uniqid(), 'code' => 'XO4_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'expense', 'policy_id' => $policy->id, 'name' => 'Everyone', 'conditions' => [], 'priority' => 1000]);
}

function xo4Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

/**
 * A report of these expenses ([category, date, amount] each), submitted by the employee and approved in full by HR.
 *
 * @param  list<array{0: ExpenseCategory, 1: string, 2: float}>  $lines
 */
function xo4Approved(Employee $employee, User $as, array $lines, string $title = 'September'): ExpenseReport
{
    $reports = app(ExpenseReports::class);
    $ids = array_map(fn (array $l) => app(ExpenseReceipts::class)->capture($employee, ['expense_category_id' => $l[0]->id, 'expense_date' => $l[1], 'amount' => $l[2]], $as)->id, $lines);
    $report = $reports->submit($reports->create($employee, $title, $ids, $as), $as);
    foreach ($report->parts()->get() as $part) {
        $reports->approvePart($part, null, null, test()->hr);
    }

    return $report->refresh();
}

/** @return array{0: User, 1: Employee} an employee of the company (no salary: for the desk, not for payroll) */
function xo4Person(Company $company, array $permissions = ['expense.claim']): array
{
    $user = tenantUser(test()->tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);

    return [$user, $employee];
}

/** The month's payroll run of the company, calculated (again). */
function xo4Run(int $month): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $open = PayrollRun::query()->whereHas('period', fn ($q) => $q->where('year', 2026)->where('month', $month))->whereNotIn('status', ['finalized', 'paid'])->first();

    return $runs->calculate($open ?? $runs->open(test()->company, 2026, $month, test()->hr), test()->hr);
}

function xo4Finalize(PayrollRun $run): PayrollRun
{
    $runs = app(PayrollRuns::class);

    return $runs->finalize($runs->approve($runs->validate($run), test()->approver, 'ok'), test()->approver);
}

function xo4Line(PayrollRun $run, ?Employee $employee = null): ?PayrollEntryLine
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id)->where('employee_id', ($employee ?? test()->employee)->id))->where('code', 'EXPENSE')->first();
}

function xo4Account(Company $company, string $alias, array $extra = []): CompanyBankAccount
{
    return CompanyBankAccount::create(['company_id' => $company->id, 'alias' => $alias, 'bank_name' => 'HDFC Bank', 'ifsc' => 'HDFC0009999', 'account_number' => '5020001234'.random_int(1000, 9999)] + $extra);
}

/** The streamed CSV of the page's current tab. */
function xo4Csv(PayExpenses $page): string
{
    ob_start();
    $page->downloadCsv()->sendContent();

    return (string) ob_get_clean();
}

// ---- Defaults ---------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: one row per report, the next payroll when no month is chosen, and the payment recorded as before', function () {
    $report = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 1200]]);
    $other = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-16', 300]]);

    $page = Livewire::test(PayExpenses::class)->assertOk()->assertSet('payTab', 'reports')->assertCanSeeTableRecords([$report, $other])
        ->assertTableColumnExists('employee.employee_code')->assertTableColumnExists('reimbursable_approved')->assertTableColumnExists('non_reimbursable')
        ->assertTableColumnExists('total_approved')->assertTableColumnExists('advance_adjusted')->assertTableColumnExists('net_payable')->assertTableColumnExists('payment')
        ->assertSee('To pay, by employee')->assertSee('Paid');
    // "Pay with payroll" without a month: the next payroll, exactly as before.
    $page->callTableAction('payWithPayroll', $report)->assertHasNoTableActionErrors();
    $report->refresh();
    expect($report->payment_channel)->toBe('payroll')->and($report->payment_scheduled_for)->toBeNull()->and($report->payMonthLabel())->toBe('Next payroll')
        ->and($report->payment_note)->toBeNull()->and($report->paymentStatus())->toBe('Scheduled for payroll');
    $page->assertSee('since '.$report->payment_scheduled_at->format('d M Y'));

    // "Record payment" with only the reference and the date: nothing more is recorded.
    Livewire::test(PayExpenses::class)->callTableAction('pay', $other, ['reference' => 'UTR-1', 'paid_on' => '2026-09-21'])->assertHasNoTableActionErrors();
    $other->refresh();
    expect($other->status)->toBe('paid')->and($other->payment_channel)->toBe('bank')->and($other->payment_reference)->toBe('UTR-1')
        ->and($other->only(['payment_mode', 'paid_from_account_id', 'cheque_number', 'cheque_date', 'payment_note', 'payment_scheduled_for']))->each->toBeNull();

    // The next payroll pays the scheduled report, as before; finalized, it is paid once.
    $run = xo4Run(9);
    expect((float) xo4Line($run)->amount)->toBe(1200.0)->and(xo4Line($run)->basis['source']['ids'])->toBe([$report->id]);
    $run = xo4Finalize($run);
    expect($report->refresh()->status)->toBe('paid')->and($report->payroll_run_id)->toBe($run->id);
});

// ---- By employee ------------------------------------------------------------------------------------------------------

it('groups the reports by employee with their totals, narrows them with the filters, and pays each report of a row', function () {
    [$otherUser, $other] = xo4Person($this->company);
    $a1 = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-10', 1000], [$this->stay, '2026-09-10', 400]], 'Trip');
    $a2 = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-12', 500]], 'Taxi');
    $b1 = xo4Approved($other, $otherUser, [[$this->cab, '2026-09-11', 700]], 'Visit');
    $this->reports->scheduleForPayroll($a2, $this->hr);
    expect((float) $a1->total_approved)->toBe(1400.0)->and((float) $a1->reimbursable_approved)->toBe(1000.0)->and((float) $a1->net_payable)->toBe(1000.0);

    $rows = fn ($page) => collect($page->instance()->getTableRecords()->items())->keyBy(fn (ExpenseReport $r) => (int) $r->getAttributes()['employee_id'])
        ->map(fn (ExpenseReport $r) => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, array_intersect_key($r->getAttributes(), array_flip(['reports_count', 'scheduled_count', 'sum_approved', 'sum_reimbursable', 'sum_non_reimbursable', 'sum_advances', 'sum_to_pay']))));
    $page = Livewire::test(PayExpenses::class)->call('setTab', 'employees')->assertSet('payTab', 'employees')->assertCountTableRecords(2)
        ->assertTableColumnExists('employee.employee_code')->assertTableColumnExists('sum_reimbursable')->assertTableColumnExists('sum_non_reimbursable')
        ->assertSee($this->employee->employee_code)->assertSee('1 waiting for payment · 1 scheduled for payroll');
    expect($rows($page)[$this->employee->id])->toEqual(['reports_count' => 2.0, 'scheduled_count' => 1.0, 'sum_approved' => 1900.0, 'sum_reimbursable' => 1500.0, 'sum_non_reimbursable' => 400.0, 'sum_advances' => 0.0, 'sum_to_pay' => 1500.0])
        ->and($rows($page)[$other->id]['sum_to_pay'])->toBe(700.0);

    // The filters narrow what a row adds up: the period, and reports with an exceptional part.
    $page->filterTable('period', ['from' => '2026-09-11']);
    expect($rows($page)[$this->employee->id]['sum_to_pay'])->toBe(500.0)->and($rows($page)[$this->employee->id]['reports_count'])->toBe(1.0);
    ExpenseReceipt::query()->where('expense_report_id', $b1->id)->update(['is_exceptional' => true]);
    Livewire::test(PayExpenses::class)->call('setTab', 'employees')->filterTable('exceptional', true)->assertCountTableRecords(1)
        ->assertTableActionVisible('payEmployee', ExpenseReport::query()->whereKey($b1->id)->first());
    Livewire::test(PayExpenses::class)->filterTable('exceptional', true)->assertCanSeeTableRecords([$b1])->assertCanNotSeeTableRecords([$a1, $a2]);

    // Paying the row pays each of its reports: with payroll (those not yet scheduled), or one outside payment.
    $rowOf = fn (Employee $e) => ExpenseReport::query()->whereKey(PayExpenses::payable()->where('employee_id', $e->id)->min('id'))->first();
    Livewire::test(PayExpenses::class)->call('setTab', 'employees')->callTableAction('payEmployeeWithPayroll', $rowOf($this->employee), ['month' => '2026-10', 'note' => 'October run'])
        ->assertHasNoTableActionErrors()->assertNotified('1 report(s) scheduled for payroll');
    expect($a1->refresh()->payMonth())->toBe('2026-10')->and($a1->payment_note)->toBe('October run')
        ->and($a2->refresh()->payMonth())->toBeNull()->and($a2->isScheduledForPayroll())->toBeTrue();
    Livewire::test(PayExpenses::class)->call('setTab', 'employees')->callTableAction('payEmployee', $rowOf($other), ['mode' => 'cash', 'reference' => 'CASH-7', 'paid_on' => '2026-09-21'])
        ->assertHasNoTableActionErrors();
    expect($b1->refresh()->status)->toBe('paid')->and($b1->payment_mode)->toBe('cash')->and($b1->payment_reference)->toBe('CASH-7');

    // "Reports" on a row opens the per-report tab searched for the employee.
    Livewire::test(PayExpenses::class)->call('setTab', 'employees')
        ->assertTableActionHasUrl('openReports', PayExpenses::getUrl(['tab' => 'reports', 'search' => $this->employee->employee_code]), $rowOf($this->employee));
    Livewire::withQueryParams(['tab' => 'reports', 'search' => $this->employee->employee_code])->test(PayExpenses::class)
        ->assertSet('payTab', 'reports')->assertCanSeeTableRecords([$a1, $a2])->assertCanNotSeeTableRecords([$b1]);

    // The CSV of the tab, audited.
    $page = Livewire::test(PayExpenses::class)->call('setTab', 'employees');
    $csv = xo4Csv($page->instance());
    expect($csv)->toContain('"Employee code",Employee,Reports,"Oldest approval"')->toContain($this->employee->employee_code)->toContain('1500.00')
        ->not->toContain('CASH-7')
        ->and(AuditEvent::query()->where('action', 'EXPORT')->where('module', 'expense')->latest('id')->first()?->metadata)->toMatchArray(['export' => 'pay_expenses', 'tab' => 'employees', 'rows' => 1]);
});

// ---- The pay month ----------------------------------------------------------------------------------------------------

it('pays a report scheduled for a chosen month only in that month\'s payroll, once', function () {
    $report = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 1200]]);
    $months = app(PayrollMonths::class)->openFor($this->employee->id);
    expect(array_keys($months))->toHaveCount(12)->and(array_key_first($months))->toBe('2026-09')->and($months['2026-10'])->toBe('Oct 2026');

    Livewire::test(PayExpenses::class)->callTableAction('payWithPayroll', $report, ['month' => '2026-10', 'note' => 'With October'])->assertHasNoTableActionErrors();
    $report->refresh();
    expect($report->payment_scheduled_for->toDateString())->toBe('2026-10-01')->and($report->payMonth())->toBe('2026-10')->and($report->payment_note)->toBe('With October')
        ->and(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $report->id)->where('reason', 'Scheduled for payroll of Oct 2026')->exists())->toBeTrue();
    Livewire::test(PayExpenses::class)->assertSee('Oct 2026 payroll');

    // Not the month before.
    $september = xo4Run(9);
    expect(xo4Line($september))->toBeNull();
    xo4Finalize($september);
    expect($report->refresh()->paid_on)->toBeNull()->and($this->reports->payMonthMissed($report))->toBeFalse();

    // Its month carries it; meanwhile nothing else pays, moves or cancels it.
    $this->travelTo('2026-10-25 09:00:00');
    $october = xo4Run(10);
    expect((float) xo4Line($october)->amount)->toBe(1200.0)->and(xo4Line($october)->basis['source']['ids'])->toBe([$report->id])
        ->and(fn () => $this->reports->markPaid($report, 'UTR-9', '2026-10-25', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being paid in the payroll of Oct 2026')
        ->and(fn () => $this->reports->reschedulePayroll($report, '2026-11', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already being paid in the payroll of Oct 2026: it stays with that payroll')
        ->and(fn () => $this->reports->reschedulePayroll($report, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'it stays with that payroll')
        ->and(fn () => $this->reports->cancelPayrollSchedule($report, 'x', $this->hr))->toThrow(ExpenseRuleViolation::class, 'it stays with that payroll')
        ->and(fn () => $this->reports->scheduleForPayroll($report, $this->hr, '2026-11'))->toThrow(ExpenseRuleViolation::class, 'already scheduled for payroll');
    $october = xo4Finalize($october);
    $report->refresh();
    expect($report->status)->toBe('paid')->and($report->payment_channel)->toBe('payroll')->and($report->payroll_run_id)->toBe($october->id)
        ->and($report->payment_reference)->toBe('Payroll Oct 2026');

    // Never in a second month, and no other way once paid.
    $this->travelTo('2026-11-25 09:00:00');
    expect(xo4Line(xo4Run(11)))->toBeNull()
        ->and(fn () => $this->reports->reschedulePayroll($report, '2026-12', $this->hr))->toThrow(ExpenseRuleViolation::class, 'is not scheduled for payroll')
        ->and(fn () => $this->reports->markPaid($report, 'UTR-9', '2026-11-25', $this->hr))->toThrow(ExpenseRuleViolation::class, 'Only an approved report');
    Livewire::test(PayExpenses::class)->assertCanNotSeeTableRecords([$report])->call('setTab', 'paid')->assertCanSeeTableRecords([$report])->assertSee('Payroll Oct 2026');
});

it('refuses a finalized month, keeps a report whose month was finalized without it out of every other month, and lets it move', function () {
    $september = xo4Run(9);
    $report = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 800]]);
    expect(app(PayrollMonths::class)->openFor($this->employee->id)['2026-09'])->toBe('Sep 2026 — payroll calculated: included when it is recalculated');

    // Scheduled for September after its run was calculated; September is finalized without recalculating.
    $this->reports->scheduleForPayroll($report, $this->hr, '2026-09');
    xo4Finalize($september);
    $report->refresh();
    expect($report->paid_on)->toBeNull()->and($report->isScheduledForPayroll())->toBeTrue()->and($this->reports->payMonthMissed($report))->toBeTrue()
        ->and(app(PayrollMonths::class)->openFor($this->employee->id))->not->toHaveKey('2026-09')
        ->and(app(PayrollMonths::class)->isFinalized($this->employee->id, '2026-09'))->toBeTrue();
    Livewire::test(PayExpenses::class)->assertSee('Sep 2026 payroll — finalized without it: change the pay month');

    // A finalized month is refused, whether scheduling or moving.
    $another = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-16', 100]]);
    expect(fn () => $this->reports->scheduleForPayroll($another, $this->hr, '2026-09'))->toThrow(ExpenseRuleViolation::class, 'The payroll of Sep 2026 is finalized')
        ->and(fn () => $this->reports->reschedulePayroll($report, '2026-09', $this->hr))->toThrow(ExpenseRuleViolation::class, 'The payroll of Sep 2026 is finalized')
        ->and($another->refresh()->payment_channel)->toBeNull();

    // No other month takes it on its own.
    $this->travelTo('2026-10-25 09:00:00');
    $october = xo4Run(10);
    expect(xo4Line($october))->toBeNull();

    // Moved to October (its run open, not finalized), the recalculated run pays it, once.
    Livewire::test(PayExpenses::class)->callTableAction('changePayMonth', $report, ['month' => '2026-10'])->assertHasNoTableActionErrors();
    expect($report->refresh()->payMonth())->toBe('2026-10')
        ->and(AuditEvent::query()->where('entity_id', $report->id)->where('entity_type', ExpenseReport::class)->where('reason', 'Payroll month changed: Sep 2026 → Oct 2026')->exists())->toBeTrue();
    $october = xo4Finalize(xo4Run(10));
    expect($report->refresh()->status)->toBe('paid')->and($report->payroll_run_id)->toBe($october->id);
});

it('moves a scheduled report between open months or back to the next payroll, and takes it off payroll, until a run carries it', function () {
    $report = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 650]]);
    expect(fn () => $this->reports->reschedulePayroll($report, '2026-10', $this->hr))->toThrow(ExpenseRuleViolation::class, 'is not scheduled for payroll')
        ->and(fn () => $this->reports->cancelPayrollSchedule($report, null, $this->hr))->toThrow(ExpenseRuleViolation::class, 'is not scheduled for payroll');

    // Only open months: a valid month, not before the next payroll, not beyond the months offered.
    foreach (['2026-13' => 'Choose the payroll month', 'Oct 2026' => 'Choose the payroll month', '2026-08' => 'not an open payroll month', '2027-09' => 'not an open payroll month'] as $month => $why) {
        expect(fn () => $this->reports->scheduleForPayroll($report, $this->hr, $month))->toThrow(ExpenseRuleViolation::class, $why);
    }
    expect($report->refresh()->payment_channel)->toBeNull();

    $this->reports->scheduleForPayroll($report, $this->hr, '2026-11');
    expect(fn () => $this->reports->reschedulePayroll($report, '2026-11', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already scheduled for Nov 2026');
    $this->reports->reschedulePayroll($report, '2026-10', $this->hr, 'Sooner');
    expect($report->refresh()->payMonth())->toBe('2026-10')->and($report->payment_note)->toBe('Sooner');

    // Back to the next payroll: September's run pays it (blank month, as before).
    $this->reports->reschedulePayroll($report, null, $this->hr);
    expect($report->refresh()->payment_scheduled_for)->toBeNull()->and($report->payMonthLabel())->toBe('Next payroll')
        ->and(fn () => $this->reports->reschedulePayroll($report, '', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already scheduled for the next payroll');
    $september = xo4Run(9);
    expect((float) xo4Line($september)->amount)->toBe(650.0);
    // The run carries it now: it stays with that payroll.
    expect(fn () => $this->reports->reschedulePayroll($report, '2026-12', $this->hr))->toThrow(ExpenseRuleViolation::class, 'it stays with that payroll');

    // Taken off payroll before a run carries it: back to waiting, and no payroll pays it.
    $later = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-18', 90]]);
    $this->reports->scheduleForPayroll($later, $this->hr, '2026-10');
    Livewire::test(PayExpenses::class)->callTableAction('cancelPayrollSchedule', $later, ['reason' => 'Paying by cheque'])->assertHasNoTableActionErrors();
    $later->refresh();
    expect($later->payment_channel)->toBeNull()->and($later->payment_scheduled_at)->toBeNull()->and($later->payment_scheduled_for)->toBeNull()
        ->and($later->paymentStatus())->toBe('Waiting for payment')
        ->and(AuditEvent::query()->where('entity_id', $later->id)->where('entity_type', ExpenseReport::class)->where('reason', 'Payroll schedule cancelled: Paying by cheque')->exists())->toBeTrue();
    xo4Finalize(xo4Run(9));
    $this->travelTo('2026-10-25 09:00:00');
    expect(xo4Line(xo4Run(10)))->toBeNull();

    // Scheduled for a month, then paid outside payroll before the month: that month's payroll leaves it alone.
    $outside = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-10-02', 70]]);
    $this->reports->scheduleForPayroll($outside, $this->hr, '2026-11');
    $this->reports->markPaid($outside, 'UTR-70', '2026-10-25', $this->hr);
    expect($outside->refresh()->payment_channel)->toBe('bank')->and($outside->payment_scheduled_for)->toBeNull();
    $this->travelTo('2026-11-25 09:00:00');
    expect(xo4Line(xo4Run(11)))->toBeNull();
});

// ---- Outside payment --------------------------------------------------------------------------------------------------

it('records how an outside payment was made — mode, paying account, cheque and note — and checks them', function () {
    $account = xo4Account($this->company, 'HDFC current');
    $south = Company::factory()->create(['name' => 'South']);
    $theirs = xo4Account($south, 'South current');
    $closed = xo4Account($this->company, 'Old account', ['status' => 'inactive']);
    expect(app(PayrollMonths::class)->payingAccounts($this->employee->id))->toBe([$account->id => $account->label()])
        ->and(app(PayrollMonths::class)->payingAccounts())->toHaveKeys([$account->id, $theirs->id])->not->toHaveKey($closed->id);

    $report = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 450]]);
    $refusals = [
        'Choose how it was paid' => ['mode' => 'wire'],
        'Give the cheque number' => ['mode' => 'cheque', 'cheque_date' => '2026-09-20'],
        'Give the cheque date' => ['mode' => 'cheque', 'cheque_number' => '004512'],
        'Cheque number and date go with a payment by cheque' => ['mode' => 'bank_transfer', 'cheque_number' => '004512'],
        'A cash payment has no paying bank account' => ['mode' => 'cash', 'account_id' => $account->id],
        'Say how it was paid before naming the paying account' => ['account_id' => $account->id],
        'an active paying bank account of the employee\'s company' => ['mode' => 'bank_transfer', 'account_id' => $theirs->id],
        'Keep the payment note to 500 characters' => ['note' => str_repeat('x', 501)],
    ];
    foreach ($refusals as $why => $payment) {
        expect(fn () => $this->reports->markPaid($report, 'REF', '2026-09-21', $this->hr, $payment))->toThrow(ExpenseRuleViolation::class, $why);
    }
    expect(fn () => $this->reports->markPaid($report, 'REF', '2026-09-21', $this->hr, ['mode' => 'bank_transfer', 'account_id' => $closed->id]))
        ->toThrow(ExpenseRuleViolation::class, 'an active paying bank account')
        ->and($report->refresh()->paid_on)->toBeNull();

    // The screen asks for the cheque details only for a cheque, and requires them.
    Livewire::test(PayExpenses::class)->callTableAction('pay', $report, ['mode' => 'cheque', 'reference' => 'CHQ', 'paid_on' => '2026-09-21'])
        ->assertHasTableActionErrors(['cheque_number' => 'required', 'cheque_date' => 'required']);
    expect($report->refresh()->paid_on)->toBeNull();

    Livewire::test(PayExpenses::class)->callTableAction('pay', $report, ['mode' => 'cheque', 'account_id' => $account->id, 'cheque_number' => '004512', 'cheque_date' => '2026-09-20',
        'reference' => 'CHQ-004512', 'paid_on' => '2026-09-21', 'note' => 'Handed over at the office'])->assertHasNoTableActionErrors();
    $report->refresh();
    expect($report->status)->toBe('paid')->and($report->payment_channel)->toBe('bank')->and($report->payment_mode)->toBe('cheque')
        ->and($report->paid_from_account_id)->toBe($account->id)->and($report->cheque_number)->toBe('004512')->and($report->cheque_date->toDateString())->toBe('2026-09-20')
        ->and($report->payment_note)->toBe('Handed over at the office')->and($report->paymentModeLabel())->toBe('Cheque 004512 of 20 Sep 2026');
    $audit = AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $report->id)->where('reason', 'Paid outside payroll: CHQ-004512')->sole();
    expect($audit->fieldChanges()->pluck('field')->all())->toContain('payment_mode', 'paid_from_account_id', 'cheque_number', 'cheque_date', 'payment_note');

    // The Paid tab and the report page show how it was paid.
    Livewire::test(PayExpenses::class)->call('setTab', 'paid')->assertCanSeeTableRecords([$report])
        ->assertSee('Cheque 004512 of 20 Sep 2026')->assertSee($account->label())->assertSee('CHQ-004512');
    Livewire::test(ViewExpenseReport::class, ['record' => $report->id])->assertOk()->assertSee('Cheque 004512 of 20 Sep 2026')->assertSee($account->label())->assertSee('Handed over at the office');
    $paid = Livewire::test(PayExpenses::class)->call('setTab', 'paid');
    expect(xo4Csv($paid->instance()))->toContain('Cheque,004512,2026-09-20,CHQ-004512')->toContain('"Handed over at the office"');

    // The employee sees the mode (when payments are shown to them), never the paying account or the note.
    $this->actingAs($this->user);
    Livewire::test(ViewExpenseReport::class, ['record' => $report->id])->assertOk()->assertSee('Cheque 004512 of 20 Sep 2026')
        ->assertDontSee($account->label())->assertDontSee('Handed over at the office');
});

// ---- Held lines (XO-3, EXP-15) -----------------------------------------------------------------------------------------

it('keeps lines held as suspected out of every payment, and a held line released after payment can only be rejected (XO-3 kept)', function () {
    xo4Setting('expense.review.suspect_button', true);
    $taxi = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-15', 'amount' => 800], $this->user);
    $odd = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-09-16', 'amount' => 300], $this->user);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Held', [$taxi->id, $odd->id], $this->user), $this->user);
    $this->reports->approvePart($report->parts()->sole(), [$odd->id => ['suspected' => true, 'note' => 'Odd bill']], null, $this->hr);
    $report->refresh();
    expect($report->status)->toBe('partly_approved')->and((float) $report->net_payable)->toBe(800.0);

    // Scheduled for October: October's payroll pays only what is not held.
    $this->reports->scheduleForPayroll($report, $this->hr, '2026-10');
    $this->travelTo('2026-10-25 09:00:00');
    $october = xo4Run(10);
    expect((float) xo4Line($october)->amount)->toBe(800.0);
    xo4Finalize($october);
    expect($report->refresh()->status)->toBe('paid')->and((float) $report->net_payable)->toBe(800.0);

    // Paid: the held line is released only as a rejection (no supplementary payment).
    expect(fn () => $this->reports->clearSuspicion($odd, 300, 'Bill found', $this->hr))->toThrow(ExpenseRuleViolation::class, 'already paid: the held expense can only be rejected now');
    $this->reports->clearSuspicion($odd, 0, 'Personal ride', $this->hr);
    expect($odd->refresh()->status)->toBe('rejected')->and($report->refresh()->status)->toBe('paid')->and((float) $report->net_payable)->toBe(800.0);

    // Paid outside payroll: the same — the held amount is never part of it.
    $bus = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-10-20', 'amount' => 200], $this->user);
    $held = app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-10-21', 'amount' => 50], $this->user);
    $second = $this->reports->submit($this->reports->create($this->employee, 'Held 2', [$bus->id, $held->id], $this->user), $this->user);
    $this->reports->approvePart($second->parts()->sole(), [$held->id => ['suspected' => true, 'note' => 'Check']], null, $this->hr);
    Livewire::test(PayExpenses::class)->assertCanSeeTableRecords([$second->refresh()])->callTableAction('pay', $second, ['mode' => 'bank_transfer', 'reference' => 'UTR-2', 'paid_on' => '2026-10-25']);
    expect($second->refresh()->status)->toBe('paid')->and((float) $second->net_payable)->toBe(200.0)
        ->and(fn () => $this->reports->clearSuspicion($held, null, 'Fine', $this->hr))->toThrow(ExpenseRuleViolation::class, 'can only be rejected now');
});

// ---- Access -----------------------------------------------------------------------------------------------------------

it('shows and pays only reports in the payer\'s access scope, never their own, on every tab', function () {
    $south = Company::factory()->create(['name' => 'South']);
    [$southUser, $southEmployee] = xo4Person($south);
    [$payerUser, $payer] = xo4Person($this->company, ['expense.pay', 'expense.claim']);
    UserAccessScope::query()->create(['user_id' => $payerUser->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    $ours = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 400]]);
    $theirs = xo4Approved($southEmployee, $southUser, [[$this->cab, '2026-09-15', 900]]);
    $own = xo4Approved($payer, $payerUser, [[$this->cab, '2026-09-15', 60]]);

    $this->actingAs($payerUser);
    Livewire::test(PayExpenses::class)->assertOk()->assertCanSeeTableRecords([$ours, $own])->assertCanNotSeeTableRecords([$theirs])
        ->assertTableActionVisible('pay', $ours)->assertTableActionHidden('pay', $own)->assertTableActionHidden('payWithPayroll', $own);
    $employees = Livewire::test(PayExpenses::class)->call('setTab', 'employees')->assertCountTableRecords(2);
    expect(collect($employees->instance()->getTableRecords()->items())->map(fn (ExpenseReport $r) => (int) $r->getAttributes()['employee_id'])->sort()->values()->all())
        ->toBe(collect([$this->employee->id, $payer->id])->sort()->values()->all())
        ->and($employees->instance()->employeeReports([$southEmployee->id, $payer->id])->pluck('id')->all())->toBe([$own->id])
        ->and(xo4Csv($employees->instance()))->not->toContain($southEmployee->employee_code);
    expect(fn () => $this->reports->markPaid($own, 'SELF', '2026-09-21', $payerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot record payments for this report.')
        ->and(fn () => $this->reports->scheduleForPayroll($own, $payerUser, '2026-10'))->toThrow(ExpenseRuleViolation::class, 'You cannot record payments for this report.')
        ->and(fn () => $this->reports->scheduleForPayroll($theirs, $payerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot record payments for this report.');

    // The employee row of one's own reports pays none of them.
    $ownRow = ExpenseReport::query()->whereKey($own->id)->first();
    Livewire::test(PayExpenses::class)->call('setTab', 'employees')->assertTableActionHidden('payEmployee', $ownRow)->assertTableActionHidden('payEmployeeWithPayroll', $ownRow);

    $this->reports->markPaid($theirs, 'UTR-S', '2026-09-21', $this->hr);
    Livewire::test(PayExpenses::class)->call('setTab', 'paid')->assertCanNotSeeTableRecords([$theirs]);

    // No expense.pay, no page.
    $this->actingAs($this->user);
    expect(PayExpenses::canAccess())->toBeFalse();
    $this->get(PayExpenses::getUrl())->assertForbidden();
});

it('keeps the tab with a saved view and opens a preset from the URL', function () {
    $report = xo4Approved($this->employee, $this->user, [[$this->cab, '2026-09-15', 400]]);
    $this->reports->markPaid($report, 'UTR-V', '2026-09-21', $this->hr);

    Livewire::test(PayExpenses::class)->call('setTab', 'paid')->callAction('posSaveView', ['name' => 'Paid this month'])->assertHasNoActionErrors();
    app()->forgetInstance(ExperiencePreferences::class);
    $saved = collect(app(SavedViews::class)->for($this->hr, PayExpenses::REGISTER))->firstWhere('name', 'Paid this month');
    expect($saved['lens'])->toBe('paid');
    Livewire::test(PayExpenses::class)->assertSet('payTab', 'reports')->callAction('posView_'.$saved['id'])->assertSet('payTab', 'paid')->assertCanSeeTableRecords([$report]);
    Livewire::withQueryParams(['view' => 'by_employee'])->test(PayExpenses::class)->assertSet('payTab', 'employees');
    expect(Str::of(ExpenseReportResource::getUrl('view', ['record' => $report]))->isNotEmpty())->toBeTrue();
});
