<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Expense\Exceptions\ExpenseRuleViolation;
use App\Domain\Expense\Models\ExpenseAdvance;
use App\Domain\Expense\Models\ExpenseAdvanceEntry;
use App\Domain\Expense\Services\ExpenseAdvances;
use App\Domain\Expense\Services\ExpenseCategories;
use App\Domain\Expense\Services\ExpenseReceipts;
use App\Domain\Expense\Services\ExpenseReports;
use App\Domain\Expense\Support\ExpenseAdvanceRules;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\DispenseAdvances;
use App\Filament\Resources\ExpenseAdvances\ExpenseAdvanceResource;
use App\Filament\Resources\ExpenseAdvances\Pages\ListExpenseAdvances;
use App\Filament\Resources\ExpenseAdvances\Pages\ViewExpenseAdvance;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/* HROne setup parity EX-D — expense advances: the advance policy enforced on requests, approval and cancellation (with
 * approval), dispensing with the payment reference, settlement against approved reports oldest first, refunds and
 * write-offs on an immutable, idempotent ledger, the Approval Center, workflows and the Dispense advances screen. */

beforeEach(function () {
    $this->travelTo('2026-05-20 10:00:00');
    Storage::fake('local');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = Company::factory()->create();
    [$this->managerUser, $this->manager] = exaPerson($this->tenant, $this->company, ['expense.claim', 'expense.approve']);
    [$this->user, $this->employee] = exaPerson($this->tenant, $this->company, ['expense.claim'], $this->manager);
    [$this->payerUser] = exaPerson($this->tenant, $this->company, ['expense.pay', 'expense.claim']);
    $this->advances = app(ExpenseAdvances::class);
});

/** @return array{0: User, 1: Employee} */
function exaPerson(Tenant $tenant, Company $company, array $permissions, ?Employee $manager = null): array
{
    $user = tenantUser($tenant, $permissions);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => '2025-01-01']);
    EmployeePosition::query()->create(['employee_id' => $employee->id, 'company_id' => $company->id, 'effective_from' => '2025-01-01']);
    if ($manager !== null) {
        ReportingRelationship::query()->create(['employee_id' => $employee->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $employee];
}

/** @param  list<Employee>  $only  named employees (empty = everyone) */
function exaPolicy(string $type, array $settings, array $only = []): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => Str::title($type).' '.uniqid(), 'code' => 'EXA_'.strtoupper(uniqid())]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    $conditions = $only === [] ? [] : [['field' => 'employee_id', 'operator' => 'in', 'value' => array_map(fn (Employee $e) => $e->id, $only)]];
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'Rule', 'conditions' => $conditions, 'priority' => $only === [] ? 1000 : 50]);

    return $policy;
}

function exaApproved(ExpenseAdvances $advances, Employee $employee, User $as, User $approver, float $amount): ExpenseAdvance
{
    return $advances->approve($advances->request($employee, ['amount' => $amount, 'purpose' => 'Trip'], $as), null, null, $approver);
}

it('refuses advance requests without an expense advance policy (HROne\'s message) or when the policy does not offer them', function () {
    expect(fn () => $this->advances->request($this->employee, ['amount' => 1000, 'purpose' => 'Trip'], $this->user))->toThrow(ExpenseRuleViolation::class, ExpenseAdvanceRules::NO_POLICY);
    exaPolicy('expense_advance', ['enabled' => false]);
    expect(fn () => $this->advances->request($this->employee, ['amount' => 1000], $this->user))->toThrow(ExpenseRuleViolation::class, 'does not allow advance requests');
});

it('enforces the largest advance, open advances, the outstanding total, purpose and attachment', function () {
    exaPolicy('expense_advance', ['enabled' => true, 'max_amount' => 5000, 'max_open' => 2, 'max_outstanding_total' => 7000, 'purpose_required' => true, 'attachment_required' => true]);
    $file = ExpenseAdvances::directory().'/itinerary.pdf';
    Storage::disk('local')->put($file, 'itinerary');
    $request = fn (float $amount, array $extra = []) => $this->advances->request($this->employee, $extra + ['amount' => $amount, 'purpose' => 'Client visit', 'attachments' => [$file]], $this->user);

    expect(fn () => $request(6000))->toThrow(ExpenseRuleViolation::class, 'at most 5,000.00 per advance')
        ->and(fn () => $request(1000, ['purpose' => '']))->toThrow(ExpenseRuleViolation::class, 'Give the purpose of the advance.')
        ->and(fn () => $request(1000, ['attachments' => []]))->toThrow(ExpenseRuleViolation::class, 'Attach a supporting document');

    $first = $request(4000);
    expect($first->number)->toStartWith('EXA-2026-')->and($first->status)->toBe('requested')->and((float) $first->amount_base)->toBe(4000.0)
        ->and($first->disbursement_channel)->toBe('bank')->and($first->policy_version_id)->not->toBeNull();
    expect(fn () => $request(3500))->toThrow(ExpenseRuleViolation::class, 'at most 7,000.00 across open advances; 4,000.00 is already open');
    $request(3000);
    expect(fn () => $request(10))->toThrow(ExpenseRuleViolation::class, 'allows 2 open advance(s) at a time');
});

it('approves for the amount asked or less, rejects with a reason, never one\'s own, from the screen or the Approval Center', function () {
    exaPolicy('expense_advance', ['enabled' => true, 'max_open' => 2]);
    $advance = $this->advances->request($this->employee, ['amount' => 3000, 'purpose' => 'Trip'], $this->user);
    expect(fn () => $this->advances->approve($advance, null, null, $this->user))->toThrow(ExpenseRuleViolation::class, 'You cannot decide or pay your own advance.')
        ->and(fn () => $this->advances->approve($advance, 3500, null, $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'Approve between 0.01 and the amount asked for');

    $item = collect(app(ApprovalCenter::class)->pending($this->managerUser))->firstWhere('type', 'expense_advance');
    expect($item)->not->toBeNull()->and($item->title)->toContain($advance->number)
        ->and(collect(app(ApprovalCenter::class)->pending($this->user))->where('type', 'expense_advance'))->toHaveCount(0);
    expect(app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'approve'))->toBe('Advance approved')
        ->and($advance->refresh()->status)->toBe('approved')->and((float) $advance->approved_amount)->toBe(3000.0);

    $second = $this->advances->request($this->employee, ['amount' => 500, 'purpose' => 'Visa'], $this->user);
    expect(fn () => $this->advances->reject($second, ' ', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'Give the reason for rejecting.');
    $this->advances->reject($second, 'Not travelling', $this->managerUser);
    expect($second->refresh()->status)->toBe('rejected');
});

it('cancels a request at once and an approved advance after approval when the policy says so; a dispensed one never', function () {
    exaPolicy('expense_advance', ['enabled' => true, 'max_open' => 5]);
    $request = $this->advances->request($this->employee, ['amount' => 100, 'purpose' => 'A'], $this->user);
    expect($this->advances->cancel($request, 'Plans changed', $this->user)->status)->toBe('cancelled');

    $approved = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 200);
    expect($this->advances->cancel($approved, 'Trip off', $this->user)->status)->toBe('cancel_requested');
    $item = collect(app(ApprovalCenter::class)->pending($this->managerUser))->firstWhere('type', 'expense_advance_cancellation');
    expect($item->decisionLabels)->toBe(['approve' => 'Approve cancellation', 'reject' => 'Keep advance']);
    app(ApprovalDecisions::class)->decide($this->managerUser, $item->id, 'reject', 'Trip is on');
    expect($approved->refresh()->status)->toBe('approved');
    $this->advances->cancel($approved, 'Trip off again', $this->user);
    $this->advances->approveCancellation($approved->refresh(), null, $this->managerUser);
    expect($approved->refresh()->status)->toBe('cancelled');

    // An approver cancels at once; a policy without the approval step lets the employee cancel at once.
    $byHr = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 300);
    expect($this->advances->cancel($byHr, 'Duplicate', $this->managerUser)->status)->toBe('cancelled');
    exaPolicy('expense_advance', ['enabled' => true, 'max_open' => 5, 'cancellation_needs_approval' => false], [$this->employee]);
    $free = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 300);
    expect($this->advances->cancel($free, 'Not needed', $this->user)->status)->toBe('cancelled');

    $paid = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 300);
    $this->advances->dispense($paid, 'UTR-1', '2026-05-20', $this->payerUser);
    expect(fn () => $this->advances->cancel($paid->refresh(), 'x', $this->user))->toThrow(ExpenseRuleViolation::class, 'A dispensed advance cannot be cancelled');
});

it('dispenses an approved advance with its payment reference, posting the ledger and the settle-by date; never one\'s own', function () {
    exaPolicy('expense_advance', ['enabled' => true, 'settle_within_days' => 10]);
    $advance = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 2500);
    expect(fn () => $this->advances->dispense($advance, 'UTR-9', '2026-05-20', $this->managerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot act on this advance.')
        ->and(fn () => $this->advances->dispense($advance, '', '2026-05-20', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'Give the payment reference')
        ->and(fn () => $this->advances->dispense($advance, 'UTR-9', '2026-05-21', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'on or before today')
        ->and(fn () => $this->advances->dispense($advance, 'UTR-9', '2026-05-19', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'An advance is dispensed on or after its approval.');

    $this->actingAs($this->payerUser);
    Livewire::test(DispenseAdvances::class)->assertOk()->assertCanSeeTableRecords([$advance])
        ->callTableAction('dispense', $advance, ['reference' => 'UTR-9', 'on' => '2026-05-20'])->assertHasNoTableActionErrors();
    $advance->refresh();
    expect($advance->status)->toBe('disbursed')->and((float) $advance->outstanding)->toBe(2500.0)->and($advance->disbursement_reference)->toBe('UTR-9')
        ->and($advance->settle_by->toDateString())->toBe('2026-05-30')->and($advance->disbursed_by)->toBe($this->payerUser->id)
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->pluck('kind')->all())->toBe(['disbursed']);

    $own = exaApproved($this->advances, Employee::query()->where('user_id', $this->payerUser->id)->sole(), $this->payerUser, $this->managerUser, 100);
    expect(fn () => $this->advances->dispense($own, 'UTR-10', '2026-05-20', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'You cannot decide or pay your own advance.');
});

it('settles dispensed advances against approved reports oldest first, and the report\'s net payable is what is left', function () {
    exaPolicy('expense_advance', ['enabled' => true, 'max_open' => 3]);
    // The later-requested advance is dispensed first: settlement follows the payment date, not the request.
    $this->travelTo('2026-05-08 10:00:00');
    $newer = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 2000);
    $older = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 1000);
    $this->travelTo('2026-05-10 10:00:00');
    $this->advances->dispense($older, 'UTR-1', '2026-05-10', $this->payerUser);
    $this->travelTo('2026-05-12 10:00:00');
    $this->advances->dispense($newer, 'UTR-2', '2026-05-12', $this->payerUser);
    $this->travelTo('2026-05-20 10:00:00');

    $cab = app(ExpenseCategories::class)->save(null, ['type' => 'amount', 'name' => 'Cab', 'code' => 'CAB']);
    exaPolicy('expense', ['categories' => [['category' => $cab->id]]]);
    $receipt = fn (float $amount) => app(ExpenseReceipts::class)->capture($this->employee, ['expense_category_id' => $cab->id, 'expense_date' => '2026-05-18', 'amount' => $amount], $this->user);
    $reports = app(ExpenseReports::class);

    $first = $reports->submit($reports->create($this->employee, 'One', [$receipt(1500)->id], $this->user), $this->user);
    $reports->approvePart($first->parts()->sole(), null, null, $this->managerUser);
    expect($first->refresh())->advance_adjusted->toEqual('1500.00')->and((float) $first->net_payable)->toBe(0.0)->and($first->isPayable())->toBeFalse()
        ->and($older->refresh()->status)->toBe('settled')->and((float) $older->outstanding)->toBe(0.0)
        ->and((float) $newer->refresh()->outstanding)->toBe(1500.0)
        ->and(ExpenseAdvanceEntry::query()->where('expense_report_id', $first->id)->pluck('amount', 'expense_advance_id')->map(fn ($v) => (float) $v)->all())->toBe([$older->id => -1000.0, $newer->id => -500.0]);

    $second = $reports->submit($reports->create($this->employee, 'Two', [$receipt(2000)->id], $this->user), $this->user);
    $reports->approvePart($second->parts()->sole(), [$second->receipts()->sole()->id => 1800], null, $this->managerUser);
    expect((float) $second->refresh()->advance_adjusted)->toBe(1500.0)->and((float) $second->net_payable)->toBe(300.0)->and($newer->refresh()->status)->toBe('settled');
});

it('records refunds and write-offs on a ledger that posts each entry once and never changes', function () {
    exaPolicy('expense_advance', ['enabled' => true]);
    $advance = exaApproved($this->advances, $this->employee, $this->user, $this->managerUser, 1000);
    $this->advances->dispense($advance, 'UTR-1', '2026-05-20', $this->payerUser);

    expect(fn () => $this->advances->refund($advance, 1200, 'CASH-1', '2026-05-20', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'between 0.01 and what is outstanding (1,000.00)');
    $this->advances->refund($advance, 400, 'CASH-1', '2026-05-20', $this->payerUser);
    expect(fn () => $this->advances->refund($advance->refresh(), 100, 'cash-1', '2026-05-20', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'A refund with the reference cash-1 is already recorded.')
        ->and((float) $advance->refresh()->outstanding)->toBe(600.0);

    expect(fn () => $this->advances->writeOff($advance, '', $this->payerUser))->toThrow(ExpenseRuleViolation::class, 'Give the reason for writing off.');
    $this->advances->writeOff($advance, 'Employee left on deputation', $this->payerUser);
    expect($advance->refresh()->status)->toBe('written_off')->and((float) $advance->outstanding)->toBe(0.0)
        ->and(ExpenseAdvanceEntry::query()->where('expense_advance_id', $advance->id)->orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all())->toBe([1000.0, -400.0, -600.0])
        ->and(fn () => ExpenseAdvanceEntry::query()->first()->update(['amount' => 1]))->toThrow(RuntimeException::class, 'never changed');
});

it('decides advances and their cancellations through workflows on expense.advance_requested and expense.advance_cancel_requested', function () {
    exaPolicy('expense_advance', ['enabled' => true]);
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Manager', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->managerUser->id]]]]);
    publishWorkflow($nodes, $edges, ['name' => 'Advance approval', 'key' => 'advance_approval', 'trigger_event' => 'expense.advance_requested']);
    publishWorkflow($nodes, $edges, ['name' => 'Advance cancellation', 'key' => 'advance_cancellation', 'trigger_event' => 'expense.advance_cancel_requested']);

    $advance = $this->advances->request($this->employee, ['amount' => 900, 'purpose' => 'Trip'], $this->user);
    expect(collect(app(ApprovalCenter::class)->pending($this->managerUser))->where('type', 'expense_advance'))->toHaveCount(0);
    $task = fn () => WorkflowTask::query()->whereIn('workflow_instance_id', WorkflowInstance::query()->where('subject_id', $advance->id)->where('subject_type', $advance->getMorphClass())->pluck('id'))->where('status', 'pending')->sole();
    app(WorkflowEngine::class)->completeTask($task(), 'approved', 'OK', $this->managerUser);
    expect($advance->refresh()->status)->toBe('approved')->and($advance->decided_by)->toBe($this->managerUser->id);

    $this->advances->cancel($advance, 'Trip off', $this->user);
    app(WorkflowEngine::class)->completeTask($task(), 'approved', 'Fine', $this->managerUser);
    expect($advance->refresh()->status)->toBe('cancelled');
});

it('lets employees request and follow their own advances under Me, and payers see the advance with its ledger', function () {
    exaPolicy('expense_advance', ['enabled' => true, 'max_amount' => 5000]);
    $this->actingAs($this->user);
    expect(ExpenseAdvanceResource::getNavigationGroup())->toBe('Me')->and(ExpenseAdvanceResource::getNavigationLabel())->toBe('My expense advances');
    Livewire::test(ListExpenseAdvances::class)->assertOk()->callAction('requestAdvance', ['currency' => 'INR', 'amount' => 1200, 'purpose' => 'Conference'])->assertHasNoActionErrors();
    $advance = ExpenseAdvance::query()->sole();
    expect((float) $advance->amount_base)->toBe(1200.0)->and($advance->requested_by)->toBe($this->user->id);

    $this->advances->approve($advance, null, null, $this->managerUser);
    $this->advances->dispense($advance->refresh(), 'UTR-5', '2026-05-20', $this->payerUser);
    $this->actingAs($this->payerUser);
    Livewire::test(ViewExpenseAdvance::class, ['record' => $advance->getRouteKey()])->assertOk()->assertSee('UTR-5');
    $this->get(ExpenseAdvanceResource::getUrl('view', ['record' => $advance]))->assertOk();
    $this->actingAs($this->managerUser);
    $this->get(DispenseAdvances::getUrl())->assertForbidden();
});
