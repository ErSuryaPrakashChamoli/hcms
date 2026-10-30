<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Models\Employee;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Support\PolicySettingsSchema;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/* HROne setup parity TO-3: regularisation, on-duty, shift and week-off change rules from HROne's check-in policy. */

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00'); // a Friday; Saturdays and Sundays are weekly offs
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->self = tenantUser($this->tenant, ['attendance.regularise', 'leave.apply']);
    $this->manager = tenantUser($this->tenant, ['attendance.approve', 'attendance.view', 'attendance.regularise']);
    $this->actingAs($this->hr);
    $this->shift = generalShift();
    $this->night = generalShift(['name' => 'Night', 'code' => 'NGT', 'start_time' => '21:00:00', 'end_time' => '06:00:00']);
    $this->employee = Employee::factory()->create(['joining_date' => '2025-01-01', 'user_id' => $this->self->id]);
    assignSchedule($this->employee, weeklySchedule($this->shift));
    $this->ar = fn (string $date, $by, array $extra = []) => app(Regularisations::class)->request($this->employee, $date, 'missed_punch', 'Forgot', $extra['in'] ?? null, $extra['out'] ?? null, $by, $extra['files'] ?? []);
    $this->to = fn (string $type, array $data, $by) => app(TimeOfficeRequests::class)->request($this->employee, $type, $data, 'Work', $by);
});

function rulesPolicy(string $type, array $settings): void
{
    $policy = Policy::create(['type' => $type, 'name' => $type, 'code' => strtoupper($type)]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2020-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);
}

it('applies HROne\'s regularisation rules: role windows, future dates, week-offs, punches, shift range, attachments, limits and processed days', function () {
    rulesPolicy('attendance', ['regularisation_window_days' => 3, 'ar_manager_backdated_days' => 10, 'ar_future_allowed' => false, 'ar_requires_punch' => true,
        'ar_not_on' => ['weekly_off'], 'ar_limit_period' => 'month', 'ar_limit' => 2, 'ar_attachment_required' => true, 'ar_within_shift' => true, 'ar_after_process' => false]);
    $files = ['files' => ['regularisations/a.pdf']];

    expect(fn () => ($this->ar)('2026-09-26', $this->self))->toThrow(RuntimeException::class, 'future date')
        ->and(fn () => ($this->ar)('2026-09-17', $this->self))->toThrow(RuntimeException::class, 'within 3 days')
        ->and(fn () => ($this->ar)('2026-09-17', $this->manager))->toThrow(RuntimeException::class, 'at least one punch') // the manager's 10 days reach it
        ->and(fn () => ($this->ar)('2026-09-20', $this->manager))->toThrow(RuntimeException::class, 'on a weekly off')
        ->and(fn () => ($this->ar)('2026-09-24', $this->self))->toThrow(RuntimeException::class, 'at least one punch');

    foreach (['2026-09-22', '2026-09-23', '2026-09-24'] as $day) {
        punch($this->employee, "{$day} 09:05:00", 'in');
    }
    expect(fn () => ($this->ar)('2026-09-24', $this->self, ['in' => '2026-09-24 09:05', 'out' => '2026-09-24 18:00']))->toThrow(RuntimeException::class, 'Attach a document')
        ->and(fn () => ($this->ar)('2026-09-24', $this->self, $files + ['in' => '2026-09-24 06:00', 'out' => '2026-09-24 18:00']))->toThrow(RuntimeException::class, 'within the shift (09:00–18:00)');

    $ok = ($this->ar)('2026-09-24', $this->self, $files + ['in' => '2026-09-24 09:05', 'out' => '2026-09-24 18:00']);
    ($this->ar)('2026-09-23', $this->self, $files);
    expect($ok->attachments)->toBe(['regularisations/a.pdf'])
        ->and(fn () => ($this->ar)('2026-09-22', $this->self, $files))->toThrow(RuntimeException::class, 'at most 2 regularisation request(s) a month');

    AttendanceRegularisation::query()->delete();
    app(AttendanceProcessor::class)->process($this->employee, '2026-09-22');
    AttendanceRecord::query()->whereDate('date', '2026-09-22')->update(['is_locked' => true]);
    app(SettingsRepository::class)->set('attendance.allow_corrections_after_lock', true, 'test');
    expect(fn () => ($this->ar)('2026-09-22', $this->self, $files))->toThrow(RuntimeException::class, 'once attendance is processed');
});

it('applies HROne\'s on-duty rules: types, attachment, future dates, off days, weekly limits that managers may pass', function () {
    rulesPolicy('time_office', ['on_duty_types' => ['Client visit', 'Training'], 'on_duty_future_allowed' => false, 'on_duty_not_on' => ['weekly_off', 'holiday'],
        'on_duty_max_per_week' => 1, 'on_duty_limits_bind_managers' => false, 'on_duty_attachment_required' => true, 'claim_window_days' => 2, 'on_duty_manager_backdated_days' => 20]);
    $day = fn (string $date, array $extra = []) => ['from_date' => $date, 'session' => 'full', 'category' => 'Client visit', 'attachments' => ['time-office/a.pdf']] + $extra;

    expect(fn () => ($this->to)('on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], $this->self))->toThrow(RuntimeException::class, 'Choose the on-duty type: Client visit, Training')
        ->and(fn () => ($this->to)('on_duty', ['from_date' => '2026-09-24', 'session' => 'full', 'category' => 'Training'], $this->self))->toThrow(RuntimeException::class, 'Attach a document to the on-duty request')
        ->and(fn () => ($this->to)('on_duty', $day('2026-09-28'), $this->self))->toThrow(RuntimeException::class, 'future date');

    $first = ($this->to)('on_duty', $day('2026-09-24'), $this->self);
    expect($first->category)->toBe('Client visit')->and($first->attachments)->toBe(['time-office/a.pdf'])
        ->and(fn () => ($this->to)('on_duty', $day('2026-09-23'), $this->self))->toThrow(RuntimeException::class, 'at most 1 on duty request(s) a week')
        ->and(fn () => ($this->to)('on_duty', $day('2026-09-20'), $this->manager))->toThrow(RuntimeException::class, 'on a weekly off')
        ->and(fn () => ($this->to)('on_duty', $day('2026-09-10'), $this->self))->toThrow(RuntimeException::class, 'up to 2 day(s) back');

    expect(($this->to)('on_duty', $day('2026-09-22'), $this->manager)->status)->toBe('pending'); // limits do not bind the manager, whose window is 20 days
});

it('keeps shift changes to the allowed shifts and to backdated / forward-dated days', function () {
    rulesPolicy('time_office', ['shift_change_shifts' => ['NGT'], 'shift_change_backdated_days' => 3, 'shift_change_future_days' => 10]);

    expect(fn () => ($this->to)('shift_change', ['from_date' => '2026-09-28', 'shift_id' => $this->shift->id], $this->self))->toThrow(RuntimeException::class, 'cannot be requested under your policy')
        ->and(fn () => ($this->to)('shift_change', ['from_date' => '2026-10-20', 'shift_id' => $this->night->id], $this->self))->toThrow(RuntimeException::class, 'at most 10 day(s) ahead')
        ->and(fn () => ($this->to)('shift_change', ['from_date' => '2026-09-18', 'shift_id' => $this->night->id], $this->self))->toThrow(RuntimeException::class, 'up to 3 day(s) back')
        ->and(($this->to)('shift_change', ['from_date' => '2026-09-23', 'to_date' => '2026-09-23', 'shift_id' => $this->night->id], $this->self)->status)->toBe('pending');
});

it('swaps a weekly off for another day off through a week-off change, and undoes it on cancellation', function () {
    expect(fn () => ($this->to)('week_off_change', ['from_date' => '2026-09-26', 'swap_date' => '2026-09-29'], $this->self))->toThrow(RuntimeException::class, 'not allowed');
    rulesPolicy('time_office', ['week_off_change_allowed' => true, 'week_off_change_future_days' => 14, 'week_off_change_max_per_month' => 2]);

    expect(fn () => ($this->to)('week_off_change', ['from_date' => '2026-09-28', 'swap_date' => '2026-09-29'], $this->self))->toThrow(RuntimeException::class, 'not one of your weekly offs')
        ->and(fn () => ($this->to)('week_off_change', ['from_date' => '2026-09-26', 'swap_date' => '2026-09-27'], $this->self))->toThrow(RuntimeException::class, 'already a day off')
        ->and(fn () => ($this->to)('week_off_change', ['from_date' => '2026-10-17', 'swap_date' => '2026-10-20'], $this->self))->toThrow(RuntimeException::class, 'at most 14 day(s) ahead');

    $swap = ($this->to)('week_off_change', ['from_date' => '2026-09-26', 'swap_date' => '2026-09-29'], $this->self);
    app(TimeOfficeRequests::class)->approve($swap, 'ok', $this->hr);
    $override = fn (string $date) => ShiftOverride::query()->where('employee_id', $this->employee->id)->whereDate('date', $date)->first();
    expect($override('2026-09-26')->shift_id)->toBe($this->shift->id)->and($override('2026-09-29')->shift_id)->toBeNull();

    $this->travelTo('2026-09-30 10:00:00');
    expect(app(AttendanceProcessor::class)->process($this->employee, '2026-09-29')->status)->toBe('weekly_off');
    app(TimeOfficeRequests::class)->cancel($swap->refresh(), 'Plans changed', $this->hr);
    if ($swap->refresh()->status === 'cancel_requested') {
        app(TimeOfficeRequests::class)->approveCancellation($swap, 'ok', $this->hr);
    }
    expect($override('2026-09-26'))->toBeNull()->and($override('2026-09-29'))->toBeNull()
        ->and(app(AttendanceProcessor::class)->process($this->employee, '2026-09-29')->status)->not->toBe('weekly_off');
});

it('refuses requests for a day that already earned overtime when the organisation says so, and keeps old policy keys', function () {
    punch($this->employee, '2026-09-24 09:00:00', 'in');
    app(AttendanceProcessor::class)->process($this->employee, '2026-09-24');
    AttendanceRecord::query()->whereDate('date', '2026-09-24')->update(['overtime_status' => 'approved']);

    expect(($this->ar)('2026-09-24', $this->self)->status)->toBe('pending'); // allowed by default
    AttendanceRegularisation::query()->delete();
    app(SettingsRepository::class)->set('attendance.requests_after_benefits', false, 'test');
    expect(fn () => ($this->ar)('2026-09-24', $this->self))->toThrow(RuntimeException::class, 'already earned overtime or a comp-off');

    $keys = array_column(PolicySettingsSchema::flatFields('time_office'), 'key');
    expect($keys)->toContain('on_duty_allowed', 'claim_window_days', 'comp_off_validity_days', 'shift_change_max_per_month', 'on_duty_types', 'week_off_change_allowed')
        ->and(array_column(PolicySettingsSchema::flatFields('attendance'), 'key'))->toContain('grace_minutes', 'regularisation_window_days', 'ar_limit', 'manager_approval_required');
});
