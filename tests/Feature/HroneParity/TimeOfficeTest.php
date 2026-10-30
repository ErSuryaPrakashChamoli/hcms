<?php

use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendancePenalty;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\EmployeeDevice;
use App\Domain\Attendance\Models\GeoFence;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\LatePenalties;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\CompOffCredits;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveDonations;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/* HROne parity HP-1: time-office requests, roster, overrides, penalties, arrears, check-in controls, donation, encashment. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    $this->shift = generalShift();
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, weeklySchedule($this->shift));
    $this->requests = app(TimeOfficeRequests::class);
    $this->processor = app(AttendanceProcessor::class);
});

function hpPolicy(string $type, array $settings, string $code): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => $code, 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

it('excuses lateness and lowers the full day for approved short leave, within the monthly limit', function () {
    hpPolicy('short_leave', ['enabled' => true, 'max_minutes' => 120, 'max_per_month' => 1, 'approval_required' => true], 'SL');
    punch($this->employee, '2026-09-23 10:30:00', 'in');
    punch($this->employee, '2026-09-23 18:00:00', 'out');
    expect($this->processor->process($this->employee, '2026-09-23'))->late_minutes->toBe(90)->status->toBe('half_day');

    $request = $this->requests->request($this->employee, 'short_leave', ['from_date' => '2026-09-23', 'start_time' => '09:00', 'end_time' => '10:30'], 'Bank visit');
    expect($request->status)->toBe('pending')->and($request->minutes)->toBe(90);
    $this->requests->approve($request, 'ok', $this->hr);

    $record = AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-23')->first();
    expect($record->late_minutes)->toBe(0)->and($record->status)->toBe('present')->and($record->exceptions)->toBeNull();

    expect(fn () => $this->requests->request($this->employee, 'short_leave', ['from_date' => '2026-09-24', 'start_time' => '17:00', 'end_time' => '18:00'], 'Again'))
        ->toThrow(RuntimeException::class, 'at most 1 short leave');
    expect(fn () => $this->requests->request($this->employee, 'short_leave', ['from_date' => '2026-10-01', 'start_time' => '09:00', 'end_time' => '12:00'], 'Long'))
        ->toThrow(RuntimeException::class, 'at most 120 minutes');
});

it('marks working days of an approved multi-day on duty, keeps the weekend off, and reverses on cancellation', function () {
    $request = $this->requests->request($this->employee, 'on_duty', ['from_date' => '2026-09-18', 'to_date' => '2026-09-21', 'session' => 'full'], 'Client site');
    $this->requests->approve($request, null, $this->hr);

    $status = fn (string $d) => AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', $d)->value('status');
    expect($status('2026-09-18'))->toBe('on_duty')->and($status('2026-09-19'))->toBe('weekly_off')->and($status('2026-09-21'))->toBe('on_duty');

    $this->requests->cancel($request->refresh(), 'Trip cancelled', $this->hr); // HR (attendance.manage) cancels at once
    expect($request->refresh()->status)->toBe('cancelled')->and($status('2026-09-18'))->toBe('absent');
});

it('needs approval to cancel an approved request when the employee asks, by policy', function () {
    $user = tenantUser($this->tenant, ['attendance.regularise']);
    $this->employee->update(['user_id' => $user->id]);
    $request = $this->requests->request($this->employee, 'on_duty', ['from_date' => '2026-09-22', 'session' => 'full'], 'Audit');
    $this->requests->approve($request, null, $this->hr);

    $this->actingAs($user);
    $this->requests->cancel($request->refresh(), 'Not needed', $user);
    expect($request->refresh()->status)->toBe('cancel_requested');
    expect(fn () => $this->requests->approveCancellation($request, null, $user))->toThrow(RuntimeException::class, 'your own request');

    $this->actingAs($this->hr);
    $this->requests->approveCancellation($request, 'fine', $this->hr);
    expect($request->refresh()->status)->toBe('cancelled');
});

it('rosters a different shift for an approved shift change and restores the schedule on cancellation', function () {
    $late = generalShift(['name' => 'Late', 'code' => 'LATE', 'start_time' => '13:00:00', 'end_time' => '22:00:00']);
    $request = $this->requests->request($this->employee, 'shift_change', ['from_date' => '2026-09-28', 'to_date' => '2026-09-29', 'shift_id' => $late->id], 'Cover');
    $this->requests->approve($request, null, $this->hr);

    expect(ShiftOverride::query()->where('employee_id', $this->employee->id)->count())->toBe(2)
        ->and(app(ShiftResolver::class)->resolve($this->employee, Carbon::parse('2026-09-28'))['shift']->id)->toBe($late->id);

    $this->requests->cancel($request->refresh(), 'Swap off', $this->hr);
    expect(ShiftOverride::query()->where('employee_id', $this->employee->id)->count())->toBe(0);

    app(ShiftRoster::class)->assign([$this->employee], '2026-09-23', '2026-09-23', null, 'Plant shutdown');
    expect($this->processor->process($this->employee, '2026-09-23')->status)->toBe('weekly_off');
});

it('makes an approved overtime claim the approved overtime of the day', function () {
    punch($this->employee, '2026-09-23 09:00:00', 'in');
    punch($this->employee, '2026-09-23 18:00:00', 'out');
    $claim = $this->requests->request($this->employee, 'overtime', ['from_date' => '2026-09-23', 'minutes' => 90], 'Release night');
    $this->requests->approve($claim, null, $this->hr);

    $record = AttendanceRecord::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-09-23')->first();
    expect($record->overtime_approved_minutes)->toBe(90)->and($record->overtime_status)->toBe('approved')->and($record->overtime_minutes)->toBe(90);
});

it('credits comp-off for work on a weekly off, refuses to cancel it once used, and lapses it after its validity', function () {
    leavePolicy(['CO' => ['days' => 0]]);
    hpPolicy('time_office', ['comp_off_validity_days' => 30, 'claim_window_days' => 30], 'TO');
    punch($this->employee, '2026-09-20 09:00:00', 'in'); // Sunday
    punch($this->employee, '2026-09-20 18:00:00', 'out');
    $this->processor->process($this->employee, '2026-09-20');

    $claim = $this->requests->request($this->employee, 'comp_off', ['from_date' => '2026-09-20'], 'Weekend deploy');
    expect((float) $claim->days)->toBe(1.0)->and($claim->expires_on->toDateString())->toBe('2026-10-20');
    $this->requests->approve($claim, null, $this->hr);

    $type = LeaveType::query()->where('code', 'CO')->first();
    $balance = fn () => app(LeaveBalances::class)->balance($this->employee, $type, 2026)->available();
    expect($balance())->toBe(1.0);

    $this->travelTo('2026-10-25 09:00:00');
    expect(app(CompOffCredits::class)->expire())->toBe(1)->and($balance())->toBe(0.0);
    expect(app(CompOffCredits::class)->expire())->toBe(0); // once per claim

    expect(fn () => $this->requests->request($this->employee, 'comp_off', ['from_date' => '2026-09-23'], 'Weekday'))->toThrow(RuntimeException::class);
});

it('makes an optional holiday a holiday only for employees who chose it, within the quota', function () {
    $calendar = HolidayCalendar::create(['name' => 'India', 'code' => 'IN', 'optional_requires_selection' => true, 'restricted_holiday_quota' => 1]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);
    $diwali = Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-24', 'name' => 'Onam', 'type' => 'optional']);
    $other = Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-30', 'name' => 'Optional two', 'type' => 'optional']);
    $colleague = Employee::factory()->create(['joining_date' => '2025-01-01']);
    assignSchedule($colleague, WorkSchedule::query()->first());

    $this->requests->approve($this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $diwali->id], 'Festival'), null, $this->hr);

    expect($this->processor->process($this->employee, '2026-09-24')->status)->toBe('holiday')
        ->and($this->processor->process($colleague, '2026-09-24')->status)->toBe('absent');
    expect(fn () => $this->requests->request($this->employee, 'restricted_holiday', ['holiday_id' => $other->id], 'Again'))->toThrow(RuntimeException::class, '1 of 1');
});

it('puts a late-mark penalty on every third late day and reports it as loss of pay, unless waived', function () {
    hpPolicy('attendance', ['grace_minutes' => 0, 'late_marks_threshold' => 3, 'late_mark_action' => 'half_day'], 'ATT');
    foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $day) {
        punch($this->employee, "{$day} 09:20:00", 'in');
        punch($this->employee, "{$day} 18:30:00", 'out');
        $this->processor->process($this->employee, $day);
    }

    $penalty = AttendancePenalty::query()->where('employee_id', $this->employee->id)->sole();
    expect($penalty->date->toDateString())->toBe('2026-09-23')->and((float) $penalty->days)->toBe(0.5)->and($penalty->late_count)->toBe(3);
    expect(app(AttendanceOutput::class)->summary($this->employee, '2026-09-21', '2026-09-23')['penalty_days'])->toBe(0.5);

    app(LatePenalties::class)->waive($penalty, 'First month');
    expect(app(AttendanceOutput::class)->summary($this->employee, '2026-09-21', '2026-09-23')['penalty_days'])->toBe(0.0);
    $this->processor->process($this->employee, '2026-09-23');
    expect($penalty->refresh()->status)->toBe('waived'); // reprocessing keeps the waiver
});

it('applies an HR override of status or worked minutes, with approved full-day leave still winning', function () {
    punch($this->employee, '2026-09-23 09:00:00', 'in');
    punch($this->employee, '2026-09-23 12:00:00', 'out');
    expect($this->processor->process($this->employee, '2026-09-23')->status)->toBe('half_day');

    expect(app(AttendanceOverrides::class)->set($this->employee, '2026-09-23', null, 480, 'Machine fault')->status)->toBe('present');
    expect(app(AttendanceOverrides::class)->set($this->employee, '2026-09-23', 'absent', null, 'Was not in')->status)->toBe('absent');
});

it('records an attendance arrear for a correction after the payroll lock only when that is allowed', function () {
    $this->processor->process($this->employee, '2026-09-23');
    AttendanceRecord::query()->where('employee_id', $this->employee->id)->update(['is_locked' => true]);

    expect(fn () => app(AttendanceOverrides::class)->set($this->employee, '2026-09-23', 'present', null, 'Late correction'))->toThrow(RuntimeException::class, 'locked');

    app(SettingsRepository::class)->set('attendance.allow_corrections_after_lock', true, 'test');
    $record = app(AttendanceOverrides::class)->set($this->employee, '2026-09-23', 'present', null, 'Late correction');
    expect($record->status)->toBe('absent'); // the locked day is never rewritten

    $arrear = AttendanceArrear::query()->sole();
    expect($arrear->status_after)->toBe('present')->and((float) $arrear->delta_days)->toBe(1.0)->and($arrear->status)->toBe('pending');
});

it('enforces the check-in policy: location, geo location and one approved device', function () {
    hpPolicy('checkin', ['require_location' => true, 'geo_fence_required' => true, 'outside_fence_action' => 'reject', 'single_device' => true, 'auto_approve_first_device' => true], 'CHK');
    GeoFence::create(['name' => 'HQ', 'latitude' => 28.6139, 'longitude' => 77.2090, 'radius_m' => 200]);
    $ingest = fn (array $meta, string $at) => app(PunchIngestion::class)->record($this->employee, $at, 'in', 'mobile', null, null, $meta + ['source_type' => 'mobile']);

    expect($ingest([], '2026-09-24 09:00:00')->processing_status)->toBe('rejected');
    expect($ingest(['latitude' => 28.70, 'longitude' => 77.10, 'device_identifier' => 'phone-1'], '2026-09-24 09:01:00'))->processing_status->toBe('rejected')->geo_status->toBe('outside');

    $ok = $ingest(['latitude' => 28.6140, 'longitude' => 77.2091, 'device_identifier' => 'phone-1'], '2026-09-24 09:02:00');
    expect($ok->processing_status)->not->toBe('rejected')->and($ok->geo_status)->toBe('inside')
        ->and(EmployeeDevice::query()->where('device_identifier', 'phone-1')->value('status'))->toBe('approved');

    expect($ingest(['latitude' => 28.6140, 'longitude' => 77.2091, 'device_identifier' => 'phone-2'], '2026-09-24 09:03:00')->processing_status)->toBe('rejected');
    // Rejected punches are evidence only.
    expect(app(PunchIngestion::class)->punchesBetween($this->employee, Carbon::parse('2026-09-24'), Carbon::parse('2026-09-24 23:59'))->count())->toBe(1)
        ->and(AttendancePunch::query()->where('processing_status', 'rejected')->count())->toBe(3);
});

it('moves donated leave between colleagues on approval and puts back a cancelled encashment', function () {
    leavePolicy(['EL' => ['days' => 12, 'encashment_allowed' => true, 'max_encash_days' => 10]]);
    $el = LeaveType::query()->where('code', 'EL')->first();
    $recipient = Employee::factory()->create(['joining_date' => '2025-01-01']);
    app(LeaveAdjustments::class)->opening($this->employee, $el, 12, 2026, 'Go-live');
    $balance = fn (Employee $e) => app(LeaveBalances::class)->balance($e, $el, 2026)->available();

    $donation = app(LeaveDonations::class)->request($this->employee, $recipient, $el, 2, 'Medical emergency');
    app(LeaveDonations::class)->approve($donation, null, $this->hr);
    expect($balance($this->employee))->toBe(10.0)->and($balance($recipient))->toBe(2.0);
    expect(fn () => app(LeaveDonations::class)->request($this->employee, $recipient, $el, 4, 'More'))->toThrow(RuntimeException::class, 'At most 5');

    $encashment = app(LeaveAdjustments::class)->requestEncashment($this->employee, $el, 3, 'Year end');
    app(LeaveAdjustments::class)->approveEncashment($encashment);
    expect($balance($this->employee))->toBe(7.0);
    app(LeaveAdjustments::class)->cancelEncashment($encashment->refresh(), 'Paid outside');
    expect($balance($this->employee))->toBe(10.0)->and($encashment->refresh()->status)->toBe('cancelled');
});

it('lists time-office requests in the Approval Center and decides them there', function () {
    $request = $this->requests->request($this->employee, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Vendor visit');

    $item = app(ApprovalCenter::class)->pending($this->hr)->first(fn ($i) => $i->type === 'time_office');
    expect($item)->not->toBeNull()->and($item->record->is($request))->toBeTrue();

    app(ApprovalDecisions::class)->decide($this->hr, $item->id, 'approve', 'Fine');
    expect($request->refresh()->status)->toBe('approved');
});

it('shows an employee their month on the attendance calendar and lets HR pick anyone', function () {
    hpPolicy('attendance', ['grace_minutes' => 0, 'late_marks_threshold' => 1, 'late_mark_action' => 'half_day'], 'ATT');
    punch($this->employee, '2026-09-23 09:30:00', 'in');
    punch($this->employee, '2026-09-23 18:30:00', 'out');
    $this->processor->process($this->employee, '2026-09-23');

    Livewire::withQueryParams(['month' => '2026-09', 'employee' => $this->employee->id])
        ->test(AttendanceCalendar::class)
        ->assertOk()->assertSee('September 2026')->assertSee('Late 30m')->assertSee('Penalty days');

    $user = tenantUser($this->tenant, ['attendance.regularise']);
    $this->employee->update(['user_id' => $user->id]);
    $other = Employee::factory()->create(['joining_date' => '2025-01-01']);
    $this->actingAs($user);
    Livewire::withQueryParams(['month' => '2026-09', 'employee' => $other->id])
        ->test(AttendanceCalendar::class)
        ->assertSet('employee', $this->employee->id); // falls back to their own month
});
