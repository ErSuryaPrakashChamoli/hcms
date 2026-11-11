<?php

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\UserAccessScope;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Loans\Models\Loan;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Department;
use App\Domain\Payroll\Jobs\CalculatePayrollRun;
use App\Domain\Payroll\Models\CompanyBankAccount;
use App\Domain\Payroll\Models\EmployeePaymentMode;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Payroll\Models\SalaryHold;
use App\Domain\Payroll\Services\BankFile;
use App\Domain\Payroll\Services\PaymentModes;
use App\Domain\Payroll\Services\PayrollRunCockpit;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Payroll\Services\SalaryHolds;
use App\Domain\Payroll\Services\VariablePay;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Resources\PayrollRuns\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\PayrollRuns\Widgets\RunCockpit;
use App\Filament\Support\PayrollReminders;
use App\Support\Tenancy\Jobs\BindTenantContext;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| HROne parity PO-4 (matrix 25 §25.6.1 #2, #3, #6, #7; §25.6.3 #2, #4, #9; §25.6.4 #1, #12; §25.6.5 #2, #3; §25.6.6 #3, #7;
| §25.6.8 #1–#5, #7, #8; §25.6.10 #1, #2; §25.6.11 #12): the run cockpit — steps and counters read from the run and its
| inputs, reminders, the entries table's filters / selection / audited CSV, calculation in the background and on a set day,
| the optional reviewer tick (never blocking, cleared by recalculation) and the run's activity. No new money logic.
*/

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->approver = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = payrollCompany();
    $this->runs = app(PayrollRuns::class);
    $this->cockpit = app(PayrollRunCockpit::class);

    // a and b are paid by bank transfer, c in cash.
    $this->a = salariedEmployee(600000);
    $this->b = salariedEmployee(480000);
    $this->c = salariedEmployee(300000);
    foreach ([$this->a, $this->b] as $e) {
        EmployeeBankAccount::create(['employee_id' => $e->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    }
    EmployeePaymentMode::create(['employee_id' => $this->c->id, 'effective_from' => '2026-01-01', 'salary_mode' => 'cash', 'reason' => 'Paid in cash', 'created_by' => $this->hr->id]);
});

function po4Entry(PayrollRun $run, $employee): PayrollEntry
{
    return PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
}

function po4Run(): PayrollRun
{
    $runs = app(PayrollRuns::class);

    return $runs->calculate($runs->open(payrollCompany(), 2026, 9, auth()->user()), auth()->user());
}

it('keeps every default as it was: no automatic calculation, no notice from the job, entries unchecked, finalize unaffected', function () {
    expect(app(SettingsRepository::class)->get('payroll.auto_calculate_day'))->toBe(0)
        ->and(collect(config('peopleos_setup.modules.payroll.settings'))->firstWhere('key', 'payroll.auto_calculate_day'))->toMatchArray(['min' => 0, 'max' => 31]);

    $run = $this->runs->open($this->company, 2026, 9, $this->hr);
    Artisan::call('peopleos:payroll:auto-calculate', ['--date' => '2026-09-05', '--tenant' => $this->tenant->slug]);
    expect(Artisan::output())->toContain('not the payroll calculation day')
        ->and($run->refresh()->status)->toBe('draft')
        ->and(AuditEvent::query()->where('action', 'SCHEDULED')->where('entity_type', PayrollRun::class)->exists())->toBeFalse();

    // The queued job as it was used before: no notice from it.
    (new CalculatePayrollRun($run->id, $this->hr->id))->handle($this->runs);
    expect($run->refresh()->status)->toBe('calculated')
        ->and(NotificationDelivery::query()->where('subject', 'like', 'Payroll calculat%')->exists())->toBeFalse()
        ->and(PayrollEntry::query()->where('payroll_run_id', $run->id)->whereNotNull('reviewed_by')->exists())->toBeFalse();

    // Nobody checked a salary: validation, approval and finalization go through as before.
    $run = $this->runs->finalize($this->runs->approve($this->runs->validate($run), $this->approver, 'ok'), $this->approver);
    expect($run->status)->toBe('finalized')->and(Payslip::query()->count())->toBe(3);
});

it('counts each step from the records it names', function () {
    // Arrears of a: pending before the month (taken), held, void, and one inside the month (not this month's arrear).
    $pending = AttendanceArrear::create(['employee_id' => $this->a->id, 'date' => '2026-08-14', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    AttendanceArrear::create(['employee_id' => $this->a->id, 'date' => '2026-08-15', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'held']);
    AttendanceArrear::create(['employee_id' => $this->a->id, 'date' => '2026-08-16', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'void']);
    AttendanceArrear::create(['employee_id' => $this->a->id, 'date' => '2026-09-05', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    // Variable pay: a's for September (in the run), b's for October (approved, not in this run).
    $component = SalaryComponent::query()->where('type', 'earning')->where('is_statutory', false)->first();
    $september = app(VariablePay::class)->request($this->a, $component, 5000, '2026-09', 'Quarterly incentive');
    app(VariablePay::class)->approve($september, null, null);
    $october = app(VariablePay::class)->request($this->b, $component, 3000, '2026-10', 'Next quarter');
    app(VariablePay::class)->approve($october, null, null);
    // A loan paid out through payroll, a hold, adjustments (approved and waiting), approved overtime for a.
    $loan = Loan::create(['employee_id' => $this->b->id, 'number' => 'LN-PO4-1', 'loan_type' => 'personal', 'principal' => 20000, 'installments' => 10, 'emi' => 2000,
        'first_due_month' => '2026-10', 'disbursed_through' => 'payroll', 'reason' => 'Medical bills', 'status' => 'approved']);
    app(SalaryHolds::class)->hold($this->b, '2026-09', 'Documents pending');
    $run = $this->runs->open($this->company, 2026, 9, $this->hr);
    PayrollAdjustment::create(['employee_id' => $this->a->id, 'payroll_period_id' => $run->payroll_period_id, 'type' => 'earning', 'name' => 'Spot award', 'amount' => 1000, 'status' => 'approved']);
    PayrollAdjustment::create(['employee_id' => $this->b->id, 'payroll_period_id' => $run->payroll_period_id, 'type' => 'deduction', 'name' => 'Canteen', 'amount' => 200, 'status' => 'pending']);
    AttendanceRecord::create(['employee_id' => $this->a->id, 'date' => '2026-09-08', 'status' => 'present', 'overtime_minutes' => 150, 'overtime_approved_minutes' => 120, 'overtime_status' => 'approved', 'processed_at' => now()]);

    // Before calculation the run's people are the population a calculation would take.
    $draft = $this->cockpit->counters($run);
    expect($draft)->toMatchArray(['population' => 3, 'calculated' => 0, 'not_calculated' => 3, 'arrears_pending' => 1, 'arrears_held' => 1, 'arrears_void' => 1, 'arrears_in_run' => 0,
        'variable_in_run' => 0, 'variable_waiting' => 2, 'loan_payouts_waiting' => 1, 'holds_held' => 1])
        ->and(collect($this->cockpit->steps($run))->pluck('state', 'key')->all())->toBe(['inputs' => 'current', 'calculate' => 'upcoming', 'verify' => 'upcoming', 'approve' => 'upcoming', 'finalize' => 'upcoming', 'pay' => 'upcoming']);

    $run = $this->runs->calculate($run, $this->hr);
    $counters = app(PayrollRunCockpit::class)->counters($run);
    $carried = PayrollEntry::query()->where('payroll_run_id', $run->id)->get()->flatMap(fn (PayrollEntry $e) => collect($e->inputs['input_sources'] ?? [])->map(fn ($ids, $key) => [$key, $ids]))
        ->groupBy(0)->map(fn ($rows) => $rows->flatMap(fn ($r) => $r[1])->unique()->count());

    expect($counters)->toMatchArray([
        'population' => 3, 'calculated' => 3, 'not_calculated' => 0,
        'arrears_pending' => AttendanceArrear::query()->where('status', 'pending')->whereDate('date', '<', '2026-09-01')->count(),
        'arrears_in_run' => $carried['attendance_arrear'], 'arrears_held' => 1, 'arrears_void' => 1, 'arrears_settled' => 0,
        'overtime_employees' => 1, 'overtime_hours' => 2.0, 'overtime_unpaid' => PayrollEntry::query()->where('payroll_run_id', $run->id)->get()->filter(fn ($e) => collect($e->exceptions)->contains('type', 'overtime_unpaid'))->count(),
        'variable_in_run' => $carried['variable_pay'], 'variable_waiting' => 1,
        'loan_payouts_in_run' => $carried['loan_disbursement'], 'loan_payouts_waiting' => 0,
        'adjustments_approved' => 1, 'adjustments_waiting' => 1,
        'holds_held' => SalaryHold::query()->where('pay_month', '2026-09')->where('status', 'held')->count(), 'holds_released' => 0,
        'pay_bank_transfer' => 2, 'pay_cash' => 1, 'pay_cheque' => 0, 'payslips' => 0, 'paid' => 0, 'reviewed' => 0,
    ])->and($counters['arrears_in_run'])->toBe(1)->and($counters['variable_in_run'])->toBe(1)->and($counters['loan_payouts_in_run'])->toBe(1)
        ->and($carried['attendance_arrear'])->toBe(1);
    expect(collect($this->cockpit->steps($run))->pluck('state', 'key')->all())->toBe(['inputs' => 'done', 'calculate' => 'done', 'verify' => 'current', 'approve' => 'upcoming', 'finalize' => 'upcoming', 'pay' => 'upcoming'])
        ->and($this->cockpit->sources($run))->toHaveKeys(['attendance_arrear', 'variable_pay', 'loan_disbursement']);

    // The step tables behind the counters: the arrear's pay-for month and salary month; overtime hours and the missing component.
    $arrears = collect($this->cockpit->table($run, 'arrears')['rows']);
    expect($arrears)->toHaveCount(3)
        ->and($arrears->firstWhere('date', '2026-08-14'))->toMatchArray(['for_month' => 'Aug 2026', 'status' => 'Pending', 'salary_month' => 'Sep 2026', 'in_run' => 'Yes', 'employee_code' => $this->a->employee_code])
        ->and($arrears->pluck('date')->all())->not->toContain('2026-09-05');
    $overtime = $this->cockpit->table($run, 'overtime')['rows'];
    expect($overtime)->toHaveCount(1)->and($overtime[0])->toMatchArray(['employee_code' => $this->a->employee_code, 'hours' => 2.0, 'unpaid' => 'Yes']);
    expect(collect($this->cockpit->table($run, 'inputs')['rows'])->pluck('source')->unique()->sort()->values()->all())->toBe(['Attendance arrears', 'Loan payouts', 'Variable pay'])
        ->and($this->cockpit->table($run, 'holds')['rows'])->toHaveCount(1);
});

it('moves the steps with the run and counts settled arrears, payslips, released holds and paid salaries', function () {
    $pending = AttendanceArrear::create(['employee_id' => $this->a->id, 'date' => '2026-08-14', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    $hold = app(SalaryHolds::class)->hold($this->b, '2026-09', 'Documents pending');
    $run = po4Run();
    $states = fn (PayrollRun $r) => collect(app(PayrollRunCockpit::class)->steps($r->refresh()))->pluck('state', 'key')->all();

    $run = $this->runs->validate($run);
    expect($states($run))->toMatchArray(['verify' => 'done', 'approve' => 'current']);
    $run = $this->runs->approve($run, $this->approver, 'ok');
    expect($states($run))->toMatchArray(['approve' => 'done', 'finalize' => 'current']);
    $run = $this->runs->finalize($run, $this->approver);
    expect($states($run))->toMatchArray(['finalize' => 'done', 'pay' => 'current']);

    $counters = app(PayrollRunCockpit::class)->counters($run);
    expect($counters)->toMatchArray(['arrears_settled' => 1, 'arrears_pending' => 0, 'arrears_in_run' => 1, 'payslips' => 3, 'holds_held' => 1, 'paid' => 0])
        ->and($pending->refresh()->status)->toBe('settled');

    app(SalaryHolds::class)->release($hold, 'Documents received');
    $run = $this->runs->markPaid($run->refresh(), '2026-09-30', $this->approver);
    expect($states($run))->toBe(array_fill_keys(array_keys(PayrollRunCockpit::STEPS), 'done'))
        ->and(app(PayrollRunCockpit::class)->counters($run))->toMatchArray(['holds_held' => 0, 'holds_released' => 1, 'paid' => 3]);
});

it('lists the reminders with counts from each register and links to its view', function () {
    EmployeeTaxDeclaration::create(['employee_id' => $this->a->id, 'financial_year' => '2026-27', 'regime' => 'new', 'declarations' => [], 'status' => 'submitted']);
    Loan::create(['employee_id' => $this->a->id, 'number' => 'LN-PO4-2', 'loan_type' => 'personal', 'principal' => 20000, 'installments' => 10, 'emi' => 2000, 'first_due_month' => '2026-10', 'reason' => 'x', 'status' => 'pending']);
    Loan::create(['employee_id' => $this->b->id, 'number' => 'LN-PO4-3', 'loan_type' => 'personal', 'principal' => 20000, 'installments' => 10, 'emi' => 2000, 'first_due_month' => '2026-10', 'reason' => 'y', 'status' => 'approved']);
    app(SalaryHolds::class)->hold($this->c, '2026-09', 'Bank details pending');
    LeaveEncashment::create(['employee_id' => $this->a->id, 'leave_type_id' => LeaveType::query()->orderBy('id')->value('id'), 'period_year' => 2026, 'days' => 2, 'status' => 'approved', 'reason' => 'Year end']);

    $reminders = collect(PayrollReminders::forViewer())->keyBy('key');
    expect($reminders->keys()->all())->toBe(['declarations', 'proofs', 'loan_approvals', 'loan_dispense', 'claims', 'holds', 'encashments'])
        ->and($reminders->map(fn ($r) => $r['count'])->all())->toBe(['declarations' => 1, 'proofs' => 0, 'loan_approvals' => 1, 'loan_dispense' => 1, 'claims' => 0, 'holds' => 1, 'encashments' => 1])
        ->and($reminders['declarations']['url'])->toContain('view=submitted')->and($reminders['loan_dispense']['url'])->toContain('view=to_disburse')
        ->and($reminders['holds']['url'])->toContain('view=held')->and($reminders['encashments']['url'])->toContain('view=approved');

    $run = po4Run();
    Livewire::test(RunCockpit::class, ['runId' => $run->id])->assertSee('Pending loan approvals')->assertSee($reminders['loan_dispense']['url'], false);

    // Only the registers the viewer can open are listed (holds and loans need payroll.manage).
    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    $keys = collect(PayrollReminders::forViewer())->pluck('key')->all();
    expect($keys)->toContain('declarations')->not->toContain('holds')->and($keys)->not->toContain('loan_approvals');
});

it('filters the entries by pay mode, paying account, hold and check, and exports them (selected or filtered) as an audited CSV', function () {
    $account = CompanyBankAccount::create(['company_id' => $this->company->id, 'alias' => 'HDFC salary', 'bank_name' => 'HDFC Bank', 'ifsc' => 'HDFC0009999', 'account_number' => '50200012345678', 'is_default' => true]);
    app(SalaryHolds::class)->hold($this->b, '2026-09', 'Documents pending');
    $run = po4Run();
    [$ea, $eb, $ec] = [po4Entry($run, $this->a), po4Entry($run, $this->b), po4Entry($run, $this->c)];
    $table = fn () => Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewPayrollRun::class]);

    $table()->filterTable('pay_mode', 'cash')->assertCanSeeTableRecords([$ec])->assertCanNotSeeTableRecords([$ea, $eb]);
    $table()->filterTable('pay_mode', 'online_transfer')->assertCanSeeTableRecords([$ea, $eb])->assertCanNotSeeTableRecords([$ec]);
    $table()->filterTable('paying_account', (string) $account->id)->assertCanSeeTableRecords([$ea, $eb])->assertCanNotSeeTableRecords([$ec]);
    $table()->filterTable('paying_account', 'none')->assertCanSeeTableRecords([$ec])->assertCanNotSeeTableRecords([$ea, $eb]);
    $table()->filterTable('hold', 'held')->assertCanSeeTableRecords([$eb])->assertCanNotSeeTableRecords([$ea, $ec]);
    $table()->filterTable('hold', 'none')->assertCanSeeTableRecords([$ea, $ec])->assertCanNotSeeTableRecords([$eb]);
    $table()->assertSee('Cash')->assertSee('Held');

    // Selection: tick two, then the check filter; the selected rows as CSV.
    $table()->callTableBulkAction('checkSelected', [$ea, $ec])->assertNotified();
    expect(PayrollEntry::query()->whereNotNull('reviewed_by')->pluck('employee_id')->sort()->values()->all())->toBe(collect([$this->a->id, $this->c->id])->sort()->values()->all());
    $table()->filterTable('checked', 'yes')->assertCanSeeTableRecords([$ea, $ec])->assertCanNotSeeTableRecords([$eb]);

    $table()->callTableBulkAction('downloadSelected', [$eb])->assertFileDownloaded('payroll-2026-09-entries.csv');
    $export = AuditEvent::query()->where('action', 'EXPORT')->latest('id')->first();
    expect($export->metadata)->toMatchArray(['export' => 'payroll_run_step', 'table' => 'entries', 'rows' => 1, 'selection' => 'selected', 'employees' => 1])
        ->and($export->entity_id)->toBe((string) $run->id)->and($export->module)->toBe('payroll');

    $table()->filterTable('pay_mode', 'online_transfer')->callTableAction('downloadEntries')->assertFileDownloaded('payroll-2026-09-entries.csv');
    expect(AuditEvent::query()->where('action', 'EXPORT')->latest('id')->first()->metadata)->toMatchArray(['rows' => 2, 'selection' => 'table']);

    $csv = $this->cockpit->csv($run, 'entries', $this->hr);
    expect($csv)->toContain('Pay mode')->toContain('Paying account')->toContain('HDFC salary')->toContain('Cash')->toContain('Held')->toContain($this->c->employee_code);
});

it('keeps the cockpit, its tables and its CSV inside the reader\'s access scope and the payroll rights', function () {
    $department = Department::factory()->create();
    EmployeePosition::query()->where('employee_id', $this->b->id)->update(['department_id' => $department->id]);
    $run = po4Run();
    $scoped = tenantUser($this->tenant, ['payroll.view', 'payroll.calculate']);
    UserAccessScope::query()->create(['user_id' => $scoped->id, 'dimension' => 'department', 'scope_id' => $department->id]);
    $this->actingAs($scoped);

    $cockpit = new PayrollRunCockpit(app(AuditRecorder::class), app(PaymentModes::class));
    expect($cockpit->counters($run)['calculated'])->toBe(1)
        ->and(collect($cockpit->table($run, 'entries')['rows'])->pluck('employee_code')->all())->toBe([$this->b->employee_code])
        ->and($cockpit->csv($run, 'entries', $scoped))->not->toContain($this->a->employee_code);
    Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewPayrollRun::class])
        ->assertCanSeeTableRecords([po4Entry($run, $this->b)])->assertCountTableRecords(1);

    // A tick on an entry outside the scope is refused.
    $this->actingAs($this->hr);
    $outside = po4Entry($run, $this->a)->id;
    $this->actingAs($scoped);
    $result = app(PayrollRunCockpit::class)->review($run, [$outside], $scoped);
    expect($result['done'])->toBe(0)->and($result['refused'])->toHaveKey($outside);

    // Without payroll.view: no cockpit, no export.
    $viewer = tenantUser($this->tenant, ['employee.view']);
    $this->actingAs($viewer);
    expect(RunCockpit::canView())->toBeFalse()
        ->and(fn () => app(PayrollRunCockpit::class)->csv($run, 'entries', $viewer))->toThrow(RuntimeException::class, 'cannot export payroll');
    Livewire::test(RunCockpit::class, ['runId' => $run->id])->assertSee('Not available')->assertDontSee($this->b->employee_code);
});

it('calculates in the background: queued for the run and the tenant, told when done, refused when the run cannot be calculated', function () {
    $job = new CalculatePayrollRun(1);
    expect($job->tries)->toBe(2)->and($job->timeout)->toBe(3600)->and($job->backoff)->toBe([60])->and($job->tenantId)->toBe($this->tenant->id)
        ->and($job->middleware()[0])->toBeInstanceOf(BindTenantContext::class)->and($job->notify)->toBeFalse();

    $run = $this->runs->open($this->company, 2026, 9, $this->hr);
    // Streamline S2: the run's actions wait for attendance and the ticked checks (RunPayrollPageTest); this test is about the queue.
    app(SettingsRepository::class)->set('payroll.run.attendance_first', false);
    app(SettingsRepository::class)->set('payroll.run.checklist_required', false);
    Queue::fake();
    Livewire::test(ViewPayrollRun::class, ['record' => $run->id])->assertActionVisible('calculateInBackground')->callAction('calculateInBackground')->assertNotified();
    Queue::assertPushed(CalculatePayrollRun::class, fn (CalculatePayrollRun $job) => $job->runId === $run->id && $job->actorId === $this->hr->id && $job->notify && $job->tenantId === $this->tenant->id);
    expect(AuditEvent::query()->where('action', 'SCHEDULED')->where('entity_type', PayrollRun::class)->where('entity_id', (string) $run->id)->sole()->metadata)->toBe(['calculation' => 'background'])
        ->and($run->refresh()->status)->toBe('draft');
    // A second request while the first waits is refused, not queued twice.
    expect(fn () => $this->cockpit->queueCalculation($run, $this->hr))->toThrow(RuntimeException::class, 'already waiting in the background');
    Queue::assertPushed(CalculatePayrollRun::class, 1);

    // The job itself (as the worker runs it): calculated, and the person told in the app and by e-mail.
    $queued = new CalculatePayrollRun($run->id, $this->hr->id, notify: true);
    $queued->handle($this->runs);
    $deliveries = NotificationDelivery::query()->where('user_id', $this->hr->id)->where('event', 'payroll.calculated')->get();
    expect($run->refresh()->status)->toBe('calculated')
        ->and($deliveries->pluck('channel')->sort()->values()->all())->toBe(['email', 'in_app'])
        ->and($deliveries->first()->subject)->toContain('Payroll calculated: Sep 2026')->and($deliveries->first()->body)->toContain('3 employee(s) calculated');

    // Failed after its retries: the person is told.
    $queued->failed(new RuntimeException('boom'));
    expect(NotificationDelivery::query()->where('subject', 'like', 'Payroll calculation failed%')->count())->toBe(2);

    // Approved, finalized: refused from the screen and the service; the job leaves it as it is and says so.
    $run = $this->runs->approve($this->runs->validate($run), $this->approver, 'ok');
    expect(fn () => $this->cockpit->queueCalculation($run, $this->hr))->toThrow(RuntimeException::class, 'approved run cannot be recalculated');
    $run = $this->runs->finalize($run, $this->approver);
    $before = [PayrollEntry::query()->where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'net_pay'])->toArray(), $run->refresh()->calculated_at?->toIso8601String()];
    expect(fn () => $this->cockpit->queueCalculation($run, $this->hr))->toThrow(RuntimeException::class, 'finalized payroll stays as issued');
    Livewire::test(ViewPayrollRun::class, ['record' => $run->id])->assertActionHidden('calculateInBackground');
    (new CalculatePayrollRun($run->id, $this->hr->id, notify: true))->handle($this->runs);
    expect([PayrollEntry::query()->where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'net_pay'])->toArray(), $run->refresh()->calculated_at?->toIso8601String()])->toBe($before)
        ->and($run->status)->toBe('finalized')
        ->and(NotificationDelivery::query()->where('subject', 'like', 'Payroll not calculated%')->count())->toBe(2);

    // Without payroll.calculate: refused.
    expect(fn () => $this->cockpit->queueCalculation($run, tenantUser($this->tenant, ['payroll.view'])))->toThrow(RuntimeException::class, 'cannot calculate payroll');
});

it('recalculates only open runs on payroll.auto_calculate_day, once a month, never approved or finalized ones', function () {
    expect(PayrollRunCockpit::isAutoCalculateDay(0, now()->setDate(2026, 9, 5)))->toBeFalse()
        ->and(PayrollRunCockpit::isAutoCalculateDay(5, now()->setDate(2026, 9, 5)))->toBeTrue()
        ->and(PayrollRunCockpit::isAutoCalculateDay(5, now()->setDate(2026, 9, 6)))->toBeFalse()
        ->and(PayrollRunCockpit::isAutoCalculateDay(31, now()->setDate(2026, 9, 30)))->toBeTrue()
        ->and(PayrollRunCockpit::isAutoCalculateDay(31, now()->setDate(2026, 10, 30)))->toBeFalse();

    // August finalized, September approved, October opened early (draft).
    $august = $this->runs->finalize($this->runs->approve($this->runs->validate($this->runs->calculate($this->runs->open($this->company, 2026, 8, $this->hr), $this->hr)), $this->approver, 'ok'), $this->approver);
    $september = $this->runs->approve($this->runs->validate($this->runs->calculate($this->runs->open($this->company, 2026, 9, $this->hr), $this->hr)), $this->approver, 'ok');
    $october = $this->runs->open($this->company, 2026, 10, $this->hr);
    $snapshot = fn () => PayrollRun::query()->orderBy('id')->get(['id', 'status', 'calculated_at', 'approved_by'])->map(fn ($r) => [$r->id, $r->status, $r->calculated_at?->toIso8601String(), $r->approved_by])->all();
    $before = $snapshot();

    // Day 0 (the default): nothing on any day.
    Artisan::call('peopleos:payroll:auto-calculate', ['--date' => '2026-09-05', '--tenant' => $this->tenant->slug]);
    expect($snapshot())->toBe($before);

    app(SettingsRepository::class)->set('payroll.auto_calculate_day', 5);
    // Streamline review: the sweep waits for attendance and the ticked checks like Calculate does (PayrollGuidedRunTest);
    // this test is about which runs it touches.
    app(SettingsRepository::class)->set('payroll.run.attendance_first', false);
    app(SettingsRepository::class)->set('payroll.run.checklist_required', false);
    Artisan::call('peopleos:payroll:auto-calculate', ['--date' => '2026-09-04', '--tenant' => $this->tenant->slug]);
    expect($snapshot())->toBe($before);

    expect(Artisan::call('peopleos:payroll:auto-calculate', ['--date' => '2026-09-05', '--tenant' => $this->tenant->slug]))->toBe(0);
    expect(Artisan::output())->toContain('1 open payroll run(s) queued')
        ->and($october->refresh()->status)->toBe('calculated')
        ->and($september->refresh()->status)->toBe('approved')->and($august->refresh()->status)->toBe('finalized')
        ->and(collect($snapshot())->except(2)->all())->toBe(collect($before)->except(2)->all())
        ->and(AuditEvent::query()->where('action', 'SCHEDULED')->where('entity_type', PayrollRun::class)->get()->map(fn ($e) => [$e->entity_id, $e->metadata])->all())->toBe([[(string) $october->id, ['calculation' => 'automatic', 'day' => 5]]]);

    // Once a month: running again the same month does nothing more.
    Artisan::call('peopleos:payroll:auto-calculate', ['--date' => '2026-09-05', '--tenant' => $this->tenant->slug]);
    expect(AuditEvent::query()->where('action', 'SCHEDULED')->where('entity_type', PayrollRun::class)->count())->toBe(1);

    // Scheduled like every sweep: daily, overlap- and server-guarded; no digits in its name.
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'peopleos:payroll:auto-calculate'));
    expect($event)->not->toBeNull()->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue()
        ->and(preg_match('/\d/', 'peopleos:payroll:auto-calculate'))->toBe(0);
});

it('ticks salaries as checked (audited), clears the tick on recalculation, and never blocks approval or finalization', function () {
    $own = salariedEmployee(360000, ['payroll.*', 'employee.*']);
    EmployeeBankAccount::create(['employee_id' => $own->id, 'account_holder_name' => 'X', 'bank_name' => 'HDFC', 'account_number' => '1234567890', 'ifsc' => 'HDFC0000001', 'is_primary' => true]);
    $run = po4Run();
    [$ea, $eb] = [po4Entry($run, $this->a), po4Entry($run, $this->b)];

    $result = $this->cockpit->review($run, [$ea->id, $eb->id], $this->approver);
    expect($result)->toMatchArray(['done' => 2, 'unchanged' => 0, 'refused' => []])
        ->and($ea->refresh()->reviewed_by)->toBe($this->approver->id)->and($ea->reviewed_at)->not->toBeNull()
        ->and(app(PayrollRunCockpit::class)->counters($run)['reviewed'])->toBe(2)
        ->and(collect(app(PayrollRunCockpit::class)->steps($run))->firstWhere('key', 'verify')['counters'][0]['value'])->toBe('2 of 4');
    $events = AuditEvent::query()->where('action', 'REVIEWED')->where('entity_type', PayrollEntry::class)->orderBy('id')->get();
    expect($events)->toHaveCount(2)->and($events[0]->entity_type)->toBe(PayrollEntry::class)->and($events[0]->actor_id)->toBe($this->approver->id)
        ->and($events[0]->metadata)->toMatchArray(['payroll_run_id' => $run->id, 'checked' => true])
        ->and($events[0]->operation_id)->not->toBeNull()->and($events[0]->operation_id)->toBe($events[1]->operation_id);

    // Ticking again changes nothing; clearing is audited too.
    expect($this->cockpit->review($run, [$ea->id], $this->approver))->toMatchArray(['done' => 0, 'unchanged' => 1]);
    expect($this->cockpit->review($run, [$eb->id], $this->hr, false))->toMatchArray(['done' => 1])
        ->and($eb->refresh()->reviewed_by)->toBeNull();

    // One's own salary, and people who neither calculate nor approve payroll, are refused.
    $ownEntry = po4Entry($run, $own);
    expect($this->cockpit->review($run, [$ownEntry->id], $own->user)['refused'])->toBe([$ownEntry->id => 'You cannot check your own salary.'])
        ->and(fn () => $this->cockpit->review($run, [$ea->id], tenantUser($this->tenant, ['payroll.view'])))->toThrow(RuntimeException::class, 'calculate or approve payroll');

    // The screen: a row action and the reviewer column.
    Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewPayrollRun::class])
        ->callTableAction('check', $eb)->assertNotified();
    expect($eb->refresh()->reviewed_by)->toBe($this->hr->id);

    // Recalculation replaces the entries: every new entry starts unchecked; the trail keeps the ticks.
    $run = $this->runs->calculate($run, $this->hr);
    expect(PayrollEntry::query()->where('payroll_run_id', $run->id)->whereNotNull('reviewed_by')->exists())->toBeFalse()
        ->and(app(PayrollRunCockpit::class)->counters($run)['reviewed'])->toBe(0)
        ->and(AuditEvent::query()->where('action', 'REVIEWED')->where('entity_type', PayrollEntry::class)->count())->toBe(4);

    // Never blocking: one salary checked of four, validated, approved and finalized; then no more ticks.
    $this->cockpit->review($run, [po4Entry($run, $this->a)->id], $this->approver);
    $run = $this->runs->finalize($this->runs->approve($this->runs->validate($run), $this->approver, 'ok'), $this->approver);
    expect($run->status)->toBe('finalized')
        ->and(fn () => $this->cockpit->review($run, [po4Entry($run, $this->b)->id], $this->approver))->toThrow(RuntimeException::class, 'Finalized payroll stays as issued');
    Livewire::test(EntriesRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewPayrollRun::class])->assertTableActionHidden('check', po4Entry($run, $this->b));
});

it('shows the run\'s activity from its audit trail and the cockpit with its steps, tables and CSV', function () {
    AttendanceArrear::create(['employee_id' => $this->a->id, 'date' => '2026-08-14', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    $run = $this->runs->open($this->company, 2026, 9, $this->hr);
    Queue::fake();
    $this->cockpit->queueCalculation($run, $this->hr);
    Queue::assertPushed(CalculatePayrollRun::class);
    $run = $this->runs->calculate($run, $this->hr);
    $run = $this->runs->calculate($run, $this->hr);
    $this->cockpit->review($run, [po4Entry($run, $this->a)->id, po4Entry($run, $this->b)->id], $this->approver);
    $run = $this->runs->finalize($this->runs->approve($this->runs->validate($run), $this->approver, 'Checked'), $this->approver);
    app(BankFile::class)->csv($run);
    $this->cockpit->csv($run, 'arrears', $this->hr);
    $run = $this->runs->markPaid($run->refresh(), '2026-09-30', $this->approver);

    $activity = collect(app(PayrollRunCockpit::class)->activity($run));
    expect($activity->pluck('what')->all())->toBe([
        'Marked as paid', 'Step table downloaded', 'Bank file downloaded', 'Finalized', 'Approved', 'Validated', 'Salaries checked: 2', 'Calculated', 'Calculated',
        'Calculation queued in the background', 'Run opened',
    ])
        ->and($activity->firstWhere('what', 'Approved')['detail'])->toBe('Checked')
        ->and($activity->firstWhere('what', 'Approved')['by'])->toBe($this->approver->name)
        ->and($activity->where('what', 'Calculated')->first()['detail'])->toBe('3 employee(s), '.$run->exception_count.' with exceptions; replaced 3 earlier result(s)')
        ->and($activity->firstWhere('what', 'Finalized')['detail'])->toBe('3 payslip(s) issued')
        ->and($activity->firstWhere('what', 'Step table downloaded')['detail'])->toBe('Attendance arrears: 1 row(s)');

    // The cockpit widget: steps, counters, a step table opened and downloaded, the activity.
    Livewire::test(RunCockpit::class, ['runId' => $run->id])
        ->assertSee('Run cockpit')->assertSee('Finalize and publish')->assertSee('Arrears settled')->assertSee('Payslips issued')->assertSee('Run activity')->assertSee('Marked as paid')
        ->call('toggleTable', 'arrears')->assertSee('2026-08-14')->assertSee('Pay arrear (for) month')
        ->call('download', 'arrears')->assertFileDownloaded('payroll-2026-09-arrears.csv');

    // Another tenant's run is not found.
    $other = provisionTenant('Other');
    actAsTenant($other);
    $foreignRun = app(PayrollRuns::class)->open(payrollCompany(), 2026, 9);
    actAsTenant($this->tenant);
    Livewire::test(RunCockpit::class, ['runId' => $foreignRun->id])->assertSee('Not available');

    // The run page carries the cockpit.
    $this->get(PayrollRunResource::getUrl('view', ['record' => $run]))->assertOk();
});
