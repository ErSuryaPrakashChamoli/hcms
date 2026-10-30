<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Expense\Events\ExpenseEvent;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseGroup;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Expense\Models\ExpenseReportPart;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseCoverLetters;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\PayExpenses;
use App\Filament\Resources\ExpenseReceipts\ExpenseReceiptResource;
use App\Filament\Resources\ExpenseReceipts\Pages\ListExpenseReceipts;
use App\Filament\Resources\ExpenseReports\ExpenseReportResource;
use App\Filament\Resources\ExpenseReports\Pages\ListExpenseReports;
use App\Filament\Resources\ExpenseReports\Pages\ViewExpenseReport;
use App\Filament\Resources\ExpenseReports\RelationManagers\PartsRelationManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity EX-C — expense reports: draft → submit (windows, months, workflow groups) → parts decided line by line
 * (directly, in the Approval Center or by a workflow through ExpenseWorkflowBridge) → approved / partly approved /
 * rejected; return, cancel, the recorded payment (Pay expenses), the cover letter, notifications and the screens. */

beforeEach(function () {
    $this->travelTo('2026-05-20 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = Company::factory()->create();
    [$this->managerUser, $this->manager] = exrPerson($this->tenant, $this->company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = exrPerson($this->tenant, $this->company, ['expense.claim'], $this->manager);
    $this->meals = ExpenseGroup::query()->where('code', 'MEALS')->firstOrFail();
    $this->travel = ExpenseGroup::query()->where('code', 'TRAVEL')->firstOrFail();
    $this->food = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Food', 'code' => 'FOOD', 'expense_group_id' => $this->meals->id,
        'limit_basis' => 'amount', 'limit_exception_allowed' => true, 'limits' => [['amount' => 1000, 'period' => 'day']]]);
    $this->cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB', 'expense_group_id' => $this->travel->id]);
    $this->hotel = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Hotel', 'code' => 'HOTEL', 'expense_group_id' => $this->travel->id, 'reimbursable' => false]);
    exrPolicy(['categories' => [['category' => $this->food->id], ['category' => $this->cab->id], ['category' => $this->hotel->id]]]);
    $this->reports = app(ExpenseReports::class);
});

/** @return array{0: User, 1: Employee} */
function exrPerson(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $employee];
}

function exrPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'expense', 'name' => 'Expense '.uniqid(), 'code' => 'EXR_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'expense', 'policy_id' => $policy->id, 'name' => 'Everyone', 'conditions' => [], 'priority' => 1000]);
}

function exrSetting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function exrReceipt(Employee $employee, User $as, string $code, string $date, float $amount, array $extra = []): ExpenseReceipt
{
    $category = ExpenseCategory::query()->where('code', $code)->firstOrFail();

    return app(ExpenseReceipts::class)->capture($employee, ['expense_category_id' => $category->id, 'expense_date' => $date, 'amount' => $amount] + $extra, $as);
}

it('builds a report from unreported expenses, submits it as one approval and approves it line by line', function () {
    $a = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-18', 800);
    $b = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-19', 300);
    $report = $this->reports->create($this->employee, 'May trip', [$a->id, $b->id], $this->user);
    expect($report->number)->toStartWith('EXR-2026-')->and($report->status)->toBe('draft')->and((float) $report->total_claimed)->toBe(1100.0)
        ->and($a->refresh()->status)->toBe('reported')
        ->and(fn () => $this->reports->create($this->employee, 'Again', [$a->id], $this->user))->toThrow(ExpenseRuleViolation::class, 'only unreported expenses can be added');
    // An expense in a draft can still be changed; the report claims the new amount.
    app(ExpenseReceipts::class)->update($b, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-19', 'amount' => 300], $this->user);
    expect((float) $report->refresh()->total_claimed)->toBe(1100.0);
    app(ExpenseReceipts::class)->update($b, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-19', 'amount' => 350], $this->user);
    expect((float) $report->refresh()->total_claimed)->toBe(1150.0);
    app(ExpenseReceipts::class)->update($b, ['expense_category_id' => $this->cab->id, 'expense_date' => '2026-05-19', 'amount' => 300], $this->user);

    Event::fake([ExpenseEvent::class]);
    $submitted = $this->reports->submit($report, $this->user);
    Event::assertDispatched(ExpenseEvent::class, fn (ExpenseEvent $e) => $e->name === 'expense.report_submitted' && $e->context['workflow_group'] === 'all' && $e->context['amount'] === 1100.0 && $e->recipientUserIds === [$this->managerUser->id]);
    expect($submitted->status)->toBe('submitted')->and($submitted->period_from->toDateString())->toBe('2026-05-18')->and($submitted->period_to->toDateString())->toBe('2026-05-19')
        ->and($part = $submitted->parts()->sole())->label->toBe('All expenses');

    expect(fn () => $this->reports->approvePart($part, null, null, $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot decide your own expense report.')
        ->and(fn () => $this->reports->approvePart($part, [$a->id => 900], null, $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'approve between 0 and the claimable 800.00');
    $this->reports->approvePart($part, [$a->id => ['amount' => 600, 'note' => 'Above the meal norm'], $b->id => 0], 'Checked', $this->managerUser);
    $report->refresh();
    expect($report->status)->toBe('partly_approved')->and((float) $report->total_approved)->toBe(600.0)->and((float) $report->net_payable)->toBe(600.0)
        ->and($report->decided_by)->toBe($this->managerUser->id)
        ->and($a->refresh()->status)->toBe('partly_approved')->and($a->approver_note)->toBe('Above the meal norm')
        ->and($b->refresh()->status)->toBe('rejected')
        ->and(fn () => $this->reports->approvePart($part, null, null, $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'already been decided');

    // Approved amounts are what later limits count: 600 of the day's 1,000 food limit is used on the 18th.
    expect((float) exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-18', 900)->claimable_amount)->toBe(400.0);
});

it('repays only reimbursable lines (company-paid lines are approved, never paid) and rejects a report whose lines are all refused', function () {
    $food = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-18', 500);
    $hotel = exrReceipt($this->employee, $this->user, 'HOTEL', '2026-05-18', 4000);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Stay', [$food->id, $hotel->id], $this->user), $this->user);
    $this->reports->approvePart($report->parts()->sole(), null, null, $this->managerUser);
    expect($report->refresh())->status->toBe('approved')->and((float) $report->total_approved)->toBe(4500.0)->and((float) $report->reimbursable_approved)->toBe(500.0)
        ->and((float) $report->net_payable)->toBe(500.0)->and($report->isPayable())->toBeTrue();

    $c = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-19', 100);
    $second = $this->reports->submit($this->reports->create($this->employee, 'Cab', [$c->id], $this->user), $this->user);
    expect(fn () => $this->reports->approvePart($second->parts()->sole(), [$c->id => 0], null, $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'give the reason');
    expect(fn () => $this->reports->rejectPart($second->parts()->sole(), '', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'Give the reason for rejecting.');
    $this->reports->rejectPart($second->parts()->sole(), 'Personal travel', $this->managerUser);
    expect($second->refresh()->status)->toBe('rejected')->and((float) $second->net_payable)->toBe(0.0)->and($c->refresh()->status)->toBe('rejected');
});

it('submits only inside the submission windows, unless filed on behalf', function () {
    exrSetting('expense.report_window.enabled', true);
    exrSetting('expense.report_window.first_from', 1);
    exrSetting('expense.report_window.first_to', 10);
    $report = $this->reports->create($this->employee, 'May', [exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 100)->id], $this->user);
    expect(fn () => $this->reports->submit($report, $this->user))->toThrow(ExpenseRuleViolation::class, 'Expense reports can be submitted between 1–10 of the month.');

    exrSetting('expense.report_window.second_enabled', true);
    exrSetting('expense.report_window.second_from', 16);
    exrSetting('expense.report_window.second_to', 40);
    expect($this->reports->submit($report, $this->user)->window_cycle)->toBe(2);

    exrSetting('expense.report_window.second_enabled', false);
    $other = $this->reports->create($this->employee, 'More', [exrReceipt($this->employee, $this->user, 'CAB', '2026-05-17', 100)->id], $this->user);
    $onBehalf = $this->reports->submit($other, $this->managerUser);
    expect($onBehalf->status)->toBe('submitted')->and($onBehalf->filed_on_behalf)->toBeTrue()->and($onBehalf->window_cycle)->toBeNull();
});

it('keeps a month or a workflow group to a report when the settings say so, and splits approvals by category or expense group', function () {
    $april = exrReceipt($this->employee, $this->user, 'CAB', '2026-04-28', 100);
    $may = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-02', 100);
    exrSetting('expense.report.multiple_months', false);
    expect(fn () => $this->reports->create($this->employee, 'Mixed', [$april->id, $may->id], $this->user))->toThrow(ExpenseRuleViolation::class, 'A report holds expenses of one month');
    exrSetting('expense.report.multiple_months', true);

    exrSetting('expense.workflow.split_by', 'group');
    exrSetting('expense.workflow.split_scope', 'listed');
    exrSetting('expense.workflow.exclusive_groups', [(string) $this->travel->id]);
    $food = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-18', 200);
    $hotel = exrReceipt($this->employee, $this->user, 'HOTEL', '2026-05-18', 900);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Split', [$april->id, $may->id, $food->id, $hotel->id], $this->user), $this->user);
    expect($report->parts()->get()->map(fn (ExpenseReportPart $p) => [$p->workflow_group, $p->label, (float) $p->total_claimed])->all())
        ->toBe([['group:TRAVEL', 'Travel', 1100.0], ['all', 'All expenses', 200.0]]);

    // Approving one part leaves the report waiting for the other.
    $travelPart = $report->parts()->where('workflow_group', 'group:TRAVEL')->sole();
    $this->reports->approvePart($travelPart, null, null, $this->managerUser);
    expect($report->refresh()->status)->toBe('submitted');
    $this->reports->approvePart($report->parts()->where('workflow_group', 'all')->sole(), null, null, $this->managerUser);
    expect($report->refresh()->status)->toBe('approved');

    exrSetting('expense.workflow.split_by', 'category');
    exrSetting('expense.workflow.split_scope', 'all');
    exrSetting('expense.report.mixed_workflow_groups', false);
    $one = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-19', 50);
    $two = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-19', 50);
    expect(fn () => $this->reports->create($this->employee, 'Mixed groups', [$one->id, $two->id], $this->user))->toThrow(ExpenseRuleViolation::class, 'Cab, Food go in separate reports');
});

it('sends exceptional expenses to an approval of their own, raising the exceptional event', function () {
    exrSetting('expense.exceptional.enabled', true);
    $normal = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-18', 300);
    $over = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-19', 1500, ['exceptional_reason' => 'Team dinner']);
    Event::fake([ExpenseEvent::class]);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Dinner', [$normal->id, $over->id], $this->user), $this->user);
    Event::assertDispatched(ExpenseEvent::class, fn (ExpenseEvent $e) => $e->name === 'expense.exceptional_submitted' && $e->context['workflow_group'] === 'exceptional' && $e->context['amount'] === 1500.0);
    Event::assertDispatched(ExpenseEvent::class, fn (ExpenseEvent $e) => $e->name === 'expense.report_submitted' && $e->context['amount'] === 300.0);
    expect($report->parts()->pluck('kind', 'workflow_group')->all())->toBe(['all' => 'standard', 'exceptional' => 'exceptional']);
});

it('starts a workflow per part and decides the part when the workflow ends, in the name of the last approver', function () {
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Manager', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->managerUser->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Expense approval', 'key' => 'expense_approval', 'trigger_event' => 'expense.report_submitted']);
    $receipt = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 250);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Cab', [$receipt->id], $this->user), $this->user);
    $part = $report->parts()->sole();
    $instance = WorkflowInstance::query()->where('subject_type', $part->getMorphClass())->where('subject_id', $part->id)->sole();
    expect($instance->contextValue('workflow_group'))->toBe('all')->and($instance->contextValue('number'))->toBe($report->number);

    // Running a workflow, the part is decided as a workflow task, not listed by the expense source.
    expect(collect(app(ApprovalCenter::class)->pending($this->managerUser))->pluck('type')->all())->not->toContain('expense_report');
    $task = WorkflowTask::query()->where('workflow_instance_id', $instance->id)->sole();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'Fine', $this->managerUser);
    expect($part->refresh()->status)->toBe('approved')->and($part->decided_by)->toBe($this->managerUser->id)->and($report->refresh()->status)->toBe('approved');
});

it('lists report parts in the Approval Center, decides and returns from there, and never shows one\'s own', function () {
    $receipt = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 250);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Cab', [$receipt->id], $this->user), $this->user);
    $items = collect(app(ApprovalCenter::class)->pending($this->managerUser))->where('type', 'expense_report');
    expect($items)->toHaveCount(1)->and($items->first()->title)->toContain($report->number)->and($items->first()->decisions)->toBe(['approve', 'reject', 'request_change'])
        ->and(collect(app(ApprovalCenter::class)->pending($this->user))->where('type', 'expense_report'))->toHaveCount(0);

    app(ApprovalDecisions::class)->decide($this->managerUser, $items->first()->id, 'request_change', 'Add the bill');
    expect($report->refresh()->status)->toBe('returned')->and($report->return_note)->toBe('Add the bill')->and($receipt->refresh()->status)->toBe('reported')
        ->and($report->parts()->sole()->status)->toBe('returned');

    // The employee changes and resubmits: a new approval is decided from the Approval Center.
    $this->reports->submit($report, $this->user);
    $item = collect(app(ApprovalCenter::class)->pending($this->managerUser))->firstWhere('type', 'expense_report');
    expect(app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'approve'))->toBe('Expenses approved')
        ->and($report->refresh()->status)->toBe('approved')->and($report->parts()->count())->toBe(2);
});

it('cancels a draft, returned or undecided report and releases its expenses, but not once a part is decided', function () {
    $a = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 100);
    $draft = $this->reports->create($this->employee, 'Draft', [$a->id], $this->user);
    $this->reports->cancel($draft, 'Not needed', $this->user);
    expect($draft->refresh()->status)->toBe('cancelled')->and($a->refresh()->status)->toBe('unreported')->and($a->expense_report_id)->toBeNull();

    exrSetting('expense.workflow.split_by', 'category');
    exrSetting('expense.workflow.split_scope', 'all');
    $b = exrReceipt($this->employee, $this->user, 'FOOD', '2026-05-18', 100);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Two', [$a->id, $b->id], $this->user), $this->user);
    $this->reports->approvePart($report->parts()->where('workflow_group', 'category:CAB')->sole(), null, null, $this->managerUser);
    expect(fn () => $this->reports->cancel($report, 'Changed my mind', $this->user))->toThrow(ExpenseRuleViolation::class, 'already decided');
    [$strangerUser] = exrPerson($this->tenant, $this->company, ['expense.claim']);
    expect(fn () => $this->reports->cancel($report, 'x', $strangerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot change this report.');
});

it('records the outside payment of an approved report on Pay expenses, never one\'s own, and shows it to the employee when allowed', function () {
    [$payerUser] = exrPerson($this->tenant, $this->company, ['expense.pay', 'expense.claim']);
    $receipt = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 250);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Cab', [$receipt->id], $this->user), $this->user);
    expect(fn () => $this->reports->markPaid($report, 'UTR1', '2026-05-20', $payerUser))->toThrow(ExpenseRuleViolation::class, 'Only an approved report');
    $this->reports->approvePart($report->parts()->sole(), null, null, $this->managerUser);
    expect(fn () => $this->reports->markPaid($report, 'UTR1', '2026-05-20', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot record payments')
        ->and(fn () => $this->reports->markPaid($report, 'UTR1', '2026-05-21', $payerUser))->toThrow(ExpenseRuleViolation::class, 'on or before today');

    $this->actingAs($payerUser);
    Livewire::test(PayExpenses::class)->assertOk()->assertCanSeeTableRecords([$report])
        ->callTableAction('pay', $report, ['reference' => 'UTR-778', 'paid_on' => '2026-05-20'])->assertHasNoTableActionErrors();
    expect($report->refresh())->status->toBe('paid')->and($report->payment_channel)->toBe('bank')->and($report->payment_reference)->toBe('UTR-778')->and($report->paid_by)->toBe($payerUser->id);

    $this->actingAs($this->user);
    expect(ExpenseReportResource::showsPayment($report))->toBeTrue();
    exrSetting('expense.payment.show_to_employee', false);
    expect(ExpenseReportResource::showsPayment($report))->toBeFalse();
    $this->actingAs($this->user);
    $this->get(PayExpenses::getUrl())->assertForbidden();
});

it('downloads a cover letter only when switched on: the standard sheet, or the tenant\'s expense cover template', function () {
    $receipt = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 250, ['merchant' => 'City Cabs']);
    $report = $this->reports->create($this->employee, 'Cab', [$receipt->id], $this->user);
    $letters = app(ExpenseCoverLetters::class);
    expect(fn () => $letters->download($report, $this->user))->toThrow(ExpenseRuleViolation::class, 'switched off');

    exrSetting('expense.report.cover_letter', true);
    $sheet = $letters->download($report, $this->user);
    expect($sheet['mime'])->toBe('text/html')->and($sheet['content'])->toContain($report->number, $receipt->number, 'City Cabs', 'Employee signature', '250.00');
    [$strangerUser] = exrPerson($this->tenant, $this->company, ['expense.claim']);
    expect(fn () => $letters->download($report, $strangerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot see this report.');

    LetterTemplate::query()->create(['code' => 'EXP_COVER', 'type' => 'expense_cover', 'name' => 'Expense cover', 'format' => 'html', 'status' => 'active', 'subject' => 'Cover {{ expense.report_number }}',
        'body' => '<p>Report {{ expense.report_number }} for {{ employee.name }}: {{ expense.currency }} {{ expense.total_claimed }}</p><p>{{ expense.lines }}</p>']);
    $filled = $letters->download($report, $this->user);
    expect($filled['content'])->toContain('Report '.$report->number, 'INR 250.00', '<table', $receipt->number)->not->toContain('PEOPLEOSEXPENSELINESTABLE');
});

it('tells the people concerned in-app when no notification rule is set up', function () {
    $receipt = exrReceipt($this->employee, $this->user, 'CAB', '2026-05-18', 250);
    $report = $this->reports->submit($this->reports->create($this->employee, 'Cab', [$receipt->id], $this->user), $this->user);
    $this->reports->approvePart($report->parts()->sole(), null, null, $this->managerUser);
    $subjects = NotificationDelivery::query()->pluck('subject')->all();
    expect(collect($subjects)->contains(fn ($s) => str_contains((string) $s, 'Expense report '.$report->number.' (All expenses)')))->toBeTrue()
        ->and(collect($subjects)->contains(fn ($s) => str_contains((string) $s, 'Your expense report '.$report->number.' is approved: 250.00')))->toBeTrue();
});

it('shows employees their own expenses and reports under Me, files expenses from the screen, and lets approvers decide line by line', function () {
    $this->actingAs($this->user);
    expect(ExpenseReceiptResource::getNavigationGroup())->toBe('Me')->and(ExpenseReceiptResource::getNavigationLabel())->toBe('My expenses');
    Livewire::test(ListExpenseReceipts::class)->assertOk()
        ->callAction('addExpense', ['expense_date' => '2026-05-18', 'expense_category_id' => $this->cab->id, 'currency' => 'INR', 'amount' => 321, 'purpose' => 'Airport'])
        ->assertHasNoActionErrors();
    $receipt = ExpenseReceipt::query()->sole();
    expect((float) $receipt->amount)->toBe(321.0)->and($receipt->purpose)->toBe('Airport');
    [$otherUser, $other] = exrPerson($this->tenant, $this->company, ['expense.claim']);
    exrReceipt($other, $otherUser, 'CAB', '2026-05-18', 99);
    Livewire::test(ListExpenseReceipts::class)->assertCanSeeTableRecords([$receipt])->assertCountTableRecords(1);

    Livewire::test(ListExpenseReports::class)->assertOk()->callAction('newReport', ['title' => 'Airport', 'receipts' => [$receipt->id]])->assertHasNoActionErrors();
    $report = ExpenseReport::query()->sole();
    Livewire::test(ViewExpenseReport::class, ['record' => $report->getRouteKey()])->assertOk()->callAction('submit')->assertHasNoActionErrors();
    expect($report->refresh()->status)->toBe('submitted');

    $this->actingAs($this->managerUser);
    expect(ExpenseReportResource::getNavigationGroup())->toBe('Expense & travel');
    $part = $report->parts()->sole();
    Livewire::test(PartsRelationManager::class, ['ownerRecord' => $report, 'pageClass' => ViewExpenseReport::class])->assertOk()
        ->callTableAction('approve', $part, ['lines' => [['id' => $receipt->id, 'amount' => 300, 'note' => 'Toll not covered']], 'note' => 'OK'])->assertHasNoTableActionErrors();
    expect($report->refresh()->status)->toBe('partly_approved')->and((float) $report->net_payable)->toBe(300.0)->and($receipt->refresh()->approver_note)->toBe('Toll not covered');
    $this->get(ExpenseReportResource::getUrl('view', ['record' => $report]))->assertOk()->assertSee($report->number);
});
