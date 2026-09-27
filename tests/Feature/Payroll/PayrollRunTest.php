<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Payroll\Models\EmployeeSalaryAssignment;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\Payslips;
use App\Domain\Payroll\Services\Salaries;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
});

it('keeps salary history effective-dated and audited as sensitive', function () {
    $employee = salariedEmployee(600000);
    $structure = SalaryStructure::query()->where('code', 'STANDARD')->first();

    $revised = app(Salaries::class)->assign($employee, $structure, 720000, '2026-07-01', ['CONV' => 1600], 'revision', 'Annual increment');

    $history = EmployeeSalaryAssignment::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();
    expect($history)->toHaveCount(2)
        ->and($history[0]->effective_to->toDateString())->toBe('2026-06-30')
        ->and(app(Salaries::class)->current($employee, '2026-06-15')->id)->toBe($history[0]->id)
        ->and(app(Salaries::class)->current($employee, '2026-09-30')->id)->toBe($revised->id);

    expect(fn () => app(Salaries::class)->assign($employee, $structure, 800000, '2026-07-01'))->toThrow(RuntimeException::class, 'already starts');

    $event = AuditEvent::query()->where('action', 'SALARY_CHANGED')->where('entity_id', $revised->id)->with('fieldChanges')->first();
    expect($event)->not->toBeNull()->and($event->fieldChanges->firstWhere('field', 'ctc_annual')->is_sensitive)->toBeTrue();
});

it('runs the pipeline end to end with separation of duties, locking, payslips and payment', function () {
    $a = salariedEmployee(600000, ['payroll.payslip']);
    $b = salariedEmployee(240000, ['payroll.payslip']);
    foreach ([$a, $b] as $e) {
        EmployeeBankAccount::create(['employee_id' => $e->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    }
    AttendanceRecord::create(['employee_id' => $a->id, 'date' => '2026-09-01', 'status' => 'present', 'processed_at' => now()]);

    $run = $this->runs->open($this->company, 2026, 9, $this->preparer);
    expect($run->status)->toBe('draft')->and($this->runs->open($this->company, 2026, 9)->id)->toBe($run->id);

    $run = $this->runs->calculate($run, $this->preparer);
    expect($run->status)->toBe('calculated')
        ->and($run->entries()->count())->toBe(2)
        ->and($run->total('employees'))->toBe(2.0)
        ->and($run->total('net'))->toBe(round($run->entries()->sum('net_pay'), 2))
        ->and($run->entries()->where('status', 'exception')->count())->toBe(0);

    expect(fn () => $this->runs->approve($run, $this->preparer))->toThrow(RuntimeException::class, 'Only a validated');
    $run = $this->runs->validate($run);
    expect(fn () => $this->runs->approve($run, $this->preparer))->toThrow(RuntimeException::class, 'cannot approve');
    expect(fn () => $this->runs->calculate($run))->not->toThrow(RuntimeException::class); // still editable before approval
    $run = $this->runs->validate($run->refresh());
    $run = $this->runs->approve($run, $this->approver, 'Looks right');
    expect($run->status)->toBe('approved')->and($run->approved_by)->toBe($this->approver->id);
    expect(fn () => $this->runs->calculate($run))->toThrow(RuntimeException::class, 'cannot be recalculated');

    $run = $this->runs->finalize($run, $this->approver);
    expect($run->status)->toBe('finalized')
        ->and(PayrollPeriod::query()->find($run->payroll_period_id)->status)->toBe('closed')
        ->and(AttendanceRecord::query()->where('employee_id', $a->id)->first()->is_locked)->toBeTrue()
        ->and(Payslip::query()->count())->toBe(2)
        ->and((float) Payslip::query()->where('employee_id', $a->id)->first()->get('totals.net'))->toBe((float) $run->entries()->where('employee_id', $a->id)->value('net_pay'))
        ->and(Payslip::query()->where('employee_id', $a->id)->first()->get('employee.bank.account'))->toBe('••••7890')
        ->and($a->user->notifications()->count())->toBe(1)
        ->and(fn () => $this->runs->open($this->company, 2026, 9))->toThrow(RuntimeException::class, 'closed');

    $csv = app(BankFile::class)->csv($run);
    expect($csv)->toContain('HDFC0000001', '1234567890', 'Salary Sep 2026')
        ->and(AuditEvent::query()->where('action', 'EXPORT')->where('module', 'payroll')->exists())->toBeTrue();

    // Reopen withdraws payslips and unlocks; then finalize again and pay.
    expect(fn () => $this->runs->reopen($run, ''))->toThrow(RuntimeException::class, 'reason');
    $run = $this->runs->reopen($run, 'Missed an adjustment', $this->approver);
    expect($run->status)->toBe('draft')
        ->and(Payslip::query()->count())->toBe(0)
        ->and(AttendanceRecord::query()->where('employee_id', $a->id)->first()->is_locked)->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'PAYROLL_REOPENED')->exists())->toBeTrue();

    $run = $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($run)), $this->approver), $this->approver);
    $run = $this->runs->markPaid($run, '2026-09-30', $this->approver);
    expect($run->status)->toBe('paid')->and($run->paid_at->toDateString())->toBe('2026-09-30');
    expect(fn () => $this->runs->reopen($run, 'x'))->toThrow(RuntimeException::class, 'unpaid');
});

it('prorates by attendance LOP, manual LOP, mid-month joining and exit', function () {
    $employee = salariedEmployee(600000);
    $period = PayrollPeriod::for($this->company, 2026, 9);

    foreach (['2026-09-02' => 'absent', '2026-09-03' => 'unpaid_leave', '2026-09-04' => 'present'] as $date => $status) {
        AttendanceRecord::create(['employee_id' => $employee->id, 'date' => $date, 'status' => $status, 'is_half_day_leave' => $status === 'unpaid_leave', 'processed_at' => now()]);
    }
    PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'type' => 'lop', 'name' => 'Unauthorised absence', 'amount' => 1]);
    PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'type' => 'earning', 'name' => 'Spot bonus', 'amount' => 5000, 'taxable' => true]);
    PayrollAdjustment::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'type' => 'deduction', 'name' => 'Canteen', 'amount' => 300]);

    $c = app(PayrollCalculator::class)->calculate($employee, $period);
    expect($c->lopDays)->toBe(2.5)->and($c->paidDays)->toBe(27.5)
        ->and($c->amount('BASIC'))->toBe(round(20000 * 27.5 / 30, 2))
        ->and($c->amount('ADJ1'))->toBe(5000.0)->and($c->amount('ADJ2'))->toBe(300.0)
        ->and($c->earnings())->toEqualWithDelta(48200 * 27.5 / 30 + 5000, 0.05);

    $joiner = salariedEmployee(600000);
    $joiner->update(['joining_date' => '2026-09-16']);
    $c = app(PayrollCalculator::class)->calculate($joiner->refresh(), $period);
    expect($c->paidDays)->toBe(15.0)->and($c->amount('BASIC'))->toBe(10000.0);

    $leaver = salariedEmployee(600000);
    $leaver->update(['exit_date' => '2026-09-10']);
    expect(app(PayrollCalculator::class)->calculate($leaver->refresh(), $period)->paidDays)->toBe(10.0);
});

it('blocks validation on missing salaries and formula errors while warnings pass', function () {
    $ok = salariedEmployee(600000);
    $noSalary = employeeWithUser();
    forceLifecycle($noSalary, LifecycleState::Active);

    $run = $this->runs->calculate($this->runs->open($this->company, 2026, 9));
    $entries = $run->entries()->get()->keyBy('employee_id');

    expect($entries[$noSalary->id]->status)->toBe('exception')
        ->and(collect($entries[$noSalary->id]->exceptions)->pluck('type')->all())->toBe(['attendance_missing', 'no_salary'])
        ->and($entries[$ok->id]->status)->toBe('warning')
        ->and($run->exception_count)->toBe(2);
    expect(fn () => $this->runs->validate($run))->toThrow(RuntimeException::class, '1 employee(s) have blocking exceptions');

    app(Salaries::class)->assign($noSalary, SalaryStructure::query()->where('code', 'STANDARD')->first(), 300000, '2026-01-01');
    $run = $this->runs->validate($this->runs->calculate($run->refresh()));
    expect($run->status)->toBe('validated');
});

it('renders payslip numbers and amounts in words', function () {
    expect(Payslips::inWords(0))->toBe('zero')
        ->and(Payslips::inWords(38703))->toBe('Thirty eight thousand seven hundred three')
        ->and(Payslips::inWords(12500000))->toBe('One crore twenty five lakh');
});
