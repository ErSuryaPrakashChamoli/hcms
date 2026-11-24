<?php

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Payroll\Services\PayrollReadiness;
use App\Domain\Payroll\Services\PayrollRunChecklist;
use App\Domain\Platform\Services\SettingsRepository;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| Master-relationships R1: with no work schedule, roster or shift, the processor records every un-punched day absent
| (weekends too, since nothing says which days are off), and payroll would take loss of pay for them. The pre-payroll
| checklist blocks the run on those days, per person and date, and tells HR what to do. Holidays and known weekly
| offs are never counted, real absences of people with a schedule are not either, and the item does not apply where
| payroll takes no loss of pay from attendance.
*/

beforeEach(function () {
    $this->travelTo('2026-10-03 09:00:00');
    syncComplianceRules();
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->preparer = tenantUser($this->tenant, ['payroll.*', 'employee.*', 'attendance.*']);
    $this->actingAs($this->preparer);
    $this->company = payrollCompany();
    $this->checklist = app(PayrollRunChecklist::class);
    $this->unscheduled = salariedEmployee(600000);
    $this->scheduled = salariedEmployee(480000);
    $this->schedule = weeklySchedule(generalShift());
    assignSchedule($this->scheduled, $this->schedule, '2026-01-05');
    $calendar = HolidayCalendar::create(['name' => 'National', 'code' => 'NAT']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-17', 'name' => 'Founders Day']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);
    $this->process = function () {
        foreach ([$this->unscheduled, $this->scheduled] as $employee) {
            app(AttendanceProcessor::class)->processRange($employee, '2026-09-01', '2026-09-30');
        }
    };
    $this->item = fn () => collect($this->checklist->items($this->company->id, 2026, 9))->firstWhere('key', 'no_shift');
});

it('blocks the run on days recorded absent only because no shift applies, naming the person and the dates', function () {
    ($this->process)();

    // 29 un-punched days with no shift (the holiday is a holiday); the scheduled colleague's absences are real ones.
    expect(AttendanceRecord::query()->where('employee_id', $this->unscheduled->id)->whereDate('date', '2026-09-17')->value('status'))->toBe('holiday')
        ->and(AttendanceRecord::query()->where('employee_id', $this->scheduled->id)->whereDate('date', '2026-09-19')->value('status'))->toBe('weekly_off');

    $check = app(PayrollReadiness::class)->evaluate($this->company->id, activeOnly: false, payMonth: [2026, 9])['checks']['no_shift'];
    expect($check['state'])->toBe('attention')->and($check['rows'])->toHaveCount(1)
        ->and($check['rows'][0])->toMatchArray(['employee_code' => $this->unscheduled->employee_code, 'days' => 29])
        ->and($check['rows'][0]['dates'])->toStartWith('1–16 Sep, 18–30 Sep')
        ->and($check['rows'][0]['detail'])->toContain('No shift on 29 days, recorded absent')->toContain('Assign a work schedule, roster or shift, then reprocess attendance');

    $item = ($this->item)();
    expect($item['status'])->toBe(PayrollRunChecklist::BLOCKED)->and($item['kind'])->toBe('block')->and($item['count'])->toBe(1)
        ->and($item['rows'][0]['code'])->toBe($this->unscheduled->employee_code)
        ->and($item['rows'][0]['detail'])->toContain('1–16 Sep, 18–30 Sep')
        ->and($this->checklist->blockers($this->company->id, 2026, 9))->toContain('Days with no shift: 1 to clear.')
        ->and(fn () => $this->checklist->tick($this->company->id, 2026, 9, 'no_shift', $this->preparer))->toThrow(RuntimeException::class, 'clear the 1 first');
});

it('says to reprocess once a schedule applies, and clears when attendance is reprocessed', function () {
    ($this->process)();
    assignSchedule($this->unscheduled, $this->schedule, '2026-09-01');

    expect(app(PayrollReadiness::class)->evaluate($this->company->id, activeOnly: false, payMonth: [2026, 9])['checks']['no_shift']['rows'][0]['detail'])
        ->toContain('A schedule applies now: reprocess attendance for these dates');

    ($this->process)();
    expect(($this->item)()['status'])->toBe(PayrollRunChecklist::PASS)
        ->and(collect($this->checklist->blockers($this->company->id, 2026, 9))->filter(fn (string $r) => str_starts_with($r, 'Days with no shift')))->toBeEmpty()
        // The weekends are weekly offs now; the un-punched weekdays are real absences of a scheduled person.
        ->and(AttendanceRecord::query()->where('employee_id', $this->unscheduled->id)->whereDate('date', '2026-09-19')->value('status'))->toBe('weekly_off');
});

it('does not apply where payroll takes no loss of pay from attendance', function () {
    ($this->process)();
    app(SettingsRepository::class)->set('payroll.lop_from_attendance', false);

    expect(($this->item)()['status'])->toBe(PayrollRunChecklist::NOT_APPLICABLE)
        ->and(app(PayrollReadiness::class)->evaluate($this->company->id, activeOnly: false, payMonth: [2026, 9])['checks']['no_shift']['state'])->toBe('not_run');
});

it('passes when everyone has a shift', function () {
    assignSchedule($this->unscheduled, $this->schedule, '2026-01-05');
    ($this->process)();

    expect(($this->item)()['status'])->toBe(PayrollRunChecklist::PASS)->and(($this->item)()['count'])->toBe(0);
});
