<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/* HROne setup parity TO-6: restricted holidays — joiner proration, calendar or financial year, cancellation after processing, days without a shift. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->self = tenantUser($this->tenant, ['attendance.regularise']);
    $this->actingAs($this->hr);
    $this->employee = Employee::factory()->create(['joining_date' => '2026-08-10', 'user_id' => $this->self->id]);
    assignSchedule($this->employee, weeklySchedule(generalShift()));
    $this->calendar = HolidayCalendar::create(['name' => 'India 2026', 'code' => 'IN26', 'year' => 2026, 'optional_requires_selection' => true, 'restricted_holiday_quota' => 3,
        'rh_prorate_rules' => [['from_month' => 7, 'to_month' => 12, 'quota' => 1]], 'rh_cancel_locked_for' => ['employee']]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $this->calendar->id, 'name' => 'All', 'priority' => 1, 'conditions' => [], 'status' => 'active']);
    $this->optional = fn (string $date, string $name) => Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'name' => $name, 'date' => $date, 'type' => 'optional']);
    $this->requests = app(TimeOfficeRequests::class);
});

it('prorates the restricted-holiday quota for joiners and counts it per calendar or financial year', function () {
    $first = ($this->optional)('2026-09-24', 'Onam');
    $second = ($this->optional)('2026-10-30', 'Chhath');
    $this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $first->id], 'Festival', $this->self);
    expect(fn () => $this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $second->id], 'Festival', $this->self))
        ->toThrow(RuntimeException::class, 'already chosen 1 of 1 restricted holiday(s) for 2026');

    app(SettingsRepository::class)->set('attendance.holiday_year_basis', 'financial', 'test');
    expect(fn () => $this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $second->id], 'Festival', $this->self))
        ->toThrow(RuntimeException::class, 'for 2026-27');
});

it('stops the employee cancelling a restricted holiday once attendance is processed, and can require a shift on the day', function () {
    $holiday = ($this->optional)('2026-09-24', 'Onam');
    $request = $this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $holiday->id], 'Festival', $this->self);
    $this->requests->approve($request, 'ok', $this->hr);
    $this->travelTo('2026-10-02 10:00:00');
    app(AttendanceProcessor::class)->process($this->employee, '2026-09-24');
    AttendanceRecord::query()->whereDate('date', '2026-09-24')->update(['is_locked' => true]);

    expect(fn () => $this->requests->cancel($request->refresh(), 'Changed my mind', $this->self))->toThrow(RuntimeException::class, 'cannot be cancelled once attendance is processed');

    $this->calendar->update(['rh_without_shift' => false]);
    $saturday = ($this->optional)('2026-10-10', 'Weekend festival');
    expect(fn () => $this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $saturday->id], 'Festival', $this->self))->toThrow(RuntimeException::class, 'no shift');
});
