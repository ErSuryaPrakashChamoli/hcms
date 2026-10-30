<?php

use App\Domain\Claims\Models\ReimbursementLedgerEntry;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollRuns;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/* HROne parity HP-2 (PY-02): pay-on-claim reimbursements — monthly accrual, balance-checked claims, payout and ledger. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'compensation.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->hr);
    $this->company = payrollCompany();
    $this->fuel = SalaryComponent::create(['name' => 'Fuel reimbursement', 'code' => 'FUEL', 'type' => 'reimbursement', 'classification' => 'reimbursement', 'calculation_method' => 'fixed',
        'taxable' => false, 'include_in_ctc' => true, 'include_in_gross' => false, 'is_recurring' => true, 'is_proratable' => false, 'pay_on_claim' => true, 'sort_order' => 50, 'status' => 'active']);
    approveStructureVersion('STANDARD', '2026-01-01', [$this->fuel->id => 50]);
    $this->employee = salariedEmployee(600000, from: '2026-01-01', componentValues: ['CONV' => 1600, 'FUEL' => 2000]);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $this->runs = app(PayrollRuns::class);
    $this->claims = app(ReimbursementClaims::class);
});

function closeMonth(int $month): PayrollRun
{
    $runs = app(PayrollRuns::class);
    $run = $runs->calculate($runs->open(test()->company, 2026, $month, test()->hr), test()->hr);

    return $runs->finalize($runs->approve($runs->validate($run), test()->approver, 'ok'), test()->approver);
}

it('accrues a pay-on-claim reimbursement instead of paying it, and pays approved claims against the balance', function () {
    $september = closeMonth(9);
    expect(PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $september->id))->where('code', 'FUEL')->exists())->toBeFalse()
        ->and($september->entries()->first()->inputs['claim_accruals'][$this->fuel->id])->toBe(2000)
        ->and((float) ReimbursementLedgerEntry::query()->where('kind', 'accrual')->sum('amount'))->toBe(2000.0);

    expect(fn () => $this->claims->claim($this->employee, $this->fuel, 1500, '2026-09-25', 'Fuel', [], []))->toThrow(RuntimeException::class, 'Attach the bills');
    $claim = $this->claims->claim($this->employee, $this->fuel, 1500, '2026-09-25', 'Fuel for client visits', [['number' => 'B1', 'amount' => 1500]], ['claims/b1.pdf']);
    expect(fn () => $this->claims->claim($this->employee, $this->fuel, 600, '2026-09-26', 'More', [], ['claims/b2.pdf']))->toThrow(RuntimeException::class, 'up to 500.00');

    $this->claims->approve($claim, 1200, 'One bill is personal', $this->approver);
    $this->travelTo('2026-10-25 09:00:00');
    $october = closeMonth(10);
    $line = PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $october->id))->where('code', 'CLAIM_FUEL')->first();

    expect((float) $line->amount)->toBe(1200.0)->and($line->type)->toBe('reimbursement')->and($line->taxable)->toBeFalse()
        ->and($claim->refresh()->status)->toBe('paid')
        ->and($this->claims->balance($this->employee, $this->fuel, 2026))->toBe(2800.0); // 2000 + 2000 accrued − 1200 paid
});
