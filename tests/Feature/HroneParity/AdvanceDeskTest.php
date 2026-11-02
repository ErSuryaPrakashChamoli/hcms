<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Services\TravelRequests;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Pages\DispenseAdvances;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use App\Filament\Resources\ExpenseAdvances\Pages\ViewExpenseAdvance;
use App\Filament\Resources\ExpenseAdvances\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\ExpenseReports\Pages\ListExpenseReports;
use App\Filament\Resources\TravelRequests\TravelRequestResource;
use App\Filament\Support\ExpenseOnBehalf;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity XO-5 (matrix 26 §26.3.1 #9; §26.3.2 #1, #2, #5, #7–#9; §26.2.2 #8; §26.7 #1, #2; §26.9 #2): HR requests an
| advance and builds a report for an employee in scope (the employee's policy, limits and workflow; filed on behalf, audited;
| never one's own, never outside the scope); Dispense advances "To dispense" / "Dispensed" tabs with the employee's
| outstanding, the trip, the note, filters and a scoped badge; partial dispense behind expense.advance.partial_dispense (off =
| in full, as before; on = several payouts, settle-by from the first, the rest lapses, the ledger and payroll recovery right);
| the trip page's advance amount and needed-on date; the advance's History tab; EXP-3 as built.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    Storage::fake('local');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'expense.*', 'exit.*', 'audit.view']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['expense.claim']);
    $this->user = $this->employee->user;
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $categories = app(ExpenseCategories::class);
    $this->cab = $categories->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    $this->hotel = $categories->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'travel_link' => 'plan', 'travel_form' => 'accommodation', 'booked_by' => 'employee']);
    xo5Policy('expense', ['categories' => [['category' => $this->cab->id], ['category' => $this->hotel->id]]]);
    xo5Policy('expense_advance', ['enabled' => true, 'max_open' => 10, 'max_amount' => 20000, 'purpose_required' => true, 'settle_within_days' => 10, 'recovery' => 'payroll', 'recovery_after_days' => 5]);
    $this->advances = app(ExpenseAdvances::class);
    $this->reports = app(ExpenseReports::class);
});

/** @param  list<Employee>  $only  named employees (empty = everyone) */
function xo5Policy(string $type, array $settings, array $only = []): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'XO5_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    $conditions = $only === [] ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => array_map(fn (Employee $e) => $e->id, $only)]];
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $only === [] ? 1000 : 50]);

    return $policy;
}

function xo5Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

/** @return array{0: User, 1: Employee} */
function xo5Person(Company $company, array $permissions = ['expense.claim']): array
{
    $user = tenantUser(test()->tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);

    return [$user, $employee];
}

/** An advance the employee asked for, approved in full by HR. */
function xo5Approved(Employee $employee, User $as, float $amount, string $purpose = 'Client visit'): ExpenseAdvance
{
    $advances = app(ExpenseAdvances::class);

    return $advances->approve($advances->request($employee, ['amount' => $amount, 'purpose' => $purpose], $as), null, null, test()->hr)->refresh();
}

function xo5Expense(Employee $employee, User $as, string $date, float $amount, ?ExpenseCategory $category = null): ExpenseReceipt
{
    return app(ExpenseReceipts::class)->capture($employee, ['expense_category_id' => ($category ?? test()->cab)->id, 'expense_date' => $date, 'amount' => $amount], $as);
}

/** A report of one expense, submitted by the employee and approved in full by HR. */
function xo5ApprovedReport(Employee $employee, User $as, string $date, float $amount): ExpenseReport
{
    $reports = app(ExpenseReports::class);
    $report = $reports->submit($reports->create($employee, 'Report '.$date, [xo5Expense($employee, $as, $date, $amount)->id], $as), $as);
    $reports->approvePart($report->parts()->sole(), null, null, test()->hr);

    return $report->refresh();
}

/** A stay in Mumbai booked by the employee. */
function xo5Trip(ExpenseCategory $hotel, array $extra = [], string $from = '2026-10-05', string $to = '2026-10-07'): array
{
    return $extra + ['purpose' => 'Client workshop', 'start_date' => $from, 'end_date' => $to,
        'legs' => [['expense_category_id' => $hotel->id, 'to_city' => 'Mumbai', 'to_state' => 'MH', 'starts_on' => $from, 'ends_on' => $to]]];
}

/** @return list<float> the advance's ledger, in order */
function xo5Ledger(ExpenseAdvance $advance): array
{
    return ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all();
}

function xo5Submitted(ExpenseAdvance $advance): AuditEvent
{
    return AuditEvent::query()->where('entity_type', ExpenseAdvance::class)->where('entity_id', $advance->id)->where('action', 'SUBMITTED')->latest('id')->firstOrFail();
}

function xo5Calculate(int $month): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $open = PayrollRun::query()->whereHas('period', fn ($q) => $q->where('year', 2026)->where('month', $month))->whereNotIn('status', ['finalized', 'paid'])->first();

    return $runs->calculate($open ?? $runs->open(test()->company, 2026, $month, test()->hr), test()->hr);
}

function xo5Finalize(PayrollRun $run): PayrollRun
{
    $runs = app(PayrollRuns::class);

    return $runs->finalize($runs->approve($runs->validate($run), test()->approver, 'ok'), test()->approver);
}

function xo5Line(PayrollRun $run, string $code): ?PayrollEntryLine
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id)->where('employee_id', test()->employee->id))->where('code', $code)->first();
}

// ---- Defaults --------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: advances dispensed in full, one\'s own requests and reports unmarked, the dispense page as before', function () {
    expect(config('peopleos.settings')['expense.advance.partial_dispense'])->toBeFalse()
        ->and(collect(config('peopleos_setup.modules.expense.settings'))->pluck('key')->all())->toContain('expense.advance.partial_dispense')
        ->and($this->advances->partialDispense())->toBeFalse();

    // The employee's own request and report: the self path, nothing marked as filed on behalf; no on-behalf actions offered.
    $this->actingAs($this->user);
    Livewire::test(ListExpenseAdvances::class)->assertOk()->assertActionHidden('requestForEmployee')
        ->callAction('requestAdvance', ['currency' => 'INR', 'amount' => 3000, 'purpose' => 'Conference'])->assertHasNoActionErrors();
    $advance = ExpenseAdvance::query()->sole();
    expect($advance->requested_by)->toBe($this->user->id)->and($advance->filed_on_behalf)->toBeFalse()
        ->and(xo5Submitted($advance)->metadata)->toEqual(['amount' => 3000]);
    Livewire::test(ListExpenseReports::class)->assertOk()->assertActionHidden('newReportForEmployee');
    $own = $this->reports->create($this->employee, 'September', [xo5Expense($this->employee, $this->user, '2026-09-10', 400)->id]);
    expect(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $own->id)->where('action', 'CREATE')->sole()->reason)->toBeNull();
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $own]))->assertOk()->assertDontSee('Filed for the employee');

    // Dispensed in full only: an amount is refused in the service and ignored on the screen; a second payout is refused.
    $this->actingAs($this->hr);
    $this->advances->approve($advance, null, null, $this->hr);
    expect(fn () => $this->advances->dispense($advance->refresh(), 'UTR-1', '2026-09-21', $this->hr, 1000))->toThrow(ExpenseRuleViolation::class, 'An advance is dispensed in full: 3,000.00.');
    Livewire::test(DispenseAdvances::class)->assertOk()->assertSet('dispenseTab', 'to_dispense')->assertCanSeeTableRecords([$advance])
        ->callTableAction('dispense', $advance, ['amount' => 1000, 'reference' => 'UTR-1', 'on' => '2026-09-21'])->assertHasNoTableActionErrors();
    $advance->refresh();
    expect($advance->status)->toBe('disbursed')->and((float) $advance->outstanding)->toBe(3000.0)->and($advance->dispensed_amount)->toBeNull()
        ->and($advance->disbursement_note)->toBeNull()->and($advance->remainder_lapsed_at)->toBeNull()
        ->and($advance->dispensedAmount())->toBe(3000.0)->and($advance->remainingToDispense())->toBe(0.0)->and($advance->settle_by->toDateString())->toBe('2026-10-01')
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->sole()->only(['kind', 'entry_key', 'note']))->toBe(['kind' => 'disbursed', 'entry_key' => 'disbursed', 'note' => 'Dispensed outside payroll'])
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvance::class)->where('entity_id', $advance->id)->where('reason', 'Dispensed: UTR-1')->exists())->toBeTrue();
    expect(fn () => $this->advances->dispense($advance, 'UTR-2', '2026-09-21', $this->hr))->toThrow(ExpenseRuleViolation::class, 'This advance is dispensed and cannot be changed that way now.')
        ->and(fn () => $this->advances->cancel($advance, 'x', $this->user))->toThrow(ExpenseRuleViolation::class, 'A dispensed advance cannot be cancelled')
        ->and(DispenseAdvances::getNavigationBadge())->toBeNull();
    Livewire::test(DispenseAdvances::class)->assertCanNotSeeTableRecords([$advance]);
    Livewire::test(ListExpenseAdvances::class)->assertTableActionHidden('dispense', $advance)->assertTableActionHidden('cancelRest', $advance);
});

// ---- On-behalf filing ------------------------------------------------------------------------------------------------

it('lets HR request an advance for an employee in scope under the employee\'s policy and workflow, filed on behalf and audited; never one\'s own or outside the scope', function () {
    [$hrUser, $hrEmployee] = xo5Person($this->company, ['expense.approve', 'expense.view', 'expense.claim']);
    UserAccessScope::query()->create(['user_id' => $hrUser->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    [, $southEmployee] = xo5Person(Company::factory()->create(['name' => 'South']));
    $checker = tenantUser($this->tenant, ['expense.approve', 'expense.view']);

    $this->actingAs($hrUser);
    expect(ExpenseOnBehalf::candidates()->pluck('employees.id')->all())->toContain($this->employee->id)->not->toContain($hrEmployee->id)->not->toContain($southEmployee->id);

    // The employee's policy decides: the largest advance and the purpose it asks for.
    Livewire::test(ListExpenseAdvances::class)->assertActionVisible('requestForEmployee')
        ->callAction('requestForEmployee', ['employee_id' => $this->employee->id, 'currency' => 'INR', 'amount' => 25000, 'purpose' => 'Site visit'])->assertNotified('Not sent');
    Livewire::test(ListExpenseAdvances::class)->callAction('requestForEmployee', ['employee_id' => $this->employee->id, 'currency' => 'INR', 'amount' => 5000, 'purpose' => ''])
        ->assertHasActionErrors(['purpose' => 'required']);
    expect(ExpenseAdvance::query()->count())->toBe(0);

    Livewire::test(ListExpenseAdvances::class)->callAction('requestForEmployee', ['employee_id' => $this->employee->id, 'currency' => 'INR', 'amount' => 5000, 'needed_on' => '2026-09-25', 'purpose' => 'Site visit'])
        ->assertHasNoActionErrors();
    $advance = ExpenseAdvance::query()->sole();
    $submitted = xo5Submitted($advance);
    expect($advance->employee_id)->toBe($this->employee->id)->and($advance->requested_by)->toBe($hrUser->id)->and($advance->filed_on_behalf)->toBeTrue()
        ->and($advance->status)->toBe('requested')->and($advance->policy_version_id)->not->toBeNull()->and((float) $advance->amount_base)->toBe(5000.0)
        ->and($advance->needed_on->toDateString())->toBe('2026-09-25')
        ->and($submitted->metadata)->toEqual(['amount' => 5000, 'on_behalf' => true, 'employee_id' => $this->employee->id])->and($submitted->actor_id)->toBe($hrUser->id)
        // No workflow: it waits in the Approval Center for the employee's approvers, as the employee's own request would.
        ->and(collect(app(ApprovalCenter::class)->pending($checker))->firstWhere('type', 'expense_advance')?->title)->toContain($advance->number);
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]))->assertOk()->assertSee('Requested by')->assertSee($hrUser->name.', on the employee', false);

    // A workflow on expense.advance_requested decides it exactly as for the employee's own request.
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Checker', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $checker->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Advance approval', 'key' => 'xo5_advance', 'trigger_event' => 'expense.advance_requested']);
    $second = $this->advances->request($this->employee, ['amount' => 700, 'purpose' => 'Visa fee'], $hrUser);
    expect(WorkflowInstance::query()->where('subject_type', $second->getMorphClass())->where('subject_id', $second->id)->exists())->toBeTrue()
        ->and($second->refresh()->filed_on_behalf)->toBeTrue();

    // One's own goes through "Request an advance"; outside the scope is refused, on the screen and in the service.
    Livewire::test(ListExpenseAdvances::class)->callAction('requestForEmployee', ['employee_id' => $hrEmployee->id, 'currency' => 'INR', 'amount' => 100, 'purpose' => 'Mine'])->assertNotified('Not sent');
    Livewire::test(ListExpenseAdvances::class)->callAction('requestForEmployee', ['employee_id' => $southEmployee->id, 'currency' => 'INR', 'amount' => 100, 'purpose' => 'South']);
    expect(ExpenseAdvance::query()->whereIn('employee_id', [$hrEmployee->id, $southEmployee->id])->exists())->toBeFalse()
        ->and(fn () => ExpenseOnBehalf::resolve($hrEmployee->id, 'Request an advance'))->toThrow(RuntimeException::class, 'This is your own record: use "Request an advance".')
        ->and(fn () => ExpenseOnBehalf::resolve($southEmployee->id, 'Request an advance'))->toThrow(RuntimeException::class, 'Choose an employee in your access scope.')
        ->and(fn () => $this->advances->request($southEmployee, ['amount' => 100, 'purpose' => 'South'], $hrUser))->toThrow(ExpenseRuleViolation::class, 'You cannot request an advance for this employee.');

    // An employee without expense.approve is not offered the action.
    $this->actingAs($this->user);
    Livewire::test(ListExpenseAdvances::class)->assertActionHidden('requestForEmployee');
});

it('lets HR build and submit an expense report for an employee in scope from their unreported expenses, outside the submission window; never one\'s own or outside the scope', function () {
    [$hrUser, $hrEmployee] = xo5Person($this->company, ['expense.approve', 'expense.view', 'expense.claim']);
    UserAccessScope::query()->create(['user_id' => $hrUser->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    [$southUser, $southEmployee] = xo5Person(Company::factory()->create(['name' => 'South']));
    $checker = tenantUser($this->tenant, ['expense.approve', 'expense.view']);
    $first = xo5Expense($this->employee, $this->user, '2026-09-10', 400);
    $second = xo5Expense($this->employee, $this->user, '2026-09-12', 250);
    $mine = xo5Expense($hrEmployee, $hrUser, '2026-09-12', 99);
    $theirs = xo5Expense($southEmployee, $southUser, '2026-09-12', 75);
    // A submission window that excludes today (the 21st): the employee is held to it, filing on behalf is not (as before).
    xo5Setting('expense.report_window.enabled', true);
    xo5Setting('expense.report_window.first_from', 1);
    xo5Setting('expense.report_window.first_to', 5);

    $this->actingAs($hrUser);
    expect(ListExpenseReports::unreported($this->employee))->toHaveKeys([$first->id, $second->id]);
    Livewire::test(ListExpenseReports::class)->assertActionVisible('newReportForEmployee')
        ->callAction('newReportForEmployee', ['employee_id' => $this->employee->id, 'title' => 'September by HR', 'receipts' => [$first->id], 'submit_now' => false])->assertHasNoActionErrors();
    $draft = ExpenseReport::query()->where('title', 'September by HR')->sole();
    expect($draft->status)->toBe('draft')->and($draft->employee_id)->toBe($this->employee->id)->and($draft->created_by)->toBe($hrUser->id)->and($draft->filed_on_behalf)->toBeFalse()
        ->and($first->refresh()->expense_report_id)->toBe($draft->id)
        ->and(AuditEvent::query()->where('entity_type', ExpenseReport::class)->where('entity_id', $draft->id)->where('action', 'CREATE')->sole()->only(['reason', 'actor_id']))
        ->toBe(['reason' => 'Created on behalf of the employee', 'actor_id' => $hrUser->id]);
    // The employee may complete it, but submits only inside the window.
    expect(fn () => $this->reports->submit($draft, $this->user))->toThrow(ExpenseRuleViolation::class, 'Expense reports can be submitted between');

    Livewire::test(ListExpenseReports::class)
        ->callAction('newReportForEmployee', ['employee_id' => $this->employee->id, 'title' => 'Cab rides', 'receipts' => [$second->id], 'submit_now' => true])->assertHasNoActionErrors();
    $submitted = ExpenseReport::query()->where('title', 'Cab rides')->sole();
    expect($submitted->status)->toBe('submitted')->and($submitted->filed_on_behalf)->toBeTrue()->and($submitted->submitted_by)->toBe($hrUser->id)
        ->and($submitted->parts()->count())->toBe(1)->and((float) $submitted->total_claimed)->toBe(250.0)
        ->and(collect(app(ApprovalCenter::class)->pending($checker))->where('type', 'expense_report')->pluck('title')->implode(' '))->toContain($submitted->number);
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $submitted]))->assertOk()->assertSee('Filed for the employee')
        ->assertSee('Created by '.$hrUser->name)->assertSee('Submitted by '.$hrUser->name);

    // One's own report goes through "New expense report"; outside the scope is refused.
    Livewire::test(ListExpenseReports::class)->callAction('newReportForEmployee', ['employee_id' => $hrEmployee->id, 'title' => 'Mine', 'receipts' => [$mine->id]])->assertNotified('Not created');
    Livewire::test(ListExpenseReports::class)->callAction('newReportForEmployee', ['employee_id' => $southEmployee->id, 'title' => 'South', 'receipts' => [$theirs->id]]);
    expect(ExpenseReport::query()->whereIn('title', ['Mine', 'South'])->exists())->toBeFalse()
        ->and($mine->refresh()->expense_report_id)->toBeNull()->and($theirs->refresh()->expense_report_id)->toBeNull()
        ->and(fn () => $this->reports->create($southEmployee, 'South', [], $hrUser))->toThrow(ExpenseRuleViolation::class, 'You cannot file expense reports for this employee.');
});

// ---- Dispense advances page --------------------------------------------------------------------------------------------

it('lists what waits and what was dispensed with the employee\'s outstanding, the note and filters; the badge counts what the person may dispense', function () {
    [$payerUser, $payer] = xo5Person($this->company, ['expense.pay', 'expense.view', 'expense.claim']);
    UserAccessScope::query()->create(['user_id' => $payerUser->id, 'dimension' => 'company', 'scope_id' => $this->company->id]);
    [$southUser, $southEmployee] = xo5Person(Company::factory()->create(['name' => 'South']));

    $this->travelTo('2026-09-05 09:00:00');
    $old = xo5Approved($this->employee, $this->user, 1500, 'Earlier trip');
    $this->advances->dispense($old, 'UTR-OLD', '2026-09-05', $this->hr, null, 'Paid by NEFT');
    $this->travelTo('2026-09-21 09:00:00');
    $waiting = xo5Approved($this->employee, $this->user, 2000, 'Client visit');
    $theirs = xo5Approved($southEmployee, $southUser, 900, 'South visit');
    $own = xo5Approved($payer, $payerUser, 300, 'Own');
    $closed = xo5Approved($this->employee, $this->user, 500, 'Small');
    $this->advances->dispense($closed, 'UTR-S', '2026-09-21', $this->hr);
    $this->advances->refund($closed->refresh(), 500, 'CASH-1', '2026-09-21', $this->hr);
    expect($closed->refresh()->status)->toBe('settled')
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $old->id)->sole()->note)->toBe('Paid by NEFT');

    // The payer's scope: their company's advances, never their own in the badge (it lists, but cannot be dispensed, as before).
    $this->actingAs($payerUser);
    expect(DispenseAdvances::getNavigationBadge())->toBe('1');
    $page = Livewire::test(DispenseAdvances::class)->assertOk()->assertCanSeeTableRecords([$waiting, $own])->assertCanNotSeeTableRecords([$theirs, $old, $closed])
        ->assertTableActionVisible('dispense', $waiting)->assertTableActionHidden('dispense', $own)->assertSee('To dispense')->assertSee('Dispensed');
    $rows = collect($page->instance()->getTableRecords()->items())->keyBy('id');
    expect((float) $rows[$waiting->id]->employee_outstanding)->toBe(1500.0)->and((float) $rows[$own->id]->employee_outstanding)->toBe(0.0);
    $page->filterTable('requested', ['from' => '2026-09-22'])->assertCanNotSeeTableRecords([$waiting, $own]);

    // "Dispensed": what was paid out in scope, with the note; dispense-date and status filters.
    Livewire::withQueryParams(['tab' => 'dispensed'])->test(DispenseAdvances::class)->assertOk()->assertSet('dispenseTab', 'dispensed')
        ->assertCanSeeTableRecords([$old, $closed])->assertCanNotSeeTableRecords([$waiting, $theirs, $own])->assertSee('Paid by NEFT')->assertSee('UTR-OLD')
        ->filterTable('dispensed', ['from' => '2026-09-01', 'until' => '2026-09-10'])
        ->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$closed]);
    Livewire::withQueryParams(['tab' => 'dispensed'])->test(DispenseAdvances::class)->filterTable('status', ['settled'])
        ->assertCanSeeTableRecords([$closed])->assertCanNotSeeTableRecords([$old]);

    // Dispensing from the page with a note: kept on the ledger entry and the advance, shown under "Dispensed".
    // (withQueryParams stays set on the Livewire manager: back to the default tab.)
    Livewire::withQueryParams([])->test(DispenseAdvances::class)->callTableAction('dispense', $waiting, ['reference' => 'UTR-W', 'on' => '2026-09-21', 'note' => 'Cash for the client visit'])->assertHasNoTableActionErrors();
    expect($waiting->refresh()->disbursement_note)->toBe('Cash for the client visit')->and((float) $waiting->outstanding)->toBe(2000.0)
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $waiting->id)->sole()->note)->toBe('Cash for the client visit')
        ->and(DispenseAdvances::getNavigationBadge())->toBeNull();
    Livewire::withQueryParams(['tab' => 'dispensed'])->test(DispenseAdvances::class)->assertCanSeeTableRecords([$waiting])->assertSee('Cash for the client visit');
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $waiting]))->assertOk()->assertSee('Dispense note')->assertSee('Cash for the client visit');

    // HR without a scope limit counts the south advance and the payer's (not HR's own); people without expense.pay get no badge.
    $this->actingAs($this->hr);
    expect(DispenseAdvances::getNavigationBadge())->toBe('2');
    $this->actingAs($this->user);
    expect(DispenseAdvances::getNavigationBadge())->toBeNull();
});

// ---- Partial dispense (owner decision D-XO8 / EXP-14) ------------------------------------------------------------------

it('with partial dispense on, pays an advance out in several payouts up to the approved amount, settle-by from the first, the rest lapsing when it is settled', function () {
    xo5Setting('expense.advance.partial_dispense', true);
    xo5Policy('expense_advance', ['enabled' => true, 'max_open' => 10, 'max_outstanding_total' => 12000, 'settle_within_days' => 10, 'recovery' => 'payroll', 'recovery_after_days' => 5], [$this->employee]);
    $this->travelTo('2026-09-01 09:00:00');
    $advance = xo5Approved($this->employee, $this->user, 10000, 'Project kick-off');

    expect(fn () => $this->advances->dispense($advance, 'UTR-1', '2026-09-01', $this->hr, 10001))->toThrow(ExpenseRuleViolation::class, 'between 0.01 and what is left to dispense (10,000.00)');
    $this->advances->dispense($advance, 'UTR-1', '2026-09-01', $this->hr, 4000, 'First tranche');
    $advance->refresh();
    expect($advance->status)->toBe('disbursed')->and((float) $advance->outstanding)->toBe(4000.0)->and((float) $advance->dispensed_amount)->toBe(4000.0)
        ->and($advance->remainingToDispense())->toBe(6000.0)->and($advance->disbursed_on->toDateString())->toBe('2026-09-01')
        ->and($advance->settle_by->toDateString())->toBe('2026-09-11')->and($advance->recovery_due_on->toDateString())->toBe('2026-09-16')
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvance::class)->where('entity_id', $advance->id)->where('reason', 'Dispensed: UTR-1 (4,000.00 of 10,000.00)')->exists())->toBeTrue();
    // What may still be paid out counts against the policy's outstanding total.
    expect(fn () => $this->advances->request($this->employee, ['amount' => 3000, 'purpose' => 'More'], $this->user))->toThrow(ExpenseRuleViolation::class, '10,000.00 is already open');

    // The rest waits under "To dispense" (and in the badge) for further payouts, never past what is left.
    $this->travelTo('2026-09-08 09:00:00');
    expect(DispenseAdvances::getNavigationBadge())->toBe('1');
    Livewire::test(DispenseAdvances::class)->assertCanSeeTableRecords([$advance])->assertSee('4,000.00 of 10,000.00 paid out')->assertTableActionVisible('dispense', $advance)
        ->callTableAction('dispense', $advance, ['amount' => 3000, 'reference' => 'UTR-2', 'on' => '2026-09-08'])->assertHasNoTableActionErrors();
    $advance->refresh();
    expect((float) $advance->outstanding)->toBe(7000.0)->and((float) $advance->dispensed_amount)->toBe(7000.0)->and($advance->remainingToDispense())->toBe(3000.0)
        ->and($advance->settle_by->toDateString())->toBe('2026-09-11')->and($advance->recovery_due_on->toDateString())->toBe('2026-09-16')
        ->and($advance->disbursed_on->toDateString())->toBe('2026-09-01')->and($advance->disbursement_reference)->toBe('UTR-1')
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->orderBy('id')->pluck('entry_key')->all())->toBe(['disbursed', 'disbursed-2'])
        ->and(xo5Ledger($advance))->toBe([4000.0, 3000.0]);
    expect(fn () => $this->advances->dispense($advance, 'UTR-3', '2026-09-08', $this->hr, 3500))->toThrow(ExpenseRuleViolation::class, 'what is left to dispense (3,000.00)')
        ->and(fn () => $this->advances->dispense($advance, 'UTR-3', '2026-08-31', $this->hr, 100))->toThrow(ExpenseRuleViolation::class, 'A further payout is on or after the first one');
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]))->assertOk()->assertSee('Paid out so far')->assertSee('Left to pay out');

    // A report settles what was paid out (never the part not paid): the advance is settled and the rest lapses.
    $report = xo5ApprovedReport($this->employee, $this->user, '2026-09-05', 7500);
    $advance->refresh();
    expect((float) $report->advance_adjusted)->toBe(7000.0)->and((float) $report->net_payable)->toBe(500.0)
        ->and($advance->status)->toBe('settled')->and((float) $advance->outstanding)->toBe(0.0)->and($advance->remainder_lapsed_at)->not->toBeNull()
        ->and($advance->lapsedAmount())->toBe(3000.0)->and($advance->remainingToDispense())->toBe(0.0)
        ->and(xo5Ledger($advance))->toBe([4000.0, 3000.0, -7000.0])->and(array_sum(xo5Ledger($advance)))->toBe(0.0)
        ->and(fn () => $this->advances->dispense($advance, 'UTR-4', '2026-09-08', $this->hr, 100))->toThrow(ExpenseRuleViolation::class, 'This advance is settled and cannot be changed that way now.')
        ->and(DispenseAdvances::getNavigationBadge())->toBeNull();
    Livewire::withQueryParams(['tab' => 'dispensed'])->test(DispenseAdvances::class)->assertCanSeeTableRecords([$advance])->assertSee('3,000.00 lapsed');
});

it('with partial dispense on, lets payroll recover only what was paid out, refuses a further payout while it recovers, and the rest lapses when recovered', function () {
    xo5Setting('expense.advance.partial_dispense', true);
    xo5Policy('expense_advance', ['enabled' => true, 'max_open' => 10, 'settle_within_days' => 5, 'recovery' => 'payroll', 'recovery_after_days' => 5], [$this->employee]);
    $this->travelTo('2026-09-01 09:00:00');
    $advance = xo5Approved($this->employee, $this->user, 5000, 'Tools');
    $this->advances->dispense($advance, 'UTR-1', '2026-09-01', $this->hr, 2000);
    expect($advance->refresh()->recovery_due_on->toDateString())->toBe('2026-09-11');

    $this->travelTo('2026-09-21 09:00:00');
    $september = xo5Calculate(9);
    $line = xo5Line($september, 'EXP_ADV_RECOVERY');
    expect($line)->not->toBeNull()->and((float) $line->amount)->toBe(2000.0)->and($line->basis['source'])->toEqual(['key' => 'expense_advance_recovery', 'ids' => [$advance->id]])
        ->and(fn () => $this->advances->dispense($advance->refresh(), 'UTR-2', '2026-09-21', $this->hr, 1000))->toThrow(ExpenseRuleViolation::class, 'already being recovered in the payroll of');
    xo5Finalize($september);

    $advance->refresh();
    $recovered = ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->where('kind', 'recovered')->sole();
    expect($advance->status)->toBe('recovered')->and((float) $advance->outstanding)->toBe(0.0)->and($advance->lapsedAmount())->toBe(3000.0)
        ->and($recovered->entry_key)->toBe('payroll-'.$september->id)->and(xo5Ledger($advance))->toBe([2000.0, -2000.0]);

    // Never twice: the next payroll neither recovers nor pays anything for it.
    $this->travelTo('2026-10-25 09:00:00');
    $october = xo5Calculate(10);
    expect(xo5Line($october, 'EXP_ADV_RECOVERY'))->toBeNull()->and(xo5Line($october, 'EXP_ADVANCE'))->toBeNull();
});

it('with partial dispense on, cancels the rest of a partly dispensed advance (or lets it lapse with its trip) while what was paid stays to be settled', function () {
    xo5Setting('expense.advance.partial_dispense', true);
    $advance = xo5Approved($this->employee, $this->user, 4000, 'Conference');
    $this->advances->dispense($advance, 'UTR-1', '2026-09-21', $this->hr, 1000);

    // The employee's Cancel lets the rest lapse; what was paid stays outstanding.
    $this->advances->cancel($advance->refresh(), 'Trip shortened', $this->user);
    $advance->refresh();
    expect($advance->status)->toBe('disbursed')->and((float) $advance->outstanding)->toBe(1000.0)->and($advance->remainingToDispense())->toBe(0.0)->and($advance->lapsedAmount())->toBe(3000.0)
        ->and(AuditEvent::query()->where('entity_type', ExpenseAdvance::class)->where('entity_id', $advance->id)->where('action', 'CANCELLED')->sole()->metadata)->toEqual(['remainder' => 3000, 'lapsed' => true])
        ->and(fn () => $this->advances->cancelRemainder($advance, 'Again', $this->hr))->toThrow(ExpenseRuleViolation::class, 'Nothing is left to pay out on this advance.')
        ->and(fn () => $this->advances->dispense($advance, 'UTR-2', '2026-09-21', $this->hr, 100))->toThrow(ExpenseRuleViolation::class, 'is dispensed in full (the rest has lapsed)');

    // Finance cancels the rest from the register; an advance dispensed in full has no rest to cancel.
    $second = xo5Approved($this->employee, $this->user, 2000, 'Workshop');
    $this->advances->dispense($second, 'UTR-3', '2026-09-21', $this->hr, 500);
    $full = xo5Approved($this->employee, $this->user, 700, 'Visa');
    $this->advances->dispense($full, 'UTR-4', '2026-09-21', $this->hr);
    Livewire::test(ListExpenseAdvances::class)->assertTableActionVisible('cancelRest', $second->refresh())->assertTableActionHidden('cancelRest', $full->refresh())
        ->callTableAction('cancelRest', $second, ['reason' => 'Not needed'])->assertHasNoTableActionErrors();
    expect($second->refresh()->lapsedAmount())->toBe(1500.0)->and((float) $second->outstanding)->toBe(500.0)->and($second->status)->toBe('disbursed')
        ->and($full->dispensed_amount)->toBeNull()->and($full->lapsedAmount())->toBe(0.0)
        ->and(fn () => $this->advances->cancel($full, 'x', $this->user))->toThrow(ExpenseRuleViolation::class, 'A dispensed advance cannot be cancelled');

    // A trip cancelled lets the rest of its advance lapse; what was paid stays.
    $trips = app(TravelRequests::class);
    $trip = $trips->submit($this->employee, xo5Trip($this->hotel, ['advance_amount' => 3000]), $this->user);
    $trips->approve($trip, null, $this->hr);
    $tripAdvance = ExpenseAdvance::query()->findOrFail($trip->refresh()->expense_advance_id);
    $this->advances->dispense($tripAdvance, 'UTR-T', '2026-09-21', $this->hr, 1200);
    $trips->cancel($trip->refresh(), 'Client postponed', $this->hr);
    expect($trip->refresh()->status)->toBe('cancelled')->and($tripAdvance->refresh()->status)->toBe('disbursed')
        ->and((float) $tripAdvance->outstanding)->toBe(1200.0)->and($tripAdvance->lapsedAmount())->toBe(1800.0)
        ->and(DispenseAdvances::getNavigationBadge())->toBeNull();
});

// ---- Trip page, History tab, EXP-3 --------------------------------------------------------------------------------------

it('shows the advance\'s amount, needed-on date and link on the trip page, and the trip on the advance page and the dispense list', function () {
    $trips = app(TravelRequests::class);
    $trip = $trips->submit($this->employee, xo5Trip($this->hotel, ['advance_amount' => 2500]), $this->user);
    $advance = ExpenseAdvance::query()->findOrFail($trip->refresh()->expense_advance_id);

    $this->get(TravelRequestResource::getUrl('view', ['record' => $trip]))->assertOk()->assertSee('Advance amount')->assertSee('INR 2,500.00')
        ->assertSee('Advance needed on')->assertSee('Oct 5, 2026')->assertSee(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]), false);
    $trips->approve($trip, null, $this->hr);
    $this->get(TravelRequestResource::getUrl('view', ['record' => $trip]))->assertOk()->assertSee('approved INR 2,500.00');
    $this->actingAs($this->user);
    $this->get(TravelRequestResource::getUrl('view', ['record' => $trip]))->assertOk()->assertSee('Advance amount')->assertSee('Oct 5, 2026');

    $this->actingAs($this->hr);
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]))->assertOk()->assertSee('Trip')->assertSee($trip->number)
        ->assertSee(TravelRequestResource::getUrl('view', ['record' => $trip]), false);
    Livewire::test(DispenseAdvances::class)->assertCanSeeTableRecords([$advance->refresh()])->assertSee($trip->number);
});

it('shows the advance\'s own audit trail on a read-only History tab to audit viewers', function () {
    $advance = xo5Approved($this->employee, $this->user, 1200);
    $this->advances->dispense($advance, 'UTR-1', '2026-09-21', $this->hr);
    $events = AuditEvent::query()->where('entity_type', ExpenseAdvance::class)->where('entity_id', $advance->id)->get();

    expect(ExpenseAdvanceResource::getRelations())->toBe([EntriesRelationManager::class, AuditHistoryRelationManager::class])
        ->and($events->map(fn (AuditEvent $e) => $e->action->value)->all())->toContain('SUBMITTED', 'APPROVED', 'UPDATE')
        ->and(AuditHistoryRelationManager::canViewForRecord($advance, ViewExpenseAdvance::class))->toBeTrue();
    Livewire::test(AuditHistoryRelationManager::class, ['ownerRecord' => $advance, 'pageClass' => ViewExpenseAdvance::class])->assertOk()
        ->assertCanSeeTableRecords($events)->assertSee('Dispensed: UTR-1')
        ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')->assertTableActionDoesNotExist('create');
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]))->assertOk()->assertSee('History')->assertSee('Ledger');

    // The employee sees their advance and its ledger, never the History tab.
    $this->actingAs($this->user);
    expect(AuditHistoryRelationManager::canViewForRecord($advance, ViewExpenseAdvance::class))->toBeFalse()
        ->and(EntriesRelationManager::canViewForRecord($advance, ViewExpenseAdvance::class))->toBeTrue();
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]))->assertOk()->assertSee('UTR-1');
});

it('keeps EXP-3 as built: an advance approved for payroll may be dispensed by bank before payroll runs, and payroll then pays nothing for it', function () {
    xo5Policy('expense_advance', ['enabled' => true, 'max_open' => 10, 'disbursement' => 'payroll'], [$this->employee]);
    $advance = xo5Approved($this->employee, $this->user, 1800);
    expect($advance->disbursement_channel)->toBe('payroll');
    Livewire::test(DispenseAdvances::class)->assertCanSeeTableRecords([$advance])->assertSee('With payroll')
        ->callTableAction('dispense', $advance, ['reference' => 'UTR-B', 'on' => '2026-09-21'])->assertHasNoTableActionErrors();
    expect($advance->refresh()->status)->toBe('disbursed')->and($advance->disbursement_channel)->toBe('bank')->and((float) $advance->outstanding)->toBe(1800.0);
    expect(xo5Line(xo5Calculate(9), 'EXP_ADVANCE'))->toBeNull();

    // With partial dispense on, a part paid by bank also takes the advance off the payroll payout; the rest is paid here.
    xo5Setting('expense.advance.partial_dispense', true);
    $part = xo5Approved($this->employee, $this->user, 1000);
    $this->advances->dispense($part, 'UTR-C', '2026-09-21', $this->hr, 400);
    expect(xo5Line(xo5Calculate(9), 'EXP_ADVANCE'))->toBeNull()
        ->and($part->refresh()->disbursement_channel)->toBe('bank')->and($part->remainingToDispense())->toBe(600.0);

    // An advance a payroll run already pays out is paid by that run, in full, once (unchanged).
    $carried = xo5Approved($this->employee, $this->user, 700);
    $run = xo5Calculate(9);
    expect((float) xo5Line($run, 'EXP_ADVANCE')->amount)->toBe(700.0)
        ->and(fn () => $this->advances->dispense($carried->refresh(), 'UTR-D', '2026-09-21', $this->hr, 300))->toThrow(ExpenseRuleViolation::class, 'already being paid out in the payroll of');
    xo5Finalize($run);
    expect($carried->refresh()->status)->toBe('disbursed')->and($carried->disbursement_channel)->toBe('payroll')->and((float) $carried->outstanding)->toBe(700.0)
        ->and($carried->dispensed_amount)->toBeNull()->and($carried->remainingToDispense())->toBe(0.0)->and(xo5Ledger($carried))->toBe([700.0]);
});
