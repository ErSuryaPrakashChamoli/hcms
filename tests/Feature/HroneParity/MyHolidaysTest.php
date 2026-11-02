<?php

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Services\HolidayYear;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\MySpace;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveMonthMovements;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\MyHolidays;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Resources\HolidayCalendars\Pages\EditHolidayCalendar;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use App\Filament\Resources\TimeOfficeRequests\Pages\ListTimeOfficeRequests;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| HROne parity MS-12 (matrix 22 §22.11, §22.12 #4): My holidays — the year's holidays of the employee's own calendar,
| Fixed / Restricted tabs with counts, weekday, type and the employee's choice, the calendar's note for employees and
| "Choose" opening the existing restricted-holiday request with the holiday filled in — and the leave balance month table
| (added / subtracted per leave type, from the ledger). Own record only.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    Company::factory()->create();
    $this->employee = employeeWithUser(null, config('peopleos.roles.employee.permissions'));
    $this->colleague = employeeWithUser(null, config('peopleos.roles.employee.permissions'));
    assignSchedule($this->employee, weeklySchedule(generalShift()));
    $this->calendar = HolidayCalendar::create(['name' => 'India 2026', 'code' => 'IN26', 'year' => 2026, 'optional_requires_selection' => true, 'restricted_holiday_quota' => 2]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $this->calendar->id, 'name' => 'All', 'priority' => 1, 'conditions' => [], 'status' => 'active']);
    $holiday = fn (string $date, string $name, string $type) => Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'name' => $name, 'date' => $date, 'type' => $type]);
    $this->republic = $holiday('2026-01-26', 'Republic Day', 'public');
    $this->independence = $holiday('2026-08-15', 'Independence Day', 'public');
    $this->diwali = $holiday('2026-11-08', 'Diwali', 'company');
    $this->holi = $holiday('2026-03-04', 'Holi', 'optional');
    $this->onam = $holiday('2026-09-25', 'Onam', 'optional');
    $this->chhath = $holiday('2026-11-15', 'Chhath', 'optional');
    $this->newYear = $holiday('2027-01-01', 'New Year', 'company');
});

// ---- the holiday year --------------------------------------------------------------------------------------------------

it('lists the year\'s fixed and restricted holidays of the employee\'s own calendar with weekday, type and choice', function () {
    $requests = app(TimeOfficeRequests::class);
    $requests->approve($requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $this->onam->id], 'Festival', $this->employee->user), 'ok', $this->hr);
    $requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $this->chhath->id], 'Festival', $this->employee->user);

    $year = app(HolidayYear::class)->for($this->employee, 2026);
    expect($year['calendar'])->toBe(['name' => 'India 2026', 'note' => null, 'requires_selection' => true])
        ->and(collect($year['fixed'])->pluck('name')->all())->toBe(['Republic Day', 'Independence Day', 'Diwali'])
        ->and(collect($year['fixed'])->firstWhere('name', 'Independence Day'))->toMatchArray(['weekday' => 'Saturday', 'type' => 'Public holiday', 'past' => true, 'choice' => null])
        ->and(collect($year['restricted'])->pluck('choice', 'name')->all())->toBe(['Holi' => 'not_chosen', 'Onam' => 'chosen', 'Chhath' => 'requested'])
        ->and($year['quota'])->toBe(['taken' => 2, 'quota' => 2, 'year' => '2026']);

    // The colleague's choices are their own.
    expect(collect(app(HolidayYear::class)->for($this->colleague, 2026)['restricted'])->pluck('choice')->unique()->all())->toBe(['not_chosen']);

    // Next year; a calendar whose optional holidays need no choosing lists them as fixed.
    expect(collect(app(HolidayYear::class)->for($this->employee, 2027)['fixed'])->pluck('name')->all())->toBe(['New Year'])
        ->and(app(HolidayYear::class)->years($this->employee))->toBe([2027, 2026, 2025]);
    $this->calendar->update(['optional_requires_selection' => false]);
    $year = app(HolidayYear::class)->for($this->employee, 2026);
    expect($year['restricted'])->toBe([])->and($year['fixed'])->toHaveCount(6)->and($year['quota'])->toBeNull();

    // No calendar applies: nothing.
    HolidayCalendarRule::query()->update(['status' => 'inactive']);
    expect(app(HolidayYear::class)->for($this->employee, 2026)['calendar'])->toBeNull();
});

it('shows My holidays with year select, tabs with counts, the note for employees and Choose for open restricted holidays', function () {
    $this->calendar->update(['employee_note' => 'Restricted holidays must be chosen by 31 October.']);
    $this->actingAs($this->employee->user);
    $this->get(MyHolidays::getUrl())->assertOk()->assertSee('Holidays for year, 2026')->assertSee('Fixed holidays (3)')->assertSee('Restricted holidays (3)')
        ->assertSee('Independence Day')->assertSee('Saturday')->assertSee('Restricted holidays must be chosen by 31 October.');

    $page = Livewire::test(MyHolidays::class)->call('setTab', 'restricted')->assertSee('Onam')->assertSee('Choose')->assertSee('You have chosen 0 of 2');
    $data = $page->instance()->holidays();
    $onam = collect($data['restricted'])->firstWhere('name', 'Onam');
    $holi = collect($data['restricted'])->firstWhere('name', 'Holi');
    $url = $page->instance()->chooseUrl($onam);
    expect($url)->toBe(TimeOfficeRequestResource::getUrl('index').'?'.http_build_query(['action' => 'newRequest', 'actionArguments' => ['type' => 'restricted_holiday']]).'&'.http_build_query(['actionArguments' => ['holiday_id' => $this->onam->id]]))
        // A holiday already past is not offered.
        ->and($page->instance()->chooseUrl($holi))->toBeNull();

    // Year select and an unknown year.
    $page->set('year', 2027)->assertSee('Holidays for year, 2027')->assertSee('No restricted holidays this year.')->call('setTab', 'fixed')->assertSee('New Year');
    $page->set('year', 1990)->assertSet('year', 2026);
    $page->call('setTab', 'nonsense')->assertSet('tab', 'fixed');
});

it('offers Choose only when the module offers the restricted holiday request, and pre-fills the holiday on the person\'s own form', function () {
    $this->actingAs($this->employee->user);
    $onam = collect(app(HolidayYear::class)->for($this->employee, 2026)['restricted'])->firstWhere('name', 'Onam');

    // The time-office form opens on the kind with the holiday filled in — only one of the person's own optional holidays.
    Livewire::test(ListTimeOfficeRequests::class)->mountAction('newRequest', ['type' => 'restricted_holiday', 'holiday_id' => $this->onam->id])
        ->assertSchemaStateSet(['type' => 'restricted_holiday', 'holiday_id' => $this->onam->id]);
    Livewire::test(ListTimeOfficeRequests::class)->mountAction('newRequest', ['type' => 'restricted_holiday', 'holiday_id' => $this->diwali->id])
        ->assertSchemaStateSet(['type' => 'restricted_holiday', 'holiday_id' => null]);
    Livewire::test(ListTimeOfficeRequests::class)->mountAction('newRequest', ['type' => 'on_duty', 'holiday_id' => $this->onam->id])
        ->assertSchemaStateSet(['type' => 'on_duty', 'holiday_id' => null]);

    // Without selection on the calendar the module does not offer the request, and nor does My holidays.
    $this->calendar->update(['optional_requires_selection' => false]);
    app()->forgetInstance(RequestRegistry::class);
    expect(Livewire::test(MyHolidays::class)->instance()->chooseUrl($onam))->toBeNull();
});

it('opens My holidays only for an employee with a time or leave right, and lists it in My space', function () {
    $this->actingAs($this->employee->user);
    app()->forgetInstance(MySpace::class);
    expect(collect(app(MySpace::class)->available($this->employee->user))->map(fn ($e) => $e['item']->key())->all())->toContain('holidays');

    $bare = employeeWithUser(null, ['task.view']);
    $this->actingAs($bare->user);
    $this->get(MyHolidays::getUrl())->assertForbidden();

    $this->actingAs(tenantUser($this->tenant, config('peopleos.roles.employee.permissions')));
    $this->get(MyHolidays::getUrl())->assertForbidden();
});

it('keeps the note for employees on the holiday calendar form', function () {
    Livewire::test(EditHolidayCalendar::class, ['record' => $this->calendar->getRouteKey()])
        ->fillForm(['employee_note' => 'Choose by October.', 'audit_reason' => 'Note for employees'])->call('save')->assertHasNoFormErrors();
    expect($this->calendar->refresh()->employee_note)->toBe('Choose by October.')
        ->and(HolidayCalendarResource::canAccess())->toBeTrue();
});

// ---- the leave balance month table --------------------------------------------------------------------------------------

it('pivots the own leave ledger of a leave year into months, added and subtracted per leave type', function () {
    $casual = LeaveType::query()->where('code', 'CL')->firstOrFail();
    $sick = LeaveType::query()->where('code', 'SL')->firstOrFail();
    $casual->update(['name' => 'Casual leave']);
    $sick->update(['name' => 'Sick leave']);
    $post = fn (Employee $e, LeaveType $t, string $date, float $days, string $type) => LeaveLedgerEntry::query()->create(['employee_id' => $e->id, 'leave_type_id' => $t->id, 'period_year' => 2026, 'entry_date' => $date, 'type' => $type, 'days' => $days]);
    $post($this->employee, $casual, '2026-01-01', 6, 'opening');
    $post($this->employee, $casual, '2026-03-31', 1.5, 'accrual');
    $post($this->employee, $casual, '2026-03-12', -2, 'usage');
    $post($this->employee, $sick, '2026-09-02', -0.5, 'usage');
    $post($this->colleague, $casual, '2026-03-10', -5, 'usage');

    $table = app(LeaveMonthMovements::class)->for($this->employee, 2026);
    expect(array_keys($table['months']))->toHaveCount(12)->and(array_key_first($table['months']))->toBe('2026-01')
        ->and(collect($table['types'])->pluck('name')->all())->toBe(['Casual leave', 'Sick leave']);
    $cl = collect($table['types'])->firstWhere('name', 'Casual leave');
    expect($cl['months']['2026-01'])->toBe(['added' => 6.0, 'subtracted' => 0.0])
        ->and($cl['months']['2026-03'])->toBe(['added' => 1.5, 'subtracted' => 2.0])
        ->and([$cl['added'], $cl['subtracted']])->toBe([7.5, 2.0])
        ->and(collect($table['types'])->firstWhere('name', 'Sick leave')['months']['2026-09'])->toBe(['added' => 0.0, 'subtracted' => 0.5]);

    // On Leave balances: "My month by month" for the signed-in person's own ledger only.
    $this->actingAs($this->employee->user);
    Livewire::test(ListLeaveBalances::class)->assertActionVisible('monthByMonth')
        ->mountAction('monthByMonth')->assertMountedActionModalSee(['Leave month name', 'Balance added', 'Casual leave', '7.5']);
    $this->actingAs($this->colleague->user);
    Livewire::test(ListLeaveBalances::class)->mountAction('monthByMonth')->assertMountedActionModalSee('Casual leave')->assertMountedActionModalDontSee('Sick leave');

    $this->actingAs(tenantUser($this->tenant, ['leave.view']));
    Livewire::test(ListLeaveBalances::class)->assertActionHidden('monthByMonth');
});
