<?php

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\AttendanceCalendar;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

require_once __DIR__.'/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| My attendance: the employee's own month — each day opens the day panel with "Regularise" (a past day missing something,
| within the regularisation window) and "Apply leave" (a working day while paid leave is left), both for the viewer's own
| record only; under the month the leave balance and the leave requests and regularisations of the month, its financial
| year and till date, for the employee and for HR looking at anyone in scope.
*/

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00'); // a Friday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    leavePolicy(['EL' => ['days' => 12, 'accrual_frequency' => 'annual'], 'CL' => ['days' => 6, 'accrual_frequency' => 'annual']]);
    $this->el = LeaveType::query()->where('code', 'EL')->sole();
    $this->schedule = weeklySchedule(generalShift());
    $this->user = tenantUser($this->tenant, (array) config('peopleos.roles.employee.permissions'));
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $this->employee->update(['user_id' => $this->user->id]);
    forceLifecycle($this->employee, LifecycleState::Active);
    assignSchedule($this->employee, $this->schedule);
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->actingAs($this->user);
});

function myMonth(array $query = ['month' => '2026-09'])
{
    return Livewire::withQueryParams($query)->test(AttendanceCalendar::class);
}

it('opens the employee\'s own month as My attendance, in Time & leave, with each day opening the day panel', function () {
    expect(AttendanceCalendar::canAccess())->toBeTrue()
        ->and(AttendanceCalendar::getNavigationLabel())->toBe('My attendance')
        ->and(AttendanceCalendar::getNavigationGroup())->toBe('Attendance');

    myMonth()->assertOk()->assertSet('employee', $this->employee->id)->assertSee('My attendance')
        ->assertSee('Click a day to regularise it or apply for leave')
        ->assertSeeHtml('data-own-day="2026-09-23"')->assertDontSeeHtml('data-open-day=')
        ->assertSee('Leave balance')->assertSee('Leave requests')->assertSee('Regularisations');

    // Time & leave opens on it for an employee (HR opens the area on the time-office desk).
    $people = collect(app(ExperienceNavigation::class)->children($this->user, 'people'))->mapWithKeys(fn (array $a) => [$a['label'] => $a['url']]);
    expect($people['Time & leave'] ?? null)->toBe(AttendanceCalendar::getUrl());
});

it('offers Regularise on a past day with a missed punch and sends the request for the employee', function () {
    punch($this->employee, '2026-09-23 09:00:00', 'in');
    app(AttendanceProcessor::class)->process($this->employee, '2026-09-23');

    $page = myMonth();
    expect($page->instance()->dayPanel('2026-09-23'))->tone->toBe('incomplete')->issue->toBeTrue()->regularise->toBeTrue()->apply_leave->toBeTrue();

    // The day panel hands over to the regularisation form for that day.
    $page->mountAction('day', ['date' => '2026-09-23'])->assertMountedActionModalSee(['Leave on this day', 'Regularisations for this day', 'This day needs attention', 'You have 18 days of paid leave left.'])
        ->callAction('dayRegularise')->assertActionMounted('regularise');

    myMonth()->callAction(TestAction::make('regularise')->arguments(['date' => '2026-09-23']), data: ['type' => 'missed_punch', 'in' => '09:00', 'out' => '18:00', 'reason' => 'Forgot to punch out'])
        ->assertHasNoActionErrors();
    $request = AttendanceRegularisation::query()->sole();
    expect($request->employee_id)->toBe($this->employee->id)->and($request->status)->toBe('pending')->and($request->type)->toBe('missed_punch')
        ->and($request->date->toDateString())->toBe('2026-09-23')->and($request->requested_out->format('H:i'))->toBe('18:00');

    // The day now carries the pending request and no longer offers another one.
    $again = myMonth()->assertSee('AR pending');
    expect($again->instance()->dayPanel('2026-09-23'))->regularise->toBeFalse()
        ->notes->toContain('A regularisation for this day is already waiting for approval.');
});

it('does not offer Regularise outside the window, on a weekly off or on a future day', function () {
    $page = myMonth();

    // Fifteen days back, nothing processed: something is missing, but the default window is seven days.
    expect($page->instance()->dayPanel('2026-09-10'))->issue->toBeTrue()->regularise->toBeFalse()
        ->notes->toContain('Regularisation is open for the last 7 days only. Ask HR to correct this day.');
    // A weekly off: nothing to fix and no leave to take.
    expect($page->instance()->dayPanel('2026-09-20'))->tone->toBe('weekly_off')->issue->toBeFalse()->regularise->toBeFalse()->apply_leave->toBeFalse();
    // A future working day: leave only.
    expect($page->instance()->dayPanel('2026-09-29'))->issue->toBeFalse()->regularise->toBeFalse()->apply_leave->toBeTrue();
    // Not a day of the month on screen (or not a date at all): nothing.
    expect($page->instance()->dayPanel('2026-10-05'))->toBeNull()->and($page->instance()->dayPanel('not-a-date'))->toBeNull();
});

it('applies for leave from a day, starting on that day, while paid leave is left', function () {
    myMonth()->mountAction('day', ['date' => '2026-09-29'])->callAction('dayApplyLeave')->assertActionMounted('applyLeave');

    myMonth()->mountAction(TestAction::make('applyLeave')->arguments(['date' => '2026-09-29']))
        ->assertSchemaStateSet(['from_date' => '2026-09-29', 'to_date' => '2026-09-29', 'from_session' => 'full'], 'mountedActionSchema0');

    myMonth()->callAction(TestAction::make('applyLeave')->arguments(['date' => '2026-09-29']),
        data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-29', 'to_date' => '2026-09-29', 'from_session' => 'full', 'reason' => 'Family function'])
        ->assertHasNoActionErrors();
    $leave = LeaveRequest::query()->sole();
    expect($leave->employee_id)->toBe($this->employee->id)->and($leave->status)->toBe('pending')->and($leave->from_date->toDateString())->toBe('2026-09-29');

    $page = myMonth()->assertSee('EL pending');
    expect($page->instance()->dayPanel('2026-09-29'))->apply_leave->toBeFalse();

    // Someone with no paid leave left is told so instead.
    $user = tenantUser($this->tenant, (array) config('peopleos.roles.employee.permissions'));
    $other = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $other->update(['user_id' => $user->id]);
    assignSchedule($other, $this->schedule);
    $this->actingAs($user);
    expect(myMonth()->instance()->dayPanel('2026-09-29'))->apply_leave->toBeFalse()->notes->toContain('No paid leave left to apply for.');
});

it('counts leave requests and regularisations for the month, its financial year and till date, with the leave balance', function () {
    $ar = fn (string $date, string $status) => AttendanceRegularisation::create(['employee_id' => $this->employee->id, 'date' => $date, 'type' => 'missed_punch', 'reason' => 'x', 'status' => $status]);
    $ar('2026-09-02', 'approved');
    $ar('2026-09-03', 'rejected');
    $ar('2026-09-04', 'pending');
    $ar('2026-05-11', 'approved');  // same financial year (2026-27)
    $ar('2026-02-10', 'rejected');  // the year before
    $leave = fn (string $from, string $to, array $dates, string $status) => LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->el->id,
        'from_date' => $from, 'to_date' => $to, 'days' => count($dates), 'dates' => array_map(fn ($d) => ['date' => $d, 'days' => 1, 'session' => 'full'], $dates), 'reason' => 'x', 'status' => $status]);
    $leave('2026-09-14', '2026-09-15', ['2026-09-14', '2026-09-15'], 'approved');
    $leave('2026-09-30', '2026-10-01', ['2026-09-30', '2026-10-01'], 'approved'); // one day in September
    $leave('2026-06-01', '2026-06-01', ['2026-06-01'], 'rejected');
    $leave('2026-01-05', '2026-01-05', ['2026-01-05'], 'approved');             // the year before

    $overview = myMonth()->assertSee('FY 2026-27')->assertSee('Till date')->instance()->overview();

    expect($overview['periods'])->toBe(['month' => 'Sep 2026', 'year' => 'FY 2026-27', 'all' => 'Till date'])
        ->and($overview['regularisation']['month'])->toMatchArray(['applied' => 3, 'approved' => 1, 'rejected' => 1, 'pending' => 1])
        ->and($overview['regularisation']['year'])->toMatchArray(['applied' => 4, 'approved' => 2, 'rejected' => 1])
        ->and($overview['regularisation']['all'])->toMatchArray(['applied' => 5, 'approved' => 2, 'rejected' => 2])
        ->and($overview['leave']['month'])->toMatchArray(['applied' => 2, 'approved' => 2, 'days' => 3.0])
        ->and($overview['leave']['year'])->toMatchArray(['applied' => 3, 'approved' => 2, 'rejected' => 1, 'days' => 4.0])
        ->and($overview['leave']['all'])->toMatchArray(['applied' => 4, 'approved' => 3, 'days' => 5.0])
        ->and(collect($overview['balances'])->firstWhere('code', 'EL'))->toMatchArray(['available' => 12.0, 'used' => 0.0]);
});

it('gives HR the same page for anyone they search: the day panel, balance and counts, with the day drawer in the panel', function () {
    punch($this->employee, '2026-09-23 09:00:00', 'in');
    app(AttendanceProcessor::class)->process($this->employee, '2026-09-23');
    $first = $this->employee->person->first_name;
    $this->actingAs($this->hr);

    // Without an employee record of their own: the search box opens anyone in scope.
    myMonth()->assertSee('Find an employee')->assertDontSee($this->employee->employee_code)
        ->set('employeeSearch', $first)->call('pickFirstMatch')->assertSet('employee', $this->employee->id);

    $page = myMonth(['month' => '2026-09', 'employee' => $this->employee->id])->assertSet('employee', $this->employee->id)
        ->assertSee('Attendance calendar')->assertSee('Leave balance')->assertSee('Leave requests')->assertSee('Regularisations')
        ->assertSee("Click a day to see its details, regularise it or apply for leave for {$first}")
        ->assertSeeHtml('data-panel-day="2026-09-23"')->assertDontSeeHtml('data-own-day=')->assertDontSeeHtml('data-open-day=')
        ->assertActionVisible('day')->assertActionVisible('regularise')->assertActionVisible('applyLeave');
    expect($page->instance()->isOwn())->toBeFalse()
        ->and($page->instance()->dayPanel('2026-09-23'))->own->toBeFalse()->issue->toBeTrue()->regularise->toBeTrue()->apply_leave->toBeTrue()
        ->and(collect($page->instance()->overview()['balances'])->firstWhere('code', 'EL')['available'])->toBe(12.0);

    $page->mountAction('day', ['date' => '2026-09-23'])
        ->assertMountedActionModalSee(['Leave on this day', 'Regularisations for this day', "{$first} has 18 days of paid leave left."])
        ->callAction('dayCorrections')->assertDispatched('pos-day-drawer-open', employee: $this->employee->id, date: '2026-09-23');
    myMonth(['month' => '2026-09', 'employee' => $this->employee->id])->mountAction('day', ['date' => '2026-09-23'])
        ->callAction('dayRegularise')->assertActionMounted('regularise');

    // An employee who puts someone else in the URL still gets their own month (and acts only for themselves).
    $this->actingAs($this->user);
    $other = Employee::factory()->create(['joining_date' => '2025-01-01']);
    myMonth(['month' => '2026-09', 'employee' => $other->id])->assertSet('employee', $this->employee->id)->assertActionVisible('day');
});

it('lets HR regularise for the employee (approve now or send for approval) and apply leave on their behalf', function () {
    foreach (['2026-09-23', '2026-09-24'] as $date) {
        punch($this->employee, $date.' 09:00:00', 'in');
        app(AttendanceProcessor::class)->process($this->employee, $date);
    }
    $this->actingAs($this->hr);
    $hrMonth = fn () => myMonth(['month' => '2026-09', 'employee' => $this->employee->id]);

    $hrMonth()->callAction(TestAction::make('regularise')->arguments(['date' => '2026-09-23']),
        data: ['type' => 'missed_punch', 'in' => '09:00', 'out' => '18:00', 'reason' => 'Card reader down', 'decision' => 'approve'])->assertHasNoActionErrors();
    $hrMonth()->callAction(TestAction::make('regularise')->arguments(['date' => '2026-09-24']),
        data: ['type' => 'missed_punch', 'in' => '09:00', 'out' => '18:00', 'reason' => 'Card reader down', 'decision' => 'send'])->assertHasNoActionErrors();
    $approved = AttendanceRegularisation::query()->whereDate('date', '2026-09-23')->sole();
    $pending = AttendanceRegularisation::query()->whereDate('date', '2026-09-24')->sole();
    expect($approved)->employee_id->toBe($this->employee->id)->status->toBe('approved')->requested_by->toBe($this->hr->id)->reviewed_by->toBe($this->hr->id)
        ->and($pending)->employee_id->toBe($this->employee->id)->status->toBe('pending')->requested_by->toBe($this->hr->id);

    $hrMonth()->callAction(TestAction::make('applyLeave')->arguments(['date' => '2026-09-29']),
        data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-29', 'to_date' => '2026-09-29', 'from_session' => 'full', 'reason' => 'Applied by HR'])
        ->assertHasNoActionErrors();
    expect(LeaveRequest::query()->sole())->employee_id->toBe($this->employee->id)->status->toBe('pending')->requested_by->toBe($this->hr->id);

    // The employee sees what HR raised for them.
    $this->actingAs($this->user);
    expect(myMonth()->assertSee('AR pending')->assertSee('EL pending')->instance()->overview()['regularisation']['month'])
        ->toMatchArray(['applied' => 2, 'approved' => 1, 'pending' => 1]);
});

it('gives someone who may only view attendance the same page without the on-behalf forms', function () {
    $viewer = tenantUser($this->tenant, ['attendance.view', 'leave.view', 'employee.view']);
    $this->actingAs($viewer);

    $page = myMonth(['month' => '2026-09', 'employee' => $this->employee->id])->assertSet('employee', $this->employee->id)
        ->assertSee('Leave balance')->assertSee('Click a day to see its details')->assertDontSee('regularise it or apply for leave for')
        ->assertActionVisible('day')->assertActionHidden('regularise')->assertActionHidden('applyLeave');
    expect($page->instance()->dayPanel('2026-09-22'))->issue->toBeTrue()->regularise->toBeFalse()->apply_leave->toBeFalse()->notes->toBe([]);
});

it('picks the month and the year separately, from the joining month to the last month of the payroll year', function () {
    myMonth([])->assertSet('month', '2026-09')->assertSet('calMonth', 9)->assertSet('calYear', 2026)
        ->assertSeeHtml('aria-label="Month"')->assertSeeHtml('aria-label="Year"')
        ->set('calMonth', 2)->assertSet('month', '2026-02')
        ->set('calYear', 2025)->assertSet('month', '2025-02')
        // The payroll year 2026-27 ends in March 2027: a later month opens March.
        ->set('calYear', 2027)->assertSet('month', '2027-02')
        ->set('calMonth', 11)->assertSet('month', '2027-03')->assertSet('calMonth', 3)
        ->call('shiftMonth', 1)->assertSet('month', '2027-03')
        // Nothing before the joining month (January 2025).
        ->set('calYear', 2025)->set('calMonth', 1)->call('shiftMonth', -1)->assertSet('month', '2025-01')
        ->call('thisMonth')->assertSet('month', '2026-09');

    myMonth(['month' => '2031-01'])->assertSet('month', '2027-03');
    myMonth(['month' => '2019-06'])->assertSet('month', '2025-01')->assertSet('calYear', 2025);
});

it('shows the leave balance and the counts of a past month as on its last day', function () {
    $balances = app(LeaveBalances::class);
    $balances->post($this->employee, $this->el, 2026, 'usage', -2, null, 'Taken', null, '2026-08-10');
    $balances->post($this->employee, $this->el, 2026, 'adjustment', 1, null, 'Credit', null, '2026-09-20');
    $ar = fn (string $date) => AttendanceRegularisation::create(['employee_id' => $this->employee->id, 'date' => $date, 'type' => 'missed_punch', 'reason' => 'x', 'status' => 'approved']);
    $ar('2026-07-02');
    $ar('2026-09-02');

    $el = fn (array $overview) => collect($overview['balances'])->firstWhere('code', 'EL');
    $july = myMonth(['month' => '2026-07'])->assertSee('As on 31 Jul 2026')->assertSee('Till Jul 2026')->instance()->overview();
    $august = myMonth(['month' => '2026-08'])->instance()->overview();
    $september = myMonth()->assertSee('As on today')->assertSee('Till date')->instance()->overview();

    expect($el($july))->toMatchArray(['available' => 12.0, 'used' => 0.0])
        ->and($el($august))->toMatchArray(['available' => 10.0, 'used' => 2.0])
        ->and($el($september))->toMatchArray(['available' => 11.0, 'used' => 2.0])
        ->and($july['regularisation']['all']['applied'])->toBe(1)
        ->and($september['regularisation']['all']['applied'])->toBe(2);
});

it('shows HR the month before anyone is picked, and fills it in for the employee picked', function () {
    $calendar = HolidayCalendar::create(['name' => 'National', 'code' => 'NAT']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-17', 'name' => 'Founders day', 'type' => 'public']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'Everyone', 'conditions' => []]);
    $this->actingAs($this->hr);

    // No employee yet: the month with plain days, the holidays everyone has and empty totals; nothing to click.
    $page = myMonth()->assertSet('employee', null)->assertSee('Search an employee above to see their attendance')->assertSee('Find an employee')
        ->assertSee('Founders day')->assertSeeHtml('data-tone="blank"')->assertDontSeeHtml('data-panel-day=')->assertDontSee('days not processed yet')
        ->assertActionHidden('day');
    $cal = $page->instance()->calendar();
    $days = collect($cal['days'])->keyBy(fn (array $d) => $d['date']->toDateString());
    expect($days)->toHaveCount(30)
        ->and($days['2026-09-17'])->tone->toBe('holiday')->code->toBe('HO')->planned->toBeFalse()
        ->and($days['2026-09-22'])->tone->toBe('blank')->code->toBe('')->status->toBeNull()->issue->toBeFalse()
        ->and($days['2026-09-29'])->tone->toBe('blank')
        ->and(collect($cal['summary'])->pluck('value')->unique()->all())->toBe(['–'])
        ->and($page->instance()->overview())->toBeNull();

    // Picking the employee fills in their days, totals, balance and requests, and each day opens the day panel.
    $page->set('employeeSearch', $this->employee->employee_code)->call('pickFirstMatch')->assertSet('employee', $this->employee->id)
        ->assertDontSee('Find an employee')->assertSee('Leave balance')->assertSeeHtml('data-panel-day="2026-09-22"')->assertSee('days not processed yet');
    expect(collect($page->instance()->calendar()['days'])->firstWhere(fn (array $d) => $d['date']->toDateString() === '2026-09-22'))->tone->toBe('not_processed')
        ->and($page->instance()->calendar()['summary'][0]['value'])->toBe(0);
});

it('shows the shift of each day, and marks the days with no shift assigned, for the employee and for HR', function () {
    $days = fn ($page) => collect($page->instance()->calendar()['days'])->keyBy(fn (array $d) => $d['date']->toDateString());

    // A shift on every working day: its code on the day, its name and times in the header, nothing marked.
    $own = myMonth()->assertSee('General · 09:00–18:00')->assertDontSeeHtml('data-shift-unassigned=');
    expect($days($own)['2026-09-22'])->shift->toBe('GEN')->shift_title->toBe('General · 09:00–18:00')->no_shift->toBeFalse()
        ->and($days($own)['2026-09-20'])->tone->toBe('weekly_off')->no_shift->toBeFalse()
        ->and($own->instance()->calendar()['shifts'])->toBe(['assigned' => [['code' => 'GEN', 'label' => 'General · 09:00–18:00']], 'unassigned' => 0]);

    // No schedule at all: every day is marked, in the header and on the day, with a note in the day panel.
    $user = tenantUser($this->tenant, (array) config('peopleos.roles.employee.permissions'));
    $unassigned = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $unassigned->update(['user_id' => $user->id]);
    $this->actingAs($user);
    $page = myMonth()->assertSeeHtml('data-shift-unassigned="30"')->assertSeeHtml('is-unassigned" title="No shift assigned for this day">No shift');
    expect($days($page)['2026-09-22'])->shift->toBeNull()->no_shift->toBeTrue()
        ->and($page->instance()->dayPanel('2026-09-22')['notes'])->toContain('No shift is assigned to you for this day. Ask HR to assign your shift.');
    $page->mountAction('day', ['date' => '2026-09-22'])->assertMountedActionModalSee('No shift assigned');

    // HR sees the same for that employee; a schedule from mid-month leaves only the days before it unassigned.
    $this->actingAs($this->hr);
    $hr = myMonth(['month' => '2026-09', 'employee' => $unassigned->id])->assertSeeHtml('data-shift-unassigned="30"');
    expect($hr->instance()->dayPanel('2026-09-22')['notes'])->toContain('No shift is assigned for this day: assign one with a work schedule or the roster.');
    assignSchedule($unassigned, $this->schedule, '2026-09-15');
    $hr = myMonth(['month' => '2026-09', 'employee' => $unassigned->id])->assertSee('General · 09:00–18:00')->assertSee('Shift not assigned on 14 days');
    expect($days($hr)['2026-09-14'])->no_shift->toBeTrue()->and($days($hr)['2026-09-15'])->shift->toBe('GEN')->no_shift->toBeFalse();
});
