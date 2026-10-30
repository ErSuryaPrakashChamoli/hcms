<?php

use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanGuarantor;
use App\Domain\Loans\Services\Loans;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\VariablePayPlans;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\MyCompensation;
use App\Filament\Resources\VariablePayPlans\VariablePayPlanResource;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne setup parity BP-9: loan policy completion, claim rules and reminders, variable pay plans and their schedule on My compensation. */

beforeEach(function () {
    $this->travelTo('2026-09-05 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'compensation.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000, ['payroll.request', 'compensation.self'], '2026-01-01');
    $this->loans = app(Loans::class);
    $this->settings = app(SettingsRepository::class);
});

/** A published loan policy for everyone. */
function lcvLoanPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'loan', 'name' => 'Loans', 'code' => 'LOANS_BP9']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'loan', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('refuses requests outside the request days, beyond the yearly count or above the share of pay', function () {
    lcvLoanPolicy(['request_window' => true, 'request_day_from' => 1, 'request_day_to' => 10, 'max_requests_per_year' => 1, 'max_active' => 5, 'max_emi_percent_of_net' => 10]);

    $this->travelTo('2026-08-20 09:00:00');
    expect(fn () => $this->loans->request($this->employee, 'personal', 10000, 5, 'Repairs'))->toThrow(RuntimeException::class, 'from day 1 to day 10 of the month');

    $this->travelTo('2026-09-05 09:00:00');
    // Monthly CTC is 50,000 before any salary is paid: 10% is 5,000 a month.
    expect(fn () => $this->loans->request($this->employee, 'personal', 30000, 3, 'Too steep'))->toThrow(RuntimeException::class, 'above 10% of your pay (5,000.00)');
    $first = $this->loans->request($this->employee, 'personal', 10000, 5, 'Repairs');
    expect($first->exceptions)->toBeNull()
        ->and(fn () => $this->loans->request($this->employee, 'personal', 5000, 5, 'Again'))->toThrow(RuntimeException::class, 'You can ask for 1 loan(s) a financial year; you have asked for 1.');

    $this->loans->cancel($first, 'Not needed');
    expect($this->loans->request($this->employee, 'personal', 5000, 5, 'Again')->status)->toBe('pending');
});

it('lets a request beyond the limits go to the approver flagged when the policy allows exceptions', function () {
    lcvLoanPolicy(['max_amount' => 50000, 'max_installments' => 6, 'allow_exception' => true, 'max_active' => 3, 'max_emi_percent_of_net' => 10]);

    $loan = $this->loans->request($this->employee, 'personal', 60000, 12, 'Wedding');
    expect($loan->status)->toBe('pending')->and(array_column($loan->exceptions, 'limit'))->toBe(['max_amount', 'max_installments'])
        ->and($loan->exceptionSummary())->toContain('between 1 and 50,000.00');

    $steep = $this->loans->request($this->employee, 'personal', 30000, 3, 'Medical');
    expect(array_column($steep->exceptions, 'limit'))->toBe(['max_emi']);

    $item = collect(app(ApprovalCenter::class)->pending($this->approver))->first(fn ($i) => $i->id === 'loan:'.$loan->id);
    expect($item->risk)->toBe('high')->and($item->riskReason)->toStartWith('Beyond the loan policy:');
});

it('lets the approver change a loan only as the policy allows, re-plans it and keeps what was asked', function () {
    lcvLoanPolicy(['max_amount' => 100000, 'max_installments' => 12, 'max_active' => 3]);
    $loan = $this->loans->request($this->employee, 'personal', 30000, 3, 'Course fee', firstDueMonth: '2026-09');
    expect(fn () => $this->loans->approve($loan, null, $this->approver, ['principal' => 24000]))->toThrow(RuntimeException::class, 'does not let the approver change the amount')
        ->and(fn () => $this->loans->approve($loan, null, $this->approver, ['interest_rate' => 12]))->toThrow(RuntimeException::class, 'does not let the approver change the interest rate');
    // Unchanged terms are not an edit.
    expect($this->loans->approve($loan, 'As asked', $this->approver, ['principal' => 30000, 'installments' => 3])->requested_terms)->toBeNull();
});

it('re-plans a loan the approver edits within the policy', function () {
    lcvLoanPolicy(['max_amount' => 100000, 'max_installments' => 12, 'max_active' => 3, 'approver_may_edit' => true, 'interest_editable' => true]);
    $loan = $this->loans->request($this->employee, 'personal', 30000, 3, 'Course fee', firstDueMonth: '2026-09');
    expect(fn () => $this->loans->approve($loan, null, $this->approver, ['installments' => 24]))->toThrow(RuntimeException::class, 'Approve 1 to 12 instalment(s)');

    $approved = $this->loans->approve($loan, 'Smaller, longer', $this->approver, ['principal' => 24000, 'installments' => 4, 'first_due_month' => '2026-10', 'interest_rate' => 12]);
    expect($approved->status)->toBe('approved')->and((float) $approved->principal)->toBe(24000.0)->and($approved->installments)->toBe(4)
        ->and((float) $approved->interest_rate)->toBe(12.0)->and($approved->schedule()->count())->toBe(4)->and($approved->schedule()->first()->due_month)->toBe('2026-10')
        ->and($approved->requested_terms)->toEqual(['principal' => 30000, 'installments' => 3, 'first_due_month' => '2026-09', 'interest_rate' => 0])
        ->and((float) $approved->emi)->toBe($this->loans->plan($approved)[0]['amount']);
});

it('asks for the policy\'s number of guarantors, limits guarantees per guarantor and waits for each to accept', function () {
    lcvLoanPolicy(['guarantor_required' => true, 'guarantors_required' => 2, 'max_guarantees_per_guarantor' => 1, 'guarantor_must_accept' => true, 'max_active' => 3]);
    [$g1, $g2, $g3] = [employeeWithUser(null, ['task.view']), employeeWithUser(null, ['task.view']), employeeWithUser(null, ['task.view'])];

    expect(fn () => $this->loans->request($this->employee, 'personal', 10000, 5, 'Rent', guarantor: $g1))->toThrow(RuntimeException::class, 'Name 2 colleagues as guarantors.');
    $loan = $this->loans->request($this->employee, 'personal', 10000, 5, 'Rent', guarantor: $g1, guarantors: [$g2]);
    expect($loan->guarantor_employee_id)->toBe($g1->id)->and($loan->guarantors()->pluck('status')->all())->toBe(['pending', 'pending'])
        ->and($g1->user->notifications()->count())->toBe(1);

    $other = salariedEmployee(500000, ['payroll.request'], '2026-01-01');
    expect(fn () => $this->loans->request($other, 'personal', 5000, 5, 'Bike', guarantor: $g1, guarantors: [$g3]))->toThrow(RuntimeException::class, 'already stands guarantor for 1 open loan(s)');

    expect(fn () => $this->loans->approve($loan, null, $this->approver))->toThrow(RuntimeException::class, 'Every guarantor must accept first');
    $guarantee = LoanGuarantor::query()->where('loan_id', $loan->id)->where('employee_id', $g1->id)->firstOrFail();
    expect(fn () => $this->loans->decideGuarantee($guarantee, true, null, $this->hr))->toThrow(RuntimeException::class, 'Only the guarantor');

    $item = collect(app(ApprovalCenter::class)->pending($g1->user))->first(fn ($i) => $i->type === 'loan_guarantee');
    expect($item)->not->toBeNull()->and($item->decisionLabels)->toBe(['approve' => 'Accept', 'reject' => 'Decline']);
    app(ApprovalDecisions::class)->decide($g1->user, $item->id, 'approve', 'Happy to');
    $this->loans->decideGuarantee(LoanGuarantor::query()->where('loan_id', $loan->id)->where('employee_id', $g2->id)->firstOrFail(), true, null, $g2->user);

    expect($this->loans->approve($loan, null, $this->approver)->status)->toBe('approved');
});

it('keeps one open claim per reimbursement and never claims more than the bills, when switched on', function () {
    $fuel = SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    $claims = app(ReimbursementClaims::class);
    $claims->opening($this->employee, $fuel, 2026, 10000, 'Go-live balance');

    // Defaults: claims stack up, bills are optional (and must add up when listed).
    $claims->claim($this->employee, $fuel, 1000, '2026-09-01', 'Fuel', [], ['b/1.pdf']);
    $claims->claim($this->employee, $fuel, 1000, '2026-09-02', 'Fuel', [], ['b/2.pdf']);

    $this->settings->set('payroll.claims.one_open_per_component', true);
    expect(fn () => $claims->claim($this->employee, $fuel, 500, '2026-09-03', 'Fuel', [], ['b/3.pdf']))->toThrow(RuntimeException::class, 'A claim for Fuel reimbursement is still open');

    $this->settings->set('payroll.claims.one_open_per_component', false);
    $this->settings->set('payroll.claims.not_above_bills', true);
    expect(fn () => $claims->claim($this->employee, $fuel, 500, '2026-09-03', 'Fuel', [], ['b/3.pdf']))->toThrow(RuntimeException::class, 'List the bills')
        ->and(fn () => $claims->claim($this->employee, $fuel, 1100, '2026-09-03', 'Fuel', [['number' => 'B3', 'amount' => 1000]], ['b/3.pdf']))->toThrow(RuntimeException::class, 'cannot be more than its bills (1,000.00)');
    expect((float) $claims->claim($this->employee, $fuel, 900, '2026-09-03', 'Fuel, part of the bill', [['number' => 'B3', 'amount' => 1000]], ['b/3.pdf'])->amount)->toBe(900.0);
});

it('reminds employees with a balance to claim in the reminder months, once a month', function () {
    $fuel = SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    app(ReimbursementClaims::class)->opening($this->employee, $fuel, 2026, 3000, 'Go-live balance');
    $user = $this->employee->user;
    $run = fn (string $date) => $this->artisan('peopleos:payroll:claim-reminders', ['--date' => $date, '--tenant' => $this->tenant->slug])->assertSuccessful();

    $run('2026-09-05');
    expect($user->notifications()->count())->toBe(0); // off by default

    $this->settings->set('payroll.claims.reminder_task', 'quarter_end');
    $run('2026-08-05'); // August is not the last month of a quarter (April–March year)
    expect($user->notifications()->count())->toBe(0);
    $run('2026-09-05');
    $run('2026-09-06');
    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'] ?? $user->notifications()->first()->data['subject'] ?? null)->toBe('Reimbursement to claim');
});

it('plans variable pay, asks the manager on the task day and turns the payout into a variable pay request', function () {
    $manager = employeeWithUser(null, ['payroll.request']);
    $employee = salariedEmployee(600000, ['payroll.request', 'compensation.self'], '2026-01-01', ['CONV' => 1600], $manager);
    $incentive = SalaryComponent::query()->where('code', 'BONUS')->firstOrFail();
    $plans = app(VariablePayPlans::class);

    expect(fn () => $plans->create($employee, $incentive, [['month' => '2026-09', 'target' => 10000]], 'Sales incentive', $manager->user))->toThrow(RuntimeException::class, 'kept by payroll');
    $plan = $plans->create($employee, $incentive, [['month' => '2026-12', 'target' => 15000], ['month' => '2026-09', 'target' => 10000]], 'Sales incentive', $this->hr);
    expect(array_keys($plan->targets()))->toBe(['2026-09', '2026-12']);

    $this->settings->set('payroll.variable.task_day', 5);
    $this->artisan('peopleos:payroll:variable-pay-tasks', ['--date' => '2026-09-04', '--tenant' => $this->tenant->slug])->assertSuccessful();
    expect($manager->user->notifications()->count())->toBe(0);
    $this->artisan('peopleos:payroll:variable-pay-tasks', ['--date' => '2026-09-05', '--tenant' => $this->tenant->slug])->assertSuccessful();
    $this->artisan('peopleos:payroll:variable-pay-tasks', ['--date' => '2026-09-05', '--tenant' => $this->tenant->slug])->assertSuccessful();
    expect($manager->user->notifications()->count())->toBe(1);

    expect(fn () => $plans->enterPayout($plan, '2026-09', 9000, 'Q2 sales', $employee->user))->toThrow(RuntimeException::class, 'Only the employee\'s manager or payroll');
    $request = $plans->enterPayout($plan, '2026-09', 9000, 'Q2 sales', $manager->user);
    expect($request->status)->toBe('pending')->and($request->variable_pay_plan_id)->toBe($plan->id)->and($request->plan_month)->toBe('2026-09')
        ->and(fn () => $plans->enterPayout($plan, '2026-09', 500, 'Again', $manager->user))->toThrow(RuntimeException::class, 'already been entered')
        ->and(fn () => $plans->enterPayout($plan, '2026-10', 500, 'Not planned', $manager->user))->toThrow(RuntimeException::class, '2026-10 is not a month of this plan');
    expect($plans->due('2026-09'))->toHaveCount(0)->and($plans->due('2026-12'))->toHaveCount(1);

    // The manager sees the plan; a colleague does not; the employee only when My compensation shows the schedule.
    $this->actingAs($manager->user);
    expect(VariablePayPlanResource::getEloquentQuery()->pluck('id')->all())->toBe([$plan->id]);
    $this->actingAs(tenantUser($this->tenant, ['payroll.request']));
    expect(VariablePayPlanResource::getEloquentQuery()->count())->toBe(0);

    $this->actingAs($employee->user);
    expect(VariablePayPlanResource::getEloquentQuery()->count())->toBe(0);
    Livewire::test(MyCompensation::class)->assertOk()->assertDontSee('Variable pay schedule');
    $this->settings->set('compensation.self_service.show_variable_plan', true);
    expect(VariablePayPlanResource::getEloquentQuery()->count())->toBe(1);
    Livewire::test(MyCompensation::class)->assertOk()->assertSee('Variable pay schedule')->assertSee('9,000.00')->assertSee('15,000.00');
    expect(VariablePayRequest::query()->withoutGlobalScopes()->where('variable_pay_plan_id', $plan->id)->count())->toBe(1);
});
