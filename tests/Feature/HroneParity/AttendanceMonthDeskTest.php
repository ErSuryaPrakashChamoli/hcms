<?php

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Contracts\AttendancePeriodState;
use App\Domain\Attendance\Contracts\PendingLeaveReader;
use App\Domain\Attendance\Jobs\ProcessAttendanceRange;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Models\AttendanceMonthRun;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceArrears;
use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Attendance\Services\AttendanceMonthDesk;
use App\Domain\Attendance\Services\AttendanceMonthPopulation;
use App\Domain\Attendance\Services\AttendanceMonthRuns;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\AttendancePendingLeaveReader;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\Payroll\Inputs\AttendanceArrearInput;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\AttendanceMonthCalendar;
use App\Domain\Payroll\Services\AttendanceMonthNotice;
use App\Domain\Payroll\Services\PayCycle;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\RunAttendance;
use App\Filament\Pages\TimeOfficeDesk;
use App\Filament\Resources\AttendanceArrears\Pages\ListAttendanceArrears;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| HROne parity TX-3 (matrix 24 §24.5.3 "Run attendance"): the attendance month desk — absences with bulk mark present /
| apply leave, arrears held and released by month, the month processed in the background with progress and a notice,
| readiness errors and reminders with CSV — and, behind attendance.month_close.enabled (TIM-1, off by default), closing
| and reopening a company's month: closed days refuse requests and overrides like locked days, a month a finalized
| payroll covers is never reopened (D-TX6), and payroll only warns about an open month through a read contract.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00'); // a Tuesday; October 2026 has ended
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'Acme India']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->sales = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Sales']);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->asha = tx3Hire('Asha', '2024-03-01', $this->delhi, ['department_id' => $this->sales->id]);
    $this->ravi = tx3Hire('Ravi', '2025-06-15', $this->delhi);
    assignSchedule($this->asha, $this->schedule);
    assignSchedule($this->ravi, $this->schedule);
    app(SettingsRepository::class)->set('attendance.regularisation.window_days', 60, 'test');
    $this->closes = app(AttendanceMonthCloses::class);
});

function tx3Hire(string $first, string $joined, Location $location, array $position = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joined],
        ['company_id' => $location->company_id, 'location_id' => $location->id] + $position);
}

function tx3Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function tx3Policy(string $type, array $settings, string $code): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => $code, 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

function tx3Leave(Employee ...$employees): LeaveType
{
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'min_notice_days' => 0, 'negative_balance_limit' => 0, 'max_consecutive_days' => 0]]);
    foreach ($employees as $employee) {
        forceLifecycle($employee, LifecycleState::Active);
        app(LeaveAccrual::class)->accrue($employee);
    }

    return LeaveType::query()->where('code', 'EL')->firstOrFail();
}

// ---- Defaults: month close off -------------------------------------------------------------------------------------

it('keeps today\'s behaviour while month close is off: nothing is closed, nothing is refused, payroll sees nothing', function () {
    // Even a closed row left behind has no effect while the setting is off.
    AttendanceMonthClose::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'closed']);

    expect(config('peopleos.settings')['attendance.month_close.enabled'])->toBeFalse()
        ->and(config('peopleos.settings')['attendance.close_requires_no_pending'])->toBeTrue()
        ->and(config('peopleos.settings')['attendance.bulk_apply_leave'])->toBeFalse()
        ->and($this->closes->closedDates($this->asha, '2026-10-01', '2026-10-31'))->toBe([])
        ->and($this->closes->isClosed($this->company->id, 2026, 10))->toBeFalse()
        ->and(app(AttendancePeriodState::class)->closingInUse())->toBeFalse()
        ->and(app(AttendancePeriodState::class)->monthState($this->company->id, 2026, 10))->toBeNull()
        ->and(app(AttendanceMonthNotice::class)->forMonth($this->company->id, 2026, 11))->toBeNull()
        ->and($this->closes->closeBlockers($this->company->id, 2026, 9))->toBe(['Closing attendance months is switched off (Setup → Time office).']);

    $day = app(AttendanceOverrides::class)->set($this->asha, '2026-10-29', 'present', null, 'Was at client', $this->hr);
    expect($day->status)->toBe('present');
    $punchDay = app(AttendanceProcessor::class)->process($this->ravi, '2026-10-29');
    expect($punchDay->status)->toBe('absent');

    expect(fn () => $this->closes->close($this->company->id, 2026, 9, $this->hr))->toThrow(RuntimeException::class, 'switched off');
    expect(collect(app(PayrollReadiness::class)->evaluate($this->company->id)['notes'])->filter(fn ($n) => str_contains($n, 'ttendance for'))->all())->toBe([]);

    Livewire::test(RunAttendance::class)->call('load')
        ->assertActionHidden('closeMonth')->assertActionHidden('reopenMonth')
        ->assertSee('Attendance months are not closed in this organisation');
});

// ---- TIM-1: close and reopen ---------------------------------------------------------------------------------------

it('closes a company month once it has ended and no request waits on its days', function () {
    tx3Setting('attendance.month_close.enabled', true);
    $pending = app(Regularisations::class)->request($this->asha, '2026-10-29', 'missed_punch', 'Forgot', '2026-10-29 09:30', '2026-10-29 18:30', $this->hr);

    expect(fn () => $this->closes->close($this->company->id, 2026, 10, $this->hr))
        ->toThrow(RuntimeException::class, 'still waiting for a decision (1 attendance regularisation)');
    expect(fn () => $this->closes->close($this->company->id, 2026, 11, $this->hr))->toThrow(RuntimeException::class, 'runs until 30 Nov 2026');

    app(Regularisations::class)->reject($pending, 'Not needed', $this->hr);
    $row = $this->closes->close($this->company->id, 2026, 10, $this->hr, 'Ready for payroll');

    expect($row->status)->toBe('closed')->and($row->closed_by)->toBe($this->hr->id)->and($row->start_date->toDateString())->toBe('2026-10-01')
        ->and($row->end_date->toDateString())->toBe('2026-10-31')
        ->and($this->closes->isClosed($this->company->id, 2026, 10))->toBeTrue()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendanceMonthClose::class)->where('entity_id', (string) $row->id)->where('reason', 'Ready for payroll')->exists())->toBeTrue()
        ->and(app(AttendancePeriodState::class)->monthState($this->company->id, 2026, 10))->toMatchArray(['status' => 'closed', 'label' => 'Oct 2026', 'closed_by' => $this->hr->name]);
    expect(fn () => $this->closes->close($this->company->id, 2026, 10, $this->hr))->toThrow(RuntimeException::class, 'Oct 2026 is already closed');

    // With the pending-request rule off, a month closes although a request still waits.
    tx3Setting('attendance.close_requires_no_pending', false);
    app(Regularisations::class)->request($this->asha, '2026-09-30', 'missed_punch', 'Forgot', '2026-09-30 09:30', '2026-09-30 18:30', $this->hr);
    expect($this->closes->close($this->company->id, 2026, 9, $this->hr)->status)->toBe('closed');

    // Closing needs attendance.process.
    $viewer = tenantUser($this->tenant, ['attendance.view', 'attendance.arrears']);
    expect(fn () => $this->closes->close($this->company->id, 2026, 8, $viewer))->toThrow(RuntimeException::class, 'cannot close or reopen');
});

it('refuses requests, overrides, roster changes and leave on closed days like locked days, and keeps the days as they are', function () {
    $el = tx3Leave($this->asha);
    $other = Company::factory()->create(['name' => 'Acme Labs']);
    $vikram = tx3Hire('Vikram', '2024-01-01', Location::factory()->create(['company_id' => $other->id]));
    assignSchedule($vikram, $this->schedule);
    $absent = app(AttendanceProcessor::class)->process($this->asha, '2026-10-29');
    tx3Setting('attendance.month_close.enabled', true);
    $this->closes->close($this->company->id, 2026, 10, $this->hr);

    expect(fn () => app(AttendanceOverrides::class)->set($this->asha, '2026-10-29', 'present', null, 'Fix', $this->hr))->toThrow(RuntimeException::class, 'closed attendance month')
        ->and(fn () => app(Regularisations::class)->request($this->asha, '2026-10-30', 'missed_punch', 'Forgot', '2026-10-30 09:30', '2026-10-30 18:30', $this->hr))->toThrow(RuntimeException::class, 'closed attendance month')
        ->and(fn () => app(TimeOfficeRequests::class)->request($this->asha, 'on_duty', ['from_date' => '2026-10-28', 'session' => 'full'], 'Client site', $this->hr))->toThrow(RuntimeException::class, 'closed attendance month')
        ->and(fn () => app(Leaves::class)->request($this->asha, $el, '2026-10-30', '2026-11-02', 'Trip', requester: $this->hr))->toThrow(RuntimeException::class, 'closed attendance month')
        ->and(fn () => app(ShiftRoster::class)->assign([$this->asha], '2026-10-26', '2026-10-27', null, 'Off', $this->hr))->toThrow(RuntimeException::class, 'closed attendance month');

    // A punch arriving later does not change the closed day.
    punch($this->asha, '2026-10-29 09:05');
    punch($this->asha, '2026-10-29 18:10');
    expect(AttendanceRecord::query()->whereKey($absent->id)->value('status'))->toBe('absent');

    // November is open; another company's October is open.
    expect(app(AttendanceOverrides::class)->set($this->asha, '2026-11-02', 'present', null, 'Fix', $this->hr)->status)->toBe('present')
        ->and(app(AttendanceOverrides::class)->set($vikram, '2026-10-29', 'present', null, 'Fix', $this->hr)->status)->toBe('present');
});

it('turns a correction to a closed day into an arrear when corrections after lock are allowed, and reopening puts it on the day', function () {
    tx3Setting('attendance.allow_corrections_after_lock', true);
    $absent = app(AttendanceProcessor::class)->process($this->asha, '2026-10-29');
    tx3Setting('attendance.month_close.enabled', true);
    $this->closes->close($this->company->id, 2026, 10, $this->hr);

    app(AttendanceOverrides::class)->set($this->asha, '2026-10-29', 'present', null, 'Was at client', $this->hr);
    $arrear = AttendanceArrear::query()->where('employee_id', $this->asha->id)->sole();
    expect(AttendanceRecord::query()->whereKey($absent->id)->value('status'))->toBe('absent')
        ->and($arrear->status)->toBe('pending')->and((float) $arrear->delta_days)->toBe(1.0)
        ->and($arrear->note)->toBe('Corrected after the attendance month was closed');

    expect(fn () => $this->closes->reopen($this->company->id, 2026, 10, $this->hr, ' '))->toThrow(RuntimeException::class, 'Give the reason');
    $row = $this->closes->reopen($this->company->id, 2026, 10, $this->hr, 'Correction found');

    expect($row->status)->toBe('open')->and($row->reopened_by)->toBe($this->hr->id)->and($row->reopen_reason)->toBe('Correction found')
        ->and($arrear->refresh()->status)->toBe('void')
        ->and(AttendanceRecord::query()->whereKey($absent->id)->value('status'))->toBe('present')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendanceMonthClose::class)->where('reason', 'Correction found')->exists())->toBeTrue();
    expect(fn () => $this->closes->reopen($this->company->id, 2026, 10, $this->hr, 'Again'))->toThrow(RuntimeException::class, 'not closed');
});

it('never reopens a month a finalized payroll covers', function () {
    tx3Setting('attendance.month_close.enabled', true);
    $this->closes->close($this->company->id, 2026, 10, $this->hr);
    $period = PayrollPeriod::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'closed']);

    expect(app(AttendanceCycle::class))->toBeInstanceOf(AttendanceMonthCalendar::class)
        ->and(app(AttendanceCycle::class)->isFinal($this->company->id, 2026, 10))->toBeTrue()
        ->and(app(AttendanceCycle::class)->isFinal($this->company->id, 2026, 9))->toBeFalse();
    expect(fn () => $this->closes->reopen($this->company->id, 2026, 10, $this->hr, 'Late leave'))->toThrow(RuntimeException::class, 'is finalized: its days are locked for good');

    // A day locked by payroll in the month refuses the reopen too.
    DB::table('payroll_periods')->where('id', $period->id)->delete();
    AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-15', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);
    expect(fn () => $this->closes->reopen($this->company->id, 2026, 10, $this->hr, 'Late leave'))->toThrow(RuntimeException::class, 'cannot be reopened');
    expect($this->closes->isClosed($this->company->id, 2026, 10))->toBeTrue();
});

// ---- Payroll reads the state and warns ----------------------------------------------------------------------------

it('warns payroll about an open attendance month through the read contract and never blocks it', function () {
    tx3Setting('attendance.month_close.enabled', true);

    expect(app(AttendancePeriodState::class))->toBeInstanceOf(AttendanceMonthCloses::class)
        ->and(app(AttendanceMonthNotice::class)->forMonth($this->company->id, 2026, 11, 'Acme India'))->toStartWith('Acme India: attendance for Nov 2026 is not closed yet')
        ->and(app(PayrollReadiness::class)->evaluate($this->company->id)['notes'])->toContain(app(AttendanceMonthNotice::class)->forMonth($this->company->id, 2026, 11, 'Acme India'));

    $this->closes->close($this->company->id, 2026, 10, $this->hr);
    expect(app(AttendanceMonthNotice::class)->forMonth($this->company->id, 2026, 10))->toBeNull();

    // The payroll side reads attendance only through the contract.
    $source = file_get_contents(app_path('Domain/Payroll/Services/AttendanceMonthNotice.php'));
    expect($source)->toContain('AttendancePeriodState')->not->toContain('AttendanceMonthClose;')->not->toContain('AttendanceRecord');
});

// ---- Step 2: arrears -----------------------------------------------------------------------------------------------

it('holds and releases arrears by the month they correct, and payroll takes only pending ones', function () {
    $sepA = AttendanceArrear::create(['employee_id' => $this->asha->id, 'date' => '2026-09-10', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    $sepR = AttendanceArrear::create(['employee_id' => $this->ravi->id, 'date' => '2026-09-11', 'status_before' => 'present', 'status_after' => 'half_day', 'paid_before' => 1, 'paid_after' => 0.5, 'delta_days' => -0.5, 'status' => 'pending']);
    $aug = AttendanceArrear::create(['employee_id' => $this->asha->id, 'date' => '2026-08-20', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'pending']);
    $settled = AttendanceArrear::create(['employee_id' => $this->asha->id, 'date' => '2026-07-01', 'status_before' => 'absent', 'status_after' => 'present', 'paid_before' => 0, 'paid_after' => 1, 'delta_days' => 1, 'status' => 'settled']);

    $groups = collect(app(AttendanceMonthDesk::class)->overview($this->company->id, 2026, 10)['arrears'])->keyBy('key');
    expect($groups->keys()->all())->toBe(['2026-08', '2026-09'])
        ->and($groups['2026-09'])->toMatchArray(['label' => 'Sep 2026', 'pending' => 2, 'held' => 0, 'people' => 2, 'pending_days' => 0.5]);

    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)
        ->callAction('holdArrears', data: ['reason' => 'Audit of September'], arguments: ['month' => '2026-09'])->assertNotified('2 arrear(s) held');
    expect($sepA->refresh()->status)->toBe('held')->and($sepR->refresh()->status)->toBe('held')->and($aug->refresh()->status)->toBe('pending');

    // Settled arrears never move; the service needs attendance.arrears.
    expect(fn () => app(AttendanceArrears::class)->hold($settled, $this->hr, 'x'))->toThrow(RuntimeException::class, 'already settled')
        ->and(fn () => app(AttendanceArrears::class)->release($sepA, tenantUser($this->tenant, ['attendance.view']), 'x'))->toThrow(RuntimeException::class, 'cannot work on attendance arrears');

    // Release on the register's bulk framework.
    $run = app(BulkRuns::class)->start($this->hr, 'attendance_arrears', 'attendance_arrears.release', [$sepA->id, $aug->id], ['reason' => 'Audit done']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($sepA->refresh()->status)->toBe('pending')->and($items[$sepA->id]->outcome)->toBe('done')
        ->and($items[$aug->id]->outcome)->toBe('refused')->and($items[$aug->id]->message)->toContain('Only a held arrear');
    Livewire::test(ListAttendanceArrears::class)->assertTableBulkActionExists('bulk_attendance_arrears_hold');

    // An arrear held after a run was calculated is still settled when that run (which paid it) is finalized.
    $period = new PayrollPeriod(['company_id' => $this->company->id, 'year' => 2026, 'month' => 11, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'open']);
    $payroll = new PayrollRun;
    $payroll->setRelation('period', $period);
    (new AttendanceArrearInput)->settle($payroll, [$this->ravi->id => [$sepR->id]]);
    expect($sepR->refresh()->status)->toBe('settled');
});

it('keeps a held arrear held when its locked day changes again', function () {
    tx3Setting('attendance.allow_corrections_after_lock', true);
    AttendanceRecord::create(['employee_id' => $this->asha->id, 'date' => '2026-09-14', 'status' => 'absent', 'is_locked' => true, 'processed_at' => now()]);
    app(AttendanceOverrides::class)->set($this->asha, '2026-09-14', 'present', null, 'Was at client', $this->hr);
    $arrear = AttendanceArrear::query()->where('employee_id', $this->asha->id)->sole();
    app(AttendanceArrears::class)->hold($arrear, $this->hr, 'Check first');

    app(AttendanceOverrides::class)->set($this->asha, '2026-09-14', 'half_day', null, 'Only the morning', $this->hr);

    expect(AttendanceArrear::query()->where('employee_id', $this->asha->id)->count())->toBe(1)
        ->and($arrear->refresh()->status)->toBe('held')->and((float) $arrear->delta_days)->toBe(0.5);
});

// ---- Step 3: processing in the background ---------------------------------------------------------------------------

it('processes the month in the background for the people chosen, with progress and a notice in the app and by e-mail', function () {
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)
        ->callAction('processMonth', data: ['department_ids' => [$this->sales->id]])
        ->assertNotified('Your request to process in background has been submitted');

    $run = AttendanceMonthRun::query()->sole();
    expect($run->total)->toBe(1)->and($run->status)->toBe('completed')->and($run->done)->toBe(1)->and($run->started_by)->toBe($this->hr->id)
        ->and($run->filters)->toBe(['department_ids' => 'Departments: 1'])
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '>=', '2026-10-01')->whereDate('date', '<=', '2026-10-31')->count())->toBe(31)
        ->and(AttendanceRecord::query()->where('employee_id', $this->ravi->id)->count())->toBe(0);
    $notices = NotificationDelivery::query()->where('event', AttendanceMonthRuns::EVENT)->where('user_id', $this->hr->id)->pluck('channel')->sort()->values()->all();
    expect($notices)->toBe(['email', 'in_app'])
        ->and(NotificationDelivery::query()->where('event', AttendanceMonthRuns::EVENT)->value('subject'))->toBe('Attendance processed: Oct 2026 · Acme India');

    // Nobody chosen: nothing is queued.
    expect(fn () => app(AttendanceMonthRuns::class)->start($this->company->id, 2026, 9, collect(), $this->hr))->toThrow(RuntimeException::class, 'Nobody to process in Sep 2026');
});

it('counts failed jobs, completes a run once, and refuses a closed month or a person without the right', function () {
    $run = AttendanceMonthRun::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'running', 'total' => 2, 'started_by' => $this->hr->id]);
    (new ProcessAttendanceRange($this->asha->id, '2026-10-01', '2026-10-02', $run->id))->handle(app(AttendanceProcessor::class));
    expect($run->refresh()->status)->toBe('running')->and($run->progress())->toBe(50);
    $job = new ProcessAttendanceRange($this->ravi->id, '2026-10-01', '2026-10-02', $run->id);
    actAsTenant(null); // failed() runs outside the job middleware: it binds the run's tenant itself
    $job->failed(new RuntimeException('Device feed down'));
    actAsTenant($this->tenant);
    expect($run->refresh()->status)->toBe('completed')->and($run->failed)->toBe(1)->and($run->done)->toBe(1)
        ->and(NotificationDelivery::query()->where('event', AttendanceMonthRuns::EVENT)->where('channel', 'in_app')->value('body'))->toContain('1 could not be processed');
    app(AttendanceMonthRuns::class)->tick($run->id, true); // a late duplicate changes nothing
    expect($run->refresh()->done)->toBe(1)->and(NotificationDelivery::query()->where('event', AttendanceMonthRuns::EVENT)->count())->toBe(2);

    tx3Setting('attendance.month_close.enabled', true);
    $this->closes->close($this->company->id, 2026, 9, $this->hr);
    $people = app(AttendanceMonthPopulation::class)->filtered($this->company->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    expect(fn () => app(AttendanceMonthRuns::class)->start($this->company->id, 2026, 9, $people, $this->hr))->toThrow(RuntimeException::class, 'Sep 2026 is closed')
        ->and(fn () => app(AttendanceMonthRuns::class)->start($this->company->id, 2026, 10, $people, tenantUser($this->tenant, ['attendance.view', 'attendance.arrears'])))->toThrow(RuntimeException::class, 'cannot process attendance');
});

it('narrows the month\'s people by pasted codes, codes left out and the attendance policy', function () {
    $population = app(AttendanceMonthPopulation::class);
    [$start, $end] = [CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')];
    $codes = fn (array $filters) => $population->filtered($this->company->id, $start, $end, $filters)->pluck('employee_code')->all();

    expect($codes([]))->toBe([$this->asha->employee_code, $this->ravi->employee_code])
        ->and($codes(['codes' => $this->ravi->employee_code."\n"]))->toBe([$this->ravi->employee_code])
        ->and($codes(['exclude_codes' => $this->ravi->employee_code]))->toBe([$this->asha->employee_code])
        ->and($codes(['attendance_policy_id' => 'none']))->toHaveCount(2);
    $policy = tx3Policy('attendance', ['punch_mode' => 'single'], 'ATT_SINGLE');
    expect($codes(['attendance_policy_id' => (string) $policy->id]))->toHaveCount(2)
        ->and($codes(['punch_mode' => 'double']))->toBe([])
        ->and($codes(['punch_mode' => 'single']))->toHaveCount(2);

    // Someone who left before the month is not in it; someone who joined after it is not either.
    $gone = tx3Hire('Gone', '2024-01-01', $this->delhi);
    forceLifecycle($gone, LifecycleState::Exited, ['exit_date' => '2026-09-15']);
    tx3Hire('Later', '2026-11-02', $this->delhi);
    expect($codes([]))->toHaveCount(2);
});

// ---- Step 1 and readiness ------------------------------------------------------------------------------------------

it('shows the month\'s absences, readiness errors and reminders, with lists and an audited CSV', function () {
    app(AttendanceProcessor::class)->process($this->asha, '2026-10-29');
    AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-30', 'status' => 'half_day', 'processed_at' => now()]);
    $meera = tx3Hire('Meera', '2026-10-12', $this->delhi); // no work schedule
    app(Regularisations::class)->request($this->asha, '2026-10-28', 'missed_punch', 'Forgot', '2026-10-28 09:30', '2026-10-28 18:30', $this->hr);
    $el = tx3Leave($this->ravi);
    app(Leaves::class)->request($this->ravi, $el, '2026-10-27', '2026-10-27', 'Errand', requester: $this->hr);

    $desk = app(AttendanceMonthDesk::class);
    $overview = $desk->overview($this->company->id, 2026, 10);
    expect($overview['label'])->toBe('Oct 2026')
        ->and($overview['absences'])->toBe(['absent_days' => 1, 'half_days' => 1, 'people' => 2])
        ->and($overview['processing']['total'])->toBe(3)->and($overview['processing']['complete'])->toBe(0)
        ->and($overview['pending'])->toMatchArray(['regularisation' => 1, 'leave' => 1, 'on_duty' => 0])
        ->and($overview['readiness']['policy_missing'])->toBe(3)
        ->and($overview['readiness']['no_schedule'])->toBe(1)
        ->and($overview['readiness']['late_joiners'])->toBe(0);
    expect(array_column($desk->list('no_schedule', $this->company->id, 2026, 10)['rows'], 'employee_code'))->toBe([$meera->employee_code])
        ->and(array_column($desk->list('pending_leave', $this->company->id, 2026, 10)['rows'], 'what'))->toBe([$el->name])
        ->and(array_column($desk->list('previous_month', $this->company->id, 2026, 10)['rows'], 'employee_code'))->toBe([$this->asha->employee_code, $this->ravi->employee_code]);

    // With an attendance policy for everyone, nobody misses one.
    tx3Policy('attendance', ['grace_minutes' => 10], 'ATT');
    expect($desk->overview($this->company->id, 2026, 10)['readiness']['policy_missing'])->toBe(0);

    // The previous month also reports it is not closed, with month close in use.
    tx3Setting('attendance.month_close.enabled', true);
    expect($desk->list('previous_month', $this->company->id, 2026, 10)['rows'][0]['detail'])->toBe('Sep 2026 is not closed yet');

    $csv = $desk->csv('absences', $this->hr, $this->company->id, 2026, 10);
    expect($csv)->toContain('"Employee code",Employee,Date,"Attendance status",Type')->toContain($this->asha->employee_code.',')->toContain('Half day');
    $export = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Export->value)->latest('id')->first();
    expect($export->metadata)->toMatchArray(['export' => 'run_attendance', 'list' => 'absences', 'rows' => 2, 'month' => '2026-10']);

    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)->call('load')
        ->assertSee('1 · Attendance pre-verification')->assertSee('2 · Attendance correction arrears')->assertSee('3 · Process attendance')
        ->call('toggleList', 'pending_regularisation')->assertSee('Attendance regularisation requests · 1')
        ->assertCanSeeTableRecords(AttendanceRecord::query()->whereIn('status', ['absent', 'half_day'])->get())
        ->call('downloadList', 'pending_leave')->assertFileDownloaded('run-attendance-pending-leave-2026-10.csv');
});

it('lists the people who joined after the cycle\'s cut-off of a 25th-to-24th month', function () {
    app(PayCycle::class)->change(CarbonImmutable::parse('2026-10-25'), 'Cycle 25th to 24th');
    $late = tx3Hire('Late', '2026-10-28', $this->delhi);
    $desk = app(AttendanceMonthDesk::class);

    // October is the changeover month (1–24 Oct); November runs 25 Oct – 24 Nov.
    expect($desk->months($this->company->id)['2026-10'])->toBe('Oct 2026 · 1 Oct – 24 Oct')
        ->and(array_column($desk->list('late_joiners', $this->company->id, 2026, 10)['rows'], 'employee_code'))->toBe([$late->employee_code])
        ->and($desk->list('late_joiners', $this->company->id, 2026, 9)['rows'])->toBe([]);
});

// ---- Bulk on absences ----------------------------------------------------------------------------------------------

it('marks selected absent days present and, with TIM-5 on, applies leave for the employees through the leave service', function () {
    $el = tx3Leave($this->asha);
    $a1 = app(AttendanceProcessor::class)->process($this->asha, '2026-10-29');
    $a2 = app(AttendanceProcessor::class)->process($this->asha, '2026-10-30');
    $r1 = app(AttendanceProcessor::class)->process($this->ravi, '2026-10-30');
    $present = app(AttendanceProcessor::class)->process($this->ravi, '2026-10-31'); // a Saturday: weekly off

    $run = app(BulkRuns::class)->start($this->hr, 'run_attendance', 'attendance_records.mark_present', [$a1->id, $present->id], ['reason' => 'Was at the client']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($a1->refresh()->status)->toBe('present')->and($items[$a1->id]->outcome)->toBe('done')
        ->and(AttendanceOverride::query()->where('employee_id', $this->asha->id)->value('reason'))->toBe('Was at the client')
        ->and($items[$present->id]->outcome)->toBe('refused');

    // Off by default: not offered, and refused if started anyway.
    expect(RunAttendance::bulkOperations())->toBe(['attendance_records.mark_present'])
        ->and(fn () => app(BulkRuns::class)->start($this->hr, 'run_attendance', 'attendance_records.apply_leave', [$a2->id], ['leave_type_id' => $el->id, 'reason' => 'x']))->toThrow(RuntimeException::class, 'switched off');

    tx3Setting('attendance.bulk_apply_leave', true);
    expect(RunAttendance::bulkOperations())->toBe(RunAttendance::BULK_OPERATIONS);
    $run = app(BulkRuns::class)->start($this->hr, 'run_attendance', 'attendance_records.apply_leave', [$a2->id, $r1->id], ['leave_type_id' => $el->id, 'half_days' => 'skip', 'reason' => 'Absent without leave']);
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    $leave = LeaveRequest::query()->where('employee_id', $this->asha->id)->sole();
    expect($items[$a2->id]->outcome)->toBe('done')
        ->and($leave->status)->toBe('approved')->and($leave->reviewed_by)->toBe($this->hr->id)->and($leave->requested_by)->toBe($this->hr->id)
        ->and($a2->refresh()->status)->not->toBe('absent')
        // Ravi has no leave balance: refused with the leave service's reason, never forced.
        ->and($items[$r1->id]->outcome)->toBe('refused')
        ->and(LeaveRequest::query()->where('employee_id', $this->ravi->id)->exists())->toBeFalse();
});

// ---- The page ------------------------------------------------------------------------------------------------------

it('opens Run attendance for time-office operators, shows each step to its right, and closes and reopens from the page', function () {
    $this->get(RunAttendance::getUrl())->assertOk()->assertSee('Run attendance');
    $labels = collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Run attendance');

    $this->actingAs(tenantUser($this->tenant, ['attendance.view']));
    expect(RunAttendance::canAccess())->toBeFalse();
    $this->get(RunAttendance::getUrl())->assertForbidden();

    $arrearsOnly = tenantUser($this->tenant, ['attendance.view', 'attendance.arrears']);
    $this->actingAs($arrearsOnly);
    Livewire::test(RunAttendance::class)->call('load')
        ->assertSee('2 · Attendance correction arrears')->assertDontSee('3 · Process attendance')->assertDontSee('1 · Attendance pre-verification');

    $this->actingAs($this->hr);
    tx3Setting('attendance.month_close.enabled', true);
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)->call('load')
        ->assertSee('Open')
        ->callAction('closeMonth', data: ['note' => 'Ready'])->assertNotified('Oct 2026 closed');
    expect($this->closes->isClosed($this->company->id, 2026, 10))->toBeTrue();
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)->call('load')
        ->assertSee('Closed by')
        ->callAction('processMonth', data: [])->assertNotified('Cannot process')
        ->callAction('reopenMonth', data: ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10'])->test(RunAttendance::class)
        ->callAction('reopenMonth', data: ['reason' => 'Late leave'])->assertNotified('Oct 2026 reopened');
    expect($this->closes->isClosed($this->company->id, 2026, 10))->toBeFalse();
});

// ---- Boundaries ----------------------------------------------------------------------------------------------------

it('keeps TX-3 attendance and leave code free of payroll words and wires the contracts in one place', function () {
    $files = ['Domain/Attendance/Services/AttendanceMonthCloses.php', 'Domain/Attendance/Services/AttendanceMonthDesk.php', 'Domain/Attendance/Services/AttendanceMonthPopulation.php',
        'Domain/Attendance/Services/AttendanceMonthRuns.php', 'Domain/Attendance/Services/AttendanceArrears.php', 'Domain/Attendance/Contracts/AttendanceCycle.php',
        'Domain/Attendance/Contracts/AttendancePeriodState.php', 'Domain/Attendance/Contracts/PendingLeaveReader.php', 'Domain/Attendance/Models/AttendanceMonthClose.php',
        'Domain/Attendance/Models/AttendanceMonthRun.php', 'Domain/Leave/Services/AttendancePendingLeaveReader.php'];
    foreach ($files as $file) {
        expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|earning|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
    expect(app(PendingLeaveReader::class))->toBeInstanceOf(AttendancePendingLeaveReader::class);
});
