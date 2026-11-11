<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRunChecklist;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\RunPayroll;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/PayrollTestHelpers.php';

/*
| Streamline S2: the Run payroll wizard drives one pay month end to end with the run page's own actions — attendance
| first, every check ticked, calculate, approval by a second person, close, publish, and reopening the closed month.
*/

beforeEach(function () {
    $this->travelTo('2026-10-03 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'attendance.*']);
    $this->approver = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'attendance.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->employee = salariedEmployee(600000);
    $this->processSeptember = function () {
        for ($day = CarbonImmutable::parse('2026-09-01'); $day->lte(CarbonImmutable::parse('2026-09-30')); $day = $day->addDay()) {
            AttendanceRecord::create(['employee_id' => $this->employee->id, 'date' => $day->toDateString(), 'status' => $day->isWeekend() ? 'weekly_off' : 'present', 'processed_at' => now()]);
        }
    };
    $this->page = fn () => Livewire::test(RunPayroll::class, ['company' => (string) $this->company->id, 'month' => '2026-09'])->call('load');
});

it('opens on the first open step and lists every step', function () {
    $page = ($this->page)();

    expect(collect($page->instance()->steps())->pluck('key')->all())->toBe(array_keys(RunPayroll::STEPS))
        ->and($page->get('step'))->toBe('attendance');
    $page->assertSee('Pre-payroll checks')->assertSee('Publish payslips')->assertSee('Attendance month close');
});

it('shows the checks as what needs a look and what is clear, and a ticked check as checked', function () {
    ($this->processSeptember)();
    $toTick = collect(app(PayrollRunChecklist::class)->items($this->company->id, 2026, 9))->where('needs_tick', true);
    expect($toTick)->not->toBeEmpty();

    $page = ($this->page)()->set('step', 'checks');
    $page->assertSee('Needs a look')->assertSee('All clear')->assertSee('to tick before the run')->assertSee('Mark as checked');

    foreach ($toTick as $item) {
        $page->call('tick', $item['key']);
    }
    $page->assertSee('All clear. Ready to run payroll.')->assertSee('Checked by '.$this->preparer->name)->assertDontSee('Mark as checked');
});

it('refuses to calculate until attendance is done and every check is ticked, then runs the month end to end', function () {
    $page = ($this->page)();
    $page->callAction('startRun')->assertNotified();
    $run = PayrollRun::query()->sole();

    // Attendance is not processed for the whole month yet: the run refuses, here and on the run page.
    $page->set('step', 'calculate')->callAction('calculate')->assertNotified('Not allowed');
    Livewire::test(ViewPayrollRun::class, ['record' => $run->id])->callAction('calculate')->assertNotified('Not allowed');
    expect($run->refresh()->status)->toBe('draft');

    ($this->processSeptember)();
    $page = ($this->page)();
    foreach (collect(app(PayrollRunChecklist::class)->items($this->company->id, 2026, 9))->where('needs_tick', true) as $item) {
        $page->call('tick', $item['key']);
    }
    expect($page->instance()->blockers())->toBe([]);

    $page->set('step', 'calculate')->callAction('calculate')->assertNotified();
    $page->callAction('validate')->assertNotified('Run validated; ready for approval');
    expect($run->refresh()->status)->toBe('validated');

    $this->actingAs($this->approver);
    $page = ($this->page)();
    // The review before approval: totals, statutory totals and the change from the last closed month.
    $page->set('step', 'approve')->assertSee('Net payable')->assertSee('Professional tax')->assertSee('No earlier closed month to compare with.');
    $page->callAction('approve')->assertNotified('Run approved');
    $page->set('step', 'close')->callAction('finalize')->assertNotified('Payroll closed; payslips ready to publish');
    expect($run->refresh()->status)->toBe('finalized')->and(Payslip::query()->published()->count())->toBe(0);

    $page->set('step', 'publish')->callAction('publishPayslips')->assertNotified('Payslips published to employees');
    expect(Payslip::query()->published()->count())->toBe(1);

    $page->callAction('reopenMonth', data: ['reason' => 'Overtime was missed'])->assertNotified();
    expect($run->refresh()->status)->toBe('draft')->and($run->reopen_count)->toBe(1)->and(Payslip::query()->count())->toBe(0);
});

it('lets an organisation run payroll without waiting for attendance or ticks', function () {
    app(SettingsRepository::class)->set('payroll.run.attendance_first', false);
    app(SettingsRepository::class)->set('payroll.run.checklist_required', false);
    $page = ($this->page)();
    $page->callAction('startRun');

    $page->set('step', 'calculate')->callAction('calculate')->assertNotified();
    expect(PayrollRun::query()->sole()->status)->toBe('calculated');
});

it('is only for people who run payroll', function () {
    $this->actingAs(tenantUser($this->tenant, ['payroll.view']));
    $this->get(RunPayroll::getUrl())->assertForbidden();
});
