<?php

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleRule;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use Illuminate\Support\Carbon;

require_once __DIR__.'/AttendanceTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['name' => 'Delhi', 'company_id' => $this->company->id]);
    $this->mumbai = Location::factory()->create(['name' => 'Mumbai', 'company_id' => $this->company->id]);
    $this->hire = fn (?Location $loc) => app(HireEmployeeAction::class)->handle(['first_name' => 'E', 'last_name' => 'X'], ['joining_date' => '2025-01-01'], ['company_id' => $this->company->id, 'location_id' => $loc?->id]);
    $this->resolver = app(ShiftResolver::class);
});

it('resolves weekly schedules from individual assignments with effective dates', function () {
    $general = generalShift();
    $night = generalShift(['name' => 'Night', 'code' => 'NIGHT', 'start_time' => '22:00:00', 'end_time' => '07:00:00', 'crosses_midnight' => true]);
    $weekly = weeklySchedule($general);
    $nights = weeklySchedule($night, ['name' => 'Nights', 'code' => 'NIGHTS']);
    $employee = ($this->hire)($this->delhi);

    assignSchedule($employee, $weekly, '2026-01-05')->update(['effective_to' => '2026-06-30']);
    assignSchedule($employee, $nights, '2026-07-01');

    expect($this->resolver->resolve($employee, Carbon::parse('2026-03-04'))['shift']->code)->toBe('GEN')
        ->and($this->resolver->resolve($employee, Carbon::parse('2026-03-07'))['weekly_off'])->toBeTrue()
        ->and($this->resolver->resolve($employee, Carbon::parse('2026-07-01'))['shift']->code)->toBe('NIGHT')
        ->and($this->resolver->resolve($employee, Carbon::parse('2025-12-01')))->toMatchArray(['shift' => null, 'weekly_off' => false]);
});

it('rotates multi-week patterns from the assignment anchor', function () {
    $morning = generalShift(['name' => 'Morning', 'code' => 'MOR', 'start_time' => '06:00:00', 'end_time' => '14:00:00']);
    $evening = generalShift(['name' => 'Evening', 'code' => 'EVE', 'start_time' => '14:00:00', 'end_time' => '22:00:00']);
    $rotation = WorkSchedule::create(['name' => 'Rotation', 'code' => 'ROT', 'pattern' => [
        ['mon' => $morning->id, 'tue' => $morning->id, 'wed' => $morning->id, 'thu' => $morning->id, 'fri' => $morning->id, 'sat' => null, 'sun' => null],
        ['mon' => $evening->id, 'tue' => $evening->id, 'wed' => $evening->id, 'thu' => $evening->id, 'fri' => $evening->id, 'sat' => $evening->id, 'sun' => null],
    ]]);
    $employee = ($this->hire)($this->delhi);
    assignSchedule($employee, $rotation, '2026-09-07'); // a Monday

    expect($this->resolver->resolve($employee, Carbon::parse('2026-09-09'))['shift']->code)->toBe('MOR')
        ->and($this->resolver->resolve($employee, Carbon::parse('2026-09-16'))['shift']->code)->toBe('EVE')
        ->and($this->resolver->resolve($employee, Carbon::parse('2026-09-19'))['shift']->code)->toBe('EVE')
        ->and($this->resolver->resolve($employee, Carbon::parse('2026-09-12'))['weekly_off'])->toBeTrue()
        ->and($this->resolver->resolve($employee, Carbon::parse('2026-09-23'))['shift']->code)->toBe('MOR');
});

it('falls back to schedule rules by priority when nobody assigned a schedule', function () {
    $general = generalShift();
    $sixDay = weeklySchedule($general, ['name' => 'Six day', 'code' => 'SIX', 'pattern' => [['mon' => $general->id, 'tue' => $general->id, 'wed' => $general->id, 'thu' => $general->id, 'fri' => $general->id, 'sat' => $general->id, 'sun' => null]]]);
    $fiveDay = weeklySchedule($general);
    WorkScheduleRule::create(['work_schedule_id' => $fiveDay->id, 'name' => 'Everyone', 'priority' => 100, 'conditions' => []]);
    WorkScheduleRule::create(['work_schedule_id' => $sixDay->id, 'name' => 'Mumbai works Saturdays', 'priority' => 10, 'conditions' => [['field' => 'location_id', 'operator' => 'equals', 'value' => $this->mumbai->id]]]);

    $delhi = ($this->hire)($this->delhi);
    $mumbai = ($this->hire)($this->mumbai);
    $saturday = Carbon::parse('2026-09-26');

    expect($this->resolver->resolve($delhi, $saturday)['weekly_off'])->toBeTrue()
        ->and($this->resolver->resolve($mumbai, $saturday)['shift']->code)->toBe('GEN');
});

it('resolves holiday calendars by rules', function () {
    $national = HolidayCalendar::create(['name' => 'National', 'code' => 'NAT']);
    $mumbaiCal = HolidayCalendar::create(['name' => 'Mumbai', 'code' => 'MUM']);
    Holiday::create(['holiday_calendar_id' => $national->id, 'date' => '2026-10-02', 'name' => 'Gandhi Jayanti']);
    Holiday::create(['holiday_calendar_id' => $mumbaiCal->id, 'date' => '2026-10-02', 'name' => 'Gandhi Jayanti']);
    Holiday::create(['holiday_calendar_id' => $mumbaiCal->id, 'date' => '2026-09-14', 'name' => 'Ganesh Chaturthi']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $national->id, 'name' => 'Default', 'priority' => 100, 'conditions' => []]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $mumbaiCal->id, 'name' => 'Mumbai', 'priority' => 10, 'conditions' => [['field' => 'location_id', 'operator' => 'equals', 'value' => $this->mumbai->id]]]);

    $resolver = app(HolidayResolver::class);
    $delhi = ($this->hire)($this->delhi);
    $mumbai = ($this->hire)($this->mumbai);

    expect($resolver->calendarFor($delhi)->code)->toBe('NAT')
        ->and($resolver->holidayOn($mumbai, Carbon::parse('2026-09-14'))?->name)->toBe('Ganesh Chaturthi')
        ->and($resolver->holidayOn($delhi, Carbon::parse('2026-09-14')))->toBeNull()
        ->and($resolver->holidayOn($delhi, Carbon::parse('2026-10-02'))?->name)->toBe('Gandhi Jayanti');
});
