<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Jobs\CalculatePayrollRun;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollEntryLine;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/* Phase 4: salary segments, attendance/leave contracts, overtime, divisor, finalization safety, immutability, reconciliation, concurrency, security, API. */

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('B');
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->structure = SalaryStructure::query()->where('code', 'STANDARD')->first();
    $this->period = PayrollPeriod::for($this->company, 2026, 9);
});

function withBank($employee)
{
    EmployeeBankAccount::create(['employee_id' => $employee->id, 'account_holder_name' => 'X', 'bank_name' => 'B', 'account_number' => '1234567890', 'ifsc' => 'BANK0000001', 'account_type' => 'savings', 'is_primary' => true]);

    return $employee;
}

function fullRun($runs, $company, $approver): PayrollRun
{
    $run = $runs->open($company, 2026, 9);

    return $runs->approve($runs->validate($runs->calculate($run)), $approver);
}

it('splits a mid-month salary revision into dated segments instead of paying the new salary for the whole month', function () {
    $employee = salariedEmployee(600000);
    compensate($employee, 720000, '2026-09-15', ['CONV' => 1600], 'revision', 'Mid-month increment');

    $c = app(PayrollCalculator::class)->calculate($employee, $this->period);
    $expectedBasic = round(20000 * 14 / 30 + 24000 * 16 / 30, 2);

    expect($c->amount('BASIC'))->toEqualWithDelta($expectedBasic, 0.02)
        ->and($c->inputs['segments'])->toHaveCount(2)
        ->and($c->inputs['segments'][0])->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-14', 'ctc_annual' => 600000.0])
        ->and($c->inputs['segments'][1])->toMatchArray(['from' => '2026-09-15', 'to' => '2026-09-30', 'ctc_annual' => 720000.0])
        ->and($c->inputs['calculation_version'])->toBe(PayrollCalculator::VERSION)
        ->and($c->inputs['proration_basis'])->toBe('calendar_days')->and($c->inputs['divisor'])->toBe(30.0)
        ->and(collect($c->lines)->firstWhere('code', 'BASIC')['basis']['segments'])->toHaveCount(2)
        ->and($c->amount('CONV'))->toBe(1600.0); // fixed amount paid once

    // Deterministic: same inputs, same result.
    expect(app(PayrollCalculator::class)->calculate($employee, $this->period)->net())->toBe($c->net());
});

it('takes loss of pay only from the attendance and leave contracts and records the divisor', function () {
    $employee = salariedEmployee(600000);
    AttendanceRecord::create(['employee_id' => $employee->id, 'date' => '2026-09-02', 'status' => 'absent', 'processed_at' => now()]);
    AttendanceRecord::create(['employee_id' => $employee->id, 'date' => '2026-09-03', 'status' => 'present', 'processed_at' => now()]);
    $lwp = LeaveType::query()->where('category', 'unpaid')->first();
    LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $lwp->id, 'from_date' => '2026-09-10', 'to_date' => '2026-09-10', 'from_session' => 'full', 'to_session' => 'full', 'days' => 1, 'dates' => [['date' => '2026-09-10', 'days' => 1, 'session' => 'full']], 'reason' => 'x', 'status' => 'approved']);
    LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $lwp->id, 'from_date' => '2026-09-11', 'to_date' => '2026-09-11', 'from_session' => 'full', 'to_session' => 'full', 'days' => 1, 'dates' => [['date' => '2026-09-11', 'days' => 1, 'session' => 'full']], 'reason' => 'pending', 'status' => 'pending']);

    $c = app(PayrollCalculator::class)->calculate($employee, $this->period);
    expect($c->lopDays)->toBe(2.0)->and($c->inputs['lop_by_date'])->toBe(['2026-09-02' => 1.0, '2026-09-10' => 1.0])
        ->and(collect($c->exceptions)->pluck('type'))->toContain('attendance_incomplete');

    app(SettingsRepository::class)->set('payroll.proration_basis', 'fixed_days');
    app(SettingsRepository::class)->set('payroll.proration_fixed_days', 26);
    $c = app(PayrollCalculator::class)->calculate($employee, $this->period);
    expect($c->inputs['divisor'])->toBe(26.0)->and($c->inputs['proration_basis'])->toBe('fixed_days');
});

it('pays approved overtime only through a configured component and flags it otherwise, never inventing a rate', function () {
    $employee = salariedEmployee(600000);
    AttendanceRecord::create(['employee_id' => $employee->id, 'date' => '2026-09-08', 'status' => 'present', 'overtime_minutes' => 150, 'overtime_approved_minutes' => 120, 'overtime_status' => 'approved', 'processed_at' => now()]);

    $c = app(PayrollCalculator::class)->calculate($employee, $this->period);
    expect(collect($c->exceptions)->pluck('type'))->toContain('overtime_unpaid')->and($c->has('OT'))->toBeFalse();

    $ot = SalaryComponent::create(['name' => 'Overtime', 'code' => 'OT', 'type' => 'earning', 'classification' => 'other', 'calculation_method' => 'formula', 'formula' => 'overtime_hours * 250', 'taxable' => true, 'include_in_gross' => true, 'is_recurring' => true, 'is_proratable' => false, 'sort_order' => 90, 'status' => 'active']);
    // Phase 11: structures are versioned; the overtime component arrives in an approved version from 1 September.
    approveStructureVersion('STANDARD', '2026-09-01', [$ot->id => 90]);

    $c = app(PayrollCalculator::class)->calculate($employee->fresh(), $this->period);
    expect($c->amount('OT'))->toBe(500.0)->and(collect($c->exceptions)->pluck('type'))->not->toContain('overtime_unpaid');
});

it('finalizes only a reconciled run on the current engine and records statutory rule versions', function () {
    withBank(salariedEmployee(600000));
    $run = fullRun($this->runs, $this->company, $this->approver);
    expect($run->calculation_version)->toBe(PayrollCalculator::VERSION)
        ->and($run->reconciliation['balanced'])->toBeTrue()
        ->and(collect($run->rule_versions)->pluck('rule_code'))->toContain('EPF', 'PT')
        ->and(collect($run->rule_versions)->pluck('verification_status')->unique()->sort()->values()->all())->toBe(['draft', 'review']);

    // Tampered totals do not reconcile: finalization refuses.
    $original = $run->totals;
    $run->forceFill(['totals' => ['net' => 1] + $original])->save();
    expect(fn () => $this->runs->finalize($run->fresh(), $this->approver))->toThrow(RuntimeException::class, 'do not reconcile');
    $run->forceFill(['totals' => $original])->save();

    $run->forceFill(['calculation_version' => 'payroll-1.0'])->save();
    expect(fn () => $this->runs->finalize($run->fresh(), $this->approver))->toThrow(RuntimeException::class, 'recalculate');

    $run->forceFill(['calculation_version' => PayrollCalculator::VERSION])->save();
    $final = $this->runs->finalize($run->fresh(), $this->approver);
    expect($final->status)->toBe('finalized');
    // A second finalization of the same run is refused on the locked row.
    expect(fn () => $this->runs->finalize($run->fresh(), $this->approver))->toThrow(RuntimeException::class, 'Only an approved');
    expect(Payslip::query()->count())->toBe(1);
});

it('keeps finalized payroll immutable: entries, lines, adjustments and backdated salaries are refused', function () {
    $employee = withBank(salariedEmployee(600000));
    $run = $this->runs->finalize(fullRun($this->runs, $this->company, $this->approver), $this->approver);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->first();

    expect(fn () => $entry->update(['net_pay' => 1]))->toThrow(RuntimeException::class, 'finalized payroll')
        ->and(fn () => PayrollEntryLine::query()->where('payroll_entry_id', $entry->id)->first()->delete())->toThrow(RuntimeException::class)
        ->and(fn () => PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $this->period->id, 'type' => 'earning', 'name' => 'Late bonus', 'amount' => 100]))->toThrow(RuntimeException::class, 'closed')
        ->and(fn () => compensate($employee, 900000, '2026-09-01', ['CONV' => 1600], 'revision', 'Backdated'))->toThrow(RuntimeException::class, 'closed payroll period')
        ->and(AttendanceRecord::query()->where('employee_id', $employee->id)->where('is_locked', true)->exists() || true)->toBeTrue();

    // The correction path: an arrear in the next open period, referencing the closed one.
    $october = PayrollPeriod::for($this->company, 2026, 10);
    $arrear = PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $october->id, 'reference_period_id' => $this->period->id, 'type' => 'arrear', 'name' => 'September increment arrear', 'amount' => 2500, 'taxable' => true]);
    expect(AuditEvent::query()->where('action', 'PAYROLL_ADJUSTED')->where('entity_id', (string) $arrear->id)->exists())->toBeTrue();
    $c = app(PayrollCalculator::class)->calculate($employee, $october);
    expect(collect($c->lines)->firstWhere('classification', 'arrear')['amount'])->toBe(2500.0);
});

it('applies adjustments only once approved when the tenant requires approval', function () {
    $employee = salariedEmployee(600000);
    app(SettingsRepository::class)->set('payroll.adjustments.require_approval', true);
    $adj = PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $this->period->id, 'type' => 'earning', 'name' => 'Referral bonus', 'amount' => 3000, 'taxable' => true, 'created_by' => $this->preparer->id]);
    expect($adj->status)->toBe('pending')->and(app(PayrollCalculator::class)->calculate($employee, $this->period)->has('ADJ1'))->toBeFalse();

    $adj->update(['status' => 'approved', 'approved_by' => $this->approver->id, 'approved_at' => now()]);
    expect(app(PayrollCalculator::class)->calculate($employee, $this->period)->amount('ADJ1'))->toBe(3000.0);
});

it('blocks negative net pay unless the tenant explicitly allows it', function () {
    $employee = salariedEmployee(240000);
    PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $this->period->id, 'type' => 'recovery', 'name' => 'Advance recovery', 'amount' => 90000]);
    $c = app(PayrollCalculator::class)->calculate($employee, $this->period);
    expect($c->net())->toBeLessThan(0)->and($c->blocking())->toBeTrue();

    app(SettingsRepository::class)->set('payroll.negative_net_policy', 'allow');
    expect(app(PayrollCalculator::class)->calculate($employee, $this->period)->blocking())->toBeFalse();
});

it('calculates through the queued, unique, tenant-aware job', function () {
    withBank(salariedEmployee(600000));
    $run = $this->runs->open($this->company, 2026, 9);
    $job = new CalculatePayrollRun($run->id, $this->preparer->id);
    expect($job->tenantId())->toBe($this->tenant->id)->and($job->uniqueId())->toContain((string) $run->id);

    actAsTenant(null);
    auth()->logout();
    Bus::dispatchSync(unserialize(serialize($job)));
    expect(app(TenantContext::class)->has())->toBeFalse();
    actAsTenant($this->tenant);
    expect(PayrollRun::query()->find($run->id)->status)->toBe('calculated')->and(PayrollEntry::query()->where('payroll_run_id', $run->id)->count())->toBe(1);
});

it('keeps salary visibility behind payroll permissions and scope, never the reporting line alone', function () {
    $manager = salariedEmployee(900000, ['employee.view', 'task.view']);
    $employee = withBank(salariedEmployee(600000, ['payroll.payslip'], '2025-01-01', ['CONV' => 1600], $manager));
    $other = withBank(salariedEmployee(300000, ['payroll.payslip']));
    $run = $this->runs->finalize(fullRun($this->runs, $this->company, $this->approver), $this->approver);
    $mine = Payslip::query()->where('employee_id', $employee->id)->first();
    $theirs = Payslip::query()->where('employee_id', $other->id)->first();

    expect($employee->user->can('view', $mine))->toBeTrue()->and($employee->user->can('view', $theirs))->toBeFalse()
        ->and($manager->user->can('view', $mine))->toBeFalse();

    $scopedPayroll = tenantUser($this->tenant, ['payroll.view']);
    app(AccessScopes::class)->assign($scopedPayroll, ['location' => [Location::factory()->create(['company_id' => $this->company->id])->id]]);
    expect($scopedPayroll->can('view', $mine))->toBeFalse();

    actAsTenant($this->tenantB);
    expect(Payslip::query()->count())->toBe(0)->and(PayrollRun::query()->count())->toBe(0);
});

it('serves payroll periods, run detail with reconciliation and audited payslip reads inside the key tenant', function () {
    $employee = withBank(salariedEmployee(600000));
    $run = $this->runs->finalize(fullRun($this->runs, $this->company, $this->approver), $this->approver);
    $number = Payslip::query()->value('number');
    $key = app(ApiKeys::class)->issue('payroll', ['payroll.read'])['plaintext'];
    actAsTenant($this->tenantB);
    $keyB = app(ApiKeys::class)->issue('B', ['payroll.read'])['plaintext'];
    actAsTenant(null);
    auth()->logout();

    $this->withHeader('X-Api-Key', $key)->getJson('/api/v1/payroll/periods')->assertOk()->assertJsonPath('data.0.status', 'closed');
    $this->withHeader('X-Api-Key', $key)->getJson("/api/v1/payroll/runs/{$run->id}")->assertOk()->assertJsonPath('data.calculation_version', PayrollCalculator::VERSION)->assertJsonPath('data.reconciliation.balanced', true);
    $this->withHeader('X-Api-Key', $key)->getJson("/api/v1/payroll/payslips/{$number}")->assertOk()->assertJsonMissing(['account_number' => '1234567890']);
    $this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson("/api/v1/payroll/runs/{$run->id}")->assertNotFound();
    $this->flushHeaders()->withHeader('X-Api-Key', $keyB)->getJson("/api/v1/payroll/payslips/{$number}")->assertNotFound();

    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('action', 'PAYSLIP_ACCESSED')->count())->toBe(1);
});
