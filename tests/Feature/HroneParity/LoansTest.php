<?php

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Loans\Models\Loan;
use App\Domain\Loans\Models\LoanInstallment;
use App\Domain\Loans\Services\Loans;
use App\Domain\Loans\Settlement\LoanSettlementSource;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne parity HP-2 (PY-01): loans and salary advances — policy, schedule, payout and recovery through payroll. */

beforeEach(function () {
    $this->travelTo('2026-08-20 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->loans = app(Loans::class);
    $this->runs = app(PayrollRuns::class);
});

function loanPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'loan', 'name' => 'Loans', 'code' => 'LOANS']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'loan', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

function runMonth(int $month): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open(test()->company, 2026, $month, test()->hr), test()->hr);
    $run = $runs->approve($runs->validate($run), test()->approver, 'ok');

    return $runs->finalize($run, test()->approver);
}

it('checks the loan policy and builds equal or reducing-balance schedules', function () {
    loanPolicy(['allowed_types' => ['salary_advance', 'personal'], 'max_amount' => 100000, 'max_installments' => 12, 'max_active' => 2]);
    expect(fn () => $this->loans->request($this->employee, 'vehicle', 50000, 6, 'Car'))->toThrow(RuntimeException::class, 'not offered')
        ->and(fn () => $this->loans->request($this->employee, 'personal', 150000, 6, 'Big'))->toThrow(RuntimeException::class, 'between 1 and')
        ->and(fn () => $this->loans->request($this->employee, 'personal', 50000, 24, 'Long'))->toThrow(RuntimeException::class, '1 to 12');

    $advance = $this->loans->request($this->employee, 'salary_advance', 30000, 3, 'Medical bills', firstDueMonth: '2026-09');
    expect((float) $advance->emi)->toBe(10000.0)->and($advance->status)->toBe('pending');

    $plan = $this->loans->plan(new Loan(['principal' => 100000, 'interest_rate' => 12, 'installments' => 12, 'first_due_month' => '2026-09']));
    expect($plan)->toHaveCount(12)->and($plan[0]['amount'])->toBe(8884.88)->and(round(array_sum(array_column($plan, 'principal_part')), 2))->toBe(100000.0);
});

it('pays out an approved advance in payroll, recovers it month by month and closes it', function () {
    $loan = $this->loans->request($this->employee, 'salary_advance', 20000, 2, 'Rent deposit', firstDueMonth: '2026-09');
    $item = app(ApprovalCenter::class)->pending($this->approver)->first(fn ($i) => $i->type === 'loan');
    expect($item)->not->toBeNull();
    app(ApprovalDecisions::class)->decide($this->approver, $item->id, 'approve', 'Fine');
    expect($loan->refresh()->status)->toBe('approved')->and($loan->schedule)->toHaveCount(2);

    $august = runMonth(8);
    expect((float) PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $august->id))->where('code', 'LOAN_PAYOUT')->value('amount'))->toBe(20000.0)
        ->and($loan->refresh()->status)->toBe('active')->and((float) $loan->outstanding)->toBe(20000.0);

    $this->travelTo('2026-09-25 09:00:00');
    $september = runMonth(9);
    expect((float) PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $september->id))->where('code', 'LOAN_EMI')->value('amount'))->toBe(10000.0)
        ->and((float) $loan->refresh()->outstanding)->toBe(10000.0);

    $this->loans->prepay($loan, 1, 'Paid in cash');
    expect($loan->refresh()->status)->toBe('closed')->and((float) $loan->outstanding)->toBe(0.0);
});

it('defers an instalment to the end of the schedule', function () {
    $loan = $this->loans->request($this->employee, 'personal', 30000, 3, 'Wedding', firstDueMonth: '2026-09');
    $this->loans->approve($loan, null, $this->approver);
    $moved = $this->loans->defer(LoanInstallment::query()->where('loan_id', $loan->id)->where('sequence', 1)->first(), 'Hardship');

    expect($moved->due_month)->toBe('2026-12')->and($moved->sequence)->toBe(4)->and($loan->refresh()->installments)->toBe(4)
        ->and(LoanInstallment::query()->where('loan_id', $loan->id)->where('status', 'due')->count())->toBe(3);
    expect(fn () => $this->loans->approve($this->loans->request($this->employee, 'personal', 1000, 1, 'Second'), null, $this->approver))->toThrow(RuntimeException::class, 'open loan');
});

it('recovers the rest of an active loan in full and final settlement and closes it when paid', function () {
    $loan = $this->loans->request($this->employee, 'personal', 30000, 3, 'Move', firstDueMonth: '2026-09');
    $this->loans->approve($loan, null, $this->approver);
    $this->loans->markDisbursed($loan->refresh(), '2026-08-25', 'Bank');

    $case = new ExitCase(['employee_id' => $this->employee->id, 'last_working_day' => '2026-09-30', 'number' => 'EX-1']);
    $source = app(LoanSettlementSource::class);
    $lines = $source->linesFor($case, new FinalSettlement);

    // September's instalment is in the final salary; October and November are recovered in the settlement.
    expect($lines)->toHaveCount(1)->and($lines[0]['code'])->toBe('LOAN_BALANCE')->and($lines[0]['amount'])->toBe(20000.0);

    $source->settled($case, new FinalSettlement);
    expect($loan->refresh()->status)->toBe('closed');
});
