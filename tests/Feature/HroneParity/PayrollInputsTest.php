<?php

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\SalaryHolds;
use App\Domain\Payroll\Services\VariablePay;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/* HROne parity HP-2: payroll input sources — encashments and attendance arrears paid through payroll and settled on finalize. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'leave.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->employee = salariedEmployee(600000);
    EmployeeBankAccount::create(['employee_id' => $this->employee->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
});

function hpLine(int $runId, string $code): ?PayrollEntryLine
{
    return PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $runId))->where('code', $code)->first();
}

it('pays approved leave encashment and attendance arrears in the run and settles them on finalize', function () {
    leavePolicy(['EL' => ['days' => 12, 'encashment_allowed' => true, 'max_encash_days' => 10]]);
    $el = LeaveType::query()->where('code', 'EL')->first();
    $el->update(['is_encashable' => true]);
    app(LeaveAdjustments::class)->opening($this->employee, $el, 12, 2026, 'Go-live');
    $encashment = app(LeaveAdjustments::class)->requestEncashment($this->employee, $el, 3, 'Year end');
    app(LeaveAdjustments::class)->approveEncashment($encashment);
    $arrear = AttendanceArrear::create(['employee_id' => $this->employee->id, 'date' => '2026-08-14', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1]);

    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);
    $basic = PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id))->where('classification', 'basic')->first();
    $encash = hpLine($run->id, 'LEAVE_ENC');
    $arrearLine = hpLine($run->id, 'ATT_ARREAR');

    expect($encash)->not->toBeNull()
        ->and((float) $encash->amount)->toBe(round(3 * round((float) $basic->basis['full_month'] / 30, 2), 2))
        ->and($encash->taxable)->toBeTrue()
        ->and($encash->basis['source'])->toEqual(['key' => 'leave_encashment', 'ids' => [$encashment->id]])
        ->and($arrearLine)->not->toBeNull()->and((float) $arrearLine->amount)->toBeGreaterThan(0.0);

    $run = $this->runs->approve($this->runs->validate($run), $this->approver, 'ok');
    $this->runs->finalize($run, $this->approver);

    expect($encashment->refresh()->status)->toBe('paid')
        ->and($arrear->refresh()->status)->toBe('settled')->and($arrear->settled_period_id)->toBe($run->payroll_period_id);
});

it('adds nothing when there is nothing to pay', function () {
    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);
    expect(hpLine($run->id, 'LEAVE_ENC'))->toBeNull()->and(hpLine($run->id, 'ATT_ARREAR'))->toBeNull()
        ->and($run->entries()->first()->inputs['input_sources'] ?? null)->toBeNull();
});

it('keeps a held salary out of the bank file until it is released', function () {
    $hold = app(SalaryHolds::class)->hold($this->employee, '2026-09', 'Documents pending');
    expect(fn () => app(SalaryHolds::class)->hold($this->employee, '2026-09', 'Again'))->toThrow(RuntimeException::class, 'already on hold');

    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);
    expect(app(BankFile::class)->rows($run))->toBe([]);

    app(SalaryHolds::class)->release($hold, 'Documents received');
    expect(app(BankFile::class)->rows($run))->toHaveCount(1);
});

it('pays approved variable pay in its month and marks it paid', function () {
    $component = SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->first();
    $request = app(VariablePay::class)->request($this->employee, $component, 5000, '2026-09', 'Quarterly incentive');
    expect(fn () => app(VariablePay::class)->approve($request, null, null))->not->toThrow(RuntimeException::class);

    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->preparer), $this->preparer);
    $line = PayrollEntryLine::query()->whereHas('entry', fn ($q) => $q->where('payroll_run_id', $run->id))->where('basis->source->key', 'variable_pay')->first();
    expect($line)->not->toBeNull()->and((float) $line->amount)->toBe(5000.0);

    $this->runs->finalize($this->runs->approve($this->runs->validate($run), $this->approver, 'ok'), $this->approver);
    expect($request->refresh()->status)->toBe('paid');
});
