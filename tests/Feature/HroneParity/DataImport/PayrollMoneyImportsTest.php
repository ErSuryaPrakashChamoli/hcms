<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Claims\Models\ReimbursementBill;
use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use App\Domain\Claims\Services\ReimbursementBills;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Models\FinalSettlement;
use App\Domain\Identity\Models\User;
use App\Domain\Loans\Models\Loan;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\VariablePayPlans;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Di4Helpers.php';

/* Data import DI-4 (Payroll): loans, variable pay, salary arrears, reimbursement claims and openings, settlements. */

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->asha = di4Employee(600000);
    $this->ravi = di4Employee(900000);
});

function di4LoanPolicy(array $settings): void
{
    $policy = Policy::create(['type' => 'loan', 'name' => 'Loans', 'code' => 'DI4LOANS']);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => 'loan', 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

function di4Reimbursement(string $code, bool $onClaim = true): SalaryComponent
{
    return SalaryComponent::create(['name' => "{$code} reimbursement", 'code' => $code, 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => $onClaim, 'sort_order' => 50, 'status' => 'active']);
}

it('raises loans on the employees\' behalf under their loan policy, approving and marking paid out where the row asks', function () {
    di4LoanPolicy(['enabled' => true, 'max_amount' => 100000, 'max_installments' => 24, 'max_active' => 2, 'interest_rate' => 0, 'disbursement' => 'payroll']);
    $a = $this->asha->employee_code;
    $r = $this->ravi->employee_code;
    $csv = "Employee code,Loan type,Amount,Instalments,Recovery from (YYYY-MM),Interest % a year,Reason,Guarantor codes,Status (requested/approved),Paid out on\n"
        ."{$a},salary_advance,30000,6,2026-11,,Medical emergency,,requested,\n"
        ."{$r},Personal loan,\"60,000\",12,2026-11,,Loan before go-live,{$a},approved,2026-09-30\n"
        ."{$r},boat,1000,2,2026-11,,x,,,\n"
        ."{$a},personal,5000,2,2026-11,9,Interest asked,,requested,\n"
        ."{$a},personal,5000,2,2026-11,9,Interest at approval,,approved,\n"
        ."{$a},personal,5000,2,2026-08,,Too early,,approved,2026-12-01\n";
    $import = di4Stage('loans', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(4)
        ->and($rows[3]->errors[0])->toContain("'boat' is not one of")
        ->and($rows[4]->errors[0])->toContain('set when the loan is approved')
        ->and($rows[5]->errors[0])->toContain('does not let the approver change the interest rate')
        ->and(implode(' ', $rows[6]->errors))->toContain('this month or later')->toContain('today or earlier');

    di4Run($import);
    $advance = Loan::query()->where('employee_id', $this->asha->id)->sole();
    $personal = Loan::query()->where('employee_id', $this->ravi->id)->sole();
    expect($advance->status)->toBe('pending')->and($advance->requested_by)->toBe($this->hr->id)
        ->and($personal->status)->toBe('active')->and($personal->disbursed_on->toDateString())->toBe('2026-09-30')
        ->and($personal->reviewed_by)->toBe($this->hr->id)->and($personal->guarantor_employee_id)->toBe($this->asha->id)
        ->and($personal->schedule()->count())->toBe(12)->and((float) $personal->outstanding)->toBe(60000.0);

    // Rows only add loans: the same open loans are skipped.
    expect(RowImportTypes::TYPES['loans'][0]::intents())->toBeFalse()
        ->and(di4Stage('loans', $csv)->skip_count)->toBe(2);

    // Decision: the person running the upload approves a row asking for it, as on the loan screen — never their own loan.
    $own = di4Employee(700000, ['employee.import', 'payroll.manage']);
    $self = User::query()->find($own->user_id);
    $this->actingAs($self);
    $mine = di4Stage('loans', "Employee code,Loan type,Amount,Instalments,Recovery from (YYYY-MM),Reason,Status (requested/approved)\n"
        ."{$own->employee_code},salary_advance,10000,2,2026-11,Own advance,approved\n{$own->employee_code},personal,10000,2,2026-11,Own loan,requested\n", user: $self);
    expect(di4Rows($mine)[1]->errors[0])->toContain('cannot approve your own loan')->and($mine->create_count)->toBe(1);
});

it('records bills behind monthly reimbursements and, from the bills date, taxes what they pay beyond the bills', function () {
    $phone = di4Reimbursement('PHONE', false);
    $fuel = di4Reimbursement('FUEL');
    approveStructureVersion('STANDARD', '2026-01-01', [$phone->id => 55]);
    $kiran = salariedEmployee(900000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'PHONE' => 2000]);
    $k = $kiran->employee_code;
    $csv = "Employee code,Reimbursement code,Bill date,Bill amount,Bill number,Vendor,Description\n"
        ."{$k},PHONE,2026-10-05,6000,INV-1,Airtel,Mobile bills April to September\n"
        ."{$k},FUEL,2026-10-05,100,F-1,,\n"
        ."{$k},PHONE,2026-12-05,100,F-2,,\n"
        ."{$k},phone,2026-10-05,6000,INV-1,,\n";
    $import = di4Stage('reimbursement_bills', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(3)
        ->and($rows[2]->errors[0])->toContain('not an active reimbursement paid with salary every month')
        ->and($rows[3]->errors[0])->toContain('today or earlier')
        ->and($rows[4]->errors[0])->toContain('row 1');
    di4Run($import);
    $bill = ReimbursementBill::query()->where('employee_id', $kiran->id)->sole();
    expect((float) $bill->amount)->toBe(6000.0)->and($bill->financial_year)->toBe(2026)->and($bill->vendor)->toBe('Airtel')
        ->and(di4Stage('reimbursement_bills', $csv)->skip_count)->toBe(1);

    // No bills date set: tax is as before.
    $october = fn () => app(PayrollCalculator::class)->calculate($kiran->refresh(), PayrollPeriod::for($this->company, 2026, 10))->inputs['tax'];
    $before = $october();
    expect($before)->not->toHaveKey('reimbursement_unbilled_taxable');

    // From the bills date: the year's phone reimbursement (2,000 × 6 months left) beyond the 6,000 billed is taxable.
    di4Setting('payroll.reimbursement.bills_from', '2026-10-01');
    $after = $october();
    expect($after['reimbursement_paid'])->toBe(12000.0)->and($after['reimbursement_bills'])->toBe(6000.0)->and($after['reimbursement_unbilled_taxable'])->toBe(6000.0)
        ->and($after['projected_gross'])->toBe($before['projected_gross'] + 6000.0);

    // A cancelled bill no longer counts; bills are never edited.
    app(ReimbursementBills::class)->cancel($bill, 'Duplicate of a paper bill', $this->hr);
    expect($october()['reimbursement_unbilled_taxable'])->toBe(12000.0)
        ->and(fn () => $bill->refresh()->update(['amount' => 1]))->toThrow(RuntimeException::class, 'never edited');
});

it('raises variable pay requests that someone else decides, and enters a plan month\'s payout', function () {
    $incentive = SalaryComponent::create(['name' => 'Sales incentive', 'code' => 'INCENTIVE', 'type' => 'earning', 'classification' => 'incentive', 'calculation_method' => 'fixed',
        'taxable' => true, 'include_in_ctc' => false, 'include_in_gross' => true, 'is_recurring' => false, 'is_proratable' => false, 'pay_nature' => 'variable', 'sort_order' => 60, 'status' => 'active']);
    $plan = app(VariablePayPlans::class)->create($this->ravi, $incentive, [['month' => '2026-10', 'target' => 10000], ['month' => '2026-11', 'target' => 10000]], 'Quarterly sales', $this->hr);
    $a = $this->asha->employee_code;
    $r = $this->ravi->employee_code;
    $csv = "Employee code,Pay item code,Amount,Pay month,Reason,Variable pay plan\n"
        ."{$a},incentive,15000,2026-10,Q2 incentive,\n"
        ."{$r},INCENTIVE,9500,2026-10,October payout,quarterly sales\n"
        ."{$a},BASIC,1000,2026-10,Not variable,\n"
        ."{$a},INCENTIVE,1000,2026-01,Too old,\n"
        ."{$r},INCENTIVE,1000,2026-12,No such month,Quarterly sales\n";
    $import = di4Stage('variable_pay', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]->errors[0])->toContain('fixed component')
        ->and($rows[4]->errors[0])->toContain('last month or later')
        ->and($rows[5]->errors[0])->toContain('not a month of plan');

    di4Run($import);
    $mine = VariablePayRequest::query()->where('employee_id', $this->asha->id)->sole();
    $payout = VariablePayRequest::query()->where('employee_id', $this->ravi->id)->sole();
    expect($mine->status)->toBe('pending')->and((float) $mine->amount)->toBe(15000.0)->and($mine->pay_month)->toBe('2026-10')->and($mine->requested_by)->toBe($this->hr->id)
        ->and($payout->variable_pay_plan_id)->toBe($plan->id)->and($payout->plan_month)->toBe('2026-10')->and($payout->status)->toBe('pending');

    expect(di4Stage('variable_pay', $csv)->skip_count)->toBe(1); // the same request waiting is skipped; the plan month is already entered
});

it('records salary arrears as payroll adjustments of an open month that the run pays, never in a closed month', function () {
    di4Finalize(9, $this->hr, $this->approver);
    $a = $this->asha->employee_code;
    $r = $this->ravi->employee_code;
    $csv = "Employee code,Pay in month,Arrear for month,Pay item code,Amount,Description,Taxable (yes/no),Note\n"
        ."{$a},2026-10,2026-08,BASIC,4500,,,Revision from August\n"
        ."{$r},2026-10,2026-09,,1200,Shift allowance arrear,no,Missed in September\n"
        ."{$r},2026-09,2026-08,,1000,,,\n"
        ."{$r},2026-10,2026-10,,1000,,,\n"
        ."{$a},2026-10,2026-08,PF_EE,100,,,\n";
    $import = di4Stage('salary_arrears', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(3)
        ->and($rows[3]->errors[0])->toContain('closed')
        ->and($rows[4]->errors[0])->toContain('before the month it is paid in')
        ->and($rows[5]->errors[0])->toContain('statutory items are never arrears');

    di4Run($import);
    $october = PayrollPeriod::for($this->company, 2026, 10);
    $basic = PayrollAdjustment::query()->where('employee_id', $this->asha->id)->sole();
    $adHoc = PayrollAdjustment::query()->where('employee_id', $this->ravi->id)->sole();
    expect($basic->payroll_period_id)->toBe($october->id)->and($basic->type)->toBe('arrear')->and($basic->status)->toBe('approved')
        ->and($basic->component->code)->toBe('BASIC')->and($basic->name)->toBe('Basic arrear Aug 2026')
        ->and($adHoc->taxable)->toBeFalse()->and($adHoc->reference_period_id)->toBe(PayrollPeriod::for($this->company, 2026, 9)->id)
        ->and($adHoc->source_type)->toBe('employee_import');

    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open($this->company, 2026, 10, $this->hr), $this->hr);
    $line = PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id)->where('employee_id', $this->ravi->id))->where('name', 'Shift allowance arrear')->sole();
    expect((float) $line->amount)->toBe(1200.0)->and($line->classification)->toBe('arrear')->and($line->taxable)->toBeFalse();

    // Rows only add arrears: the same arrear is skipped. With approval required, a new arrear waits for someone else.
    expect(di4Stage('salary_arrears', $csv)->skip_count)->toBe(2);
    di4Setting('payroll.adjustments.require_approval', true);
    di4Run(di4Stage('salary_arrears', "Employee code,Pay in month,Arrear for month,Amount\n{$a},2026-10,2026-07,800\n"));
    expect(PayrollAdjustment::query()->where('employee_id', $this->asha->id)->where('amount', 800)->value('status'))->toBe('pending');
});

it('posts reimbursement opening balances once and raises claims against the balance with the claim screen\'s rules', function () {
    $fuel = di4Reimbursement('FUEL');
    di4Reimbursement('PHONE', false);
    $a = $this->asha->employee_code;
    $r = $this->ravi->employee_code;
    $opening = "Employee code,Reimbursement code,Financial year,Opening balance,Note\n"
        ."{$a},FUEL,2026-27,7500,From the old system\n"
        ."{$r},PHONE,2026-27,100,\n"
        ."{$r},FUEL,2026-2027,0,\n";
    $import = di4Stage('reimbursement_opening', $opening);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(2)
        ->and($rows[2]->errors[0])->toContain('not an active reimbursement paid on claim')
        ->and($rows[3]->errors[0])->toContain('above zero');
    di4Run($import);
    $claims = app(ReimbursementClaims::class);
    expect(ReimbursementLedgerEntry::query()->where('employee_id', $this->asha->id)->where('kind', 'opening')->sole()->amount)->toEqual('7500.00')
        ->and($claims->balance($this->asha, $fuel, 2026))->toBe(7500.0)
        ->and(di4Stage('reimbursement_opening', $opening)->skip_count)->toBe(1); // posted once, never changed

    $csv = "Employee code,Reimbursement code,Claim date,Claim amount,Description,Bill number,Bill date,Vendor,Bill amount\n"
        ."{$a},FUEL,2026-10-15,2500,Fuel for October,INV-1,2026-10-14,City Fuels,2500\n"
        ."{$a},FUEL,2026-10-16,6000,More fuel,,,,\n"
        ."{$r},FUEL,2026-10-15,100,Fuel,,,,\n"
        ."{$a},FUEL,2026-10-17,500,Bill does not add up,INV-2,,,400\n";
    // Bills must be attached (the default): payroll holds them, so the row lists its bill instead (decision).
    $import = di4Stage('reimbursement_claims', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(1)->and($import->error_count)->toBe(3)
        ->and($rows[2]->errors[0])->toContain('Give the bill amount')
        ->and($rows[3]->errors[0])->toContain('Give the bill amount')
        ->and($rows[4]->errors[0])->toContain('add up');
    di4Run($import);
    $claim = ReimbursementClaim::query()->where('employee_id', $this->asha->id)->sole();
    expect($claim->status)->toBe('pending')->and($claim->requested_by)->toBe($this->hr->id)->and($claim->bills[0])->toMatchArray(['number' => 'INV-1', 'amount' => 2500])
        ->and($claim->attachments)->toBeNull()
        ->and(AuditEvent::query()->where('entity_type', ReimbursementClaim::class)->where('entity_id', (string) $claim->id)->where('action', AuditAction::Submitted->value)->sole()->metadata['bills_held_by_payroll'])->toBeTrue()
        ->and($claims->balance($this->asha, $fuel, 2026))->toBe(5000.0)
        ->and(di4Stage('reimbursement_claims', $csv)->skip_count)->toBe(1);

    // Without attachments required, the claim screen's balance rules decide (earlier rows of the file count).
    di4Setting('payroll.claims.attachment_required', false);
    $more = di4Stage('reimbursement_claims', "Employee code,Reimbursement code,Claim date,Claim amount,Description\n"
        ."{$a},FUEL,2026-10-18,4000,More fuel\n{$a},FUEL,2026-10-19,1500,Even more fuel\n{$r},FUEL,2026-10-15,100,Fuel\n");
    $rows = di4Rows($more);
    expect($more->create_count)->toBe(1)
        ->and($rows[2]->errors[0])->toContain('Up to 1,000.00')
        ->and($rows[3]->errors[0])->toContain('Up to 0.00');
    // Only payroll, for someone else, raises a claim with the bills held by payroll.
    expect(fn () => $claims->claim($this->asha, $fuel, 10, '2026-10-19', 'x', [['amount' => 10]], [], User::query()->find($this->asha->user_id), billsHeldByPayroll: true))
        ->toThrow(RuntimeException::class, 'Only payroll');
});

it('adds manual lines to the settlement of an exit in progress, replacing a line on update and never touching an approved settlement', function () {
    $case = ExitCase::create(['number' => 'EXIT-DI4-1', 'employee_id' => $this->asha->id, 'type' => 'resignation', 'status' => 'notice', 'initiated_on' => '2026-10-01', 'last_working_day' => '2026-10-31']);
    $a = $this->asha->employee_code;
    $r = $this->ravi->employee_code;
    $csv = "Employee code,Earning or deduction,Line,Amount,Note\n"
        ."{$a},earning,Pending incentive,12000,Q3\n"
        ."{$a},deduction,Laptop damage recovery,\"3,500\",Asset\n"
        ."{$r},earning,Bonus,1000,\n"
        ."{$a},refund,Something,10,\n";
    $import = di4Stage('settlements', $csv);
    $rows = di4Rows($import);
    expect($import->create_count)->toBe(2)->and($import->error_count)->toBe(2)
        ->and($rows[3]->errors[0])->toContain('no exit in progress')
        ->and($rows[4]->errors[0])->toContain('earning or deduction');

    di4Run($import);
    $settlement = FinalSettlement::query()->where('exit_case_id', $case->id)->sole();
    expect($settlement->status)->toBe('draft')->and($settlement->lines()->where('source', 'manual')->count())->toBe(2)
        ->and((float) $settlement->total_earnings)->toBe(12000.0)->and((float) $settlement->net_amount)->toBe(8500.0);

    $change = "Employee code,Earning or deduction,Line,Amount\n{$a},earning,pending incentive,15000\n";
    expect(di4Stage('settlements', $change, ['mode' => 'new'])->skip_count)->toBe(1)
        ->and(di4Run(di4Stage('settlements', $change, ['mode' => 'update']))->update_count)->toBe(1)
        ->and($settlement->lines()->where('source', 'manual')->where('type', 'earning')->sole()->amount)->toEqual('15000.00')
        ->and((float) $settlement->refresh()->net_amount)->toBe(11500.0);

    $settlement->update(['status' => 'approved', 'approved_by' => $this->approver->id, 'approved_at' => now()]);
    expect(di4Rows(di4Stage('settlements', $change))[1]->errors[0])->toContain('never changed');
});
