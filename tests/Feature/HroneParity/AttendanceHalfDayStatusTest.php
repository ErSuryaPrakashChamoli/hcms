<?php

use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Attendance\Imports\DayOverrideImports;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Services\AttendanceOverrides;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PaidDaysRegister;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Attendance\Support\PaidDayTally;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\BulkOperations\ChangeAttendanceStatusOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AttendanceStatusChange;
use App\Filament\Pages\TimeOfficeDesk;
use App\Filament\Resources\AttendanceOverrides\Pages\ListAttendanceOverrides;
use App\Filament\Resources\AttendanceRecords\Pages\ListAttendanceRecords;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| HROne parity TX-6 (matrix 24 §24.5.1 #4, §24.5.2 #4, §24.5.3 #6, §24.8 #9, the HR part of §24.13 #3): a status per half on
| HR day overrides (attendance_overrides.first_half_status / second_half_status; both null = the whole-day behaviour as
| before), "Change attendance status" for chosen employees and a date range with per-employee / per-day refusals, the row
| "Edit" on attendance records, the day-override upload's half columns, and paid days that agree with the summary.
| Today is Wednesday 14 Oct 2026; the schedule is Monday to Friday, General 09:00–18:00.
*/

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'Acme India']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi', 'code' => 'DEL']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai', 'code' => 'MUM']);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->asha = tx6Hire('Asha', $this->delhi, 'EMP1');
    $this->ravi = tx6Hire('Ravi', $this->mumbai, 'EMP2');
    assignSchedule($this->asha, $this->schedule);
    assignSchedule($this->ravi, $this->schedule);
    $this->overrides = app(AttendanceOverrides::class);
});

function tx6Hire(string $first, Location $location, string $code, string $joining = '2025-01-01'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joining, 'employee_code' => $code],
        ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

function tx6Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function tx6Day(Employee $employee, string $date): ?AttendanceRecord
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

function tx6Override(Employee $employee, string $date): ?AttendanceOverride
{
    return AttendanceOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

function tx6Worked(Employee $employee, string $date, string $in = '09:00', string $out = '18:00'): AttendanceRecord
{
    punch($employee, "{$date} {$in}:00", 'in');
    punch($employee, "{$date} {$out}:00", 'out');

    return app(AttendanceProcessor::class)->process($employee, $date);
}

function tx6Stage(string $csv): EmployeeImport
{
    $service = app(DayOverrideImports::class);
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = $service->register($path, 'overrides.csv', auth()->user());
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping);

    return $service->validate($import->refresh());
}

function tx6Run(EmployeeImport $import): EmployeeImport
{
    $service = app(DayOverrideImports::class);
    $service->approve($import, auth()->user(), 'Corrections');

    return $service->run($import->refresh(), auth()->user());
}

// ---- Defaults ----------------------------------------------------------------------------------------------------------

it('keeps every whole-day and worked-minutes override exactly as before when no half is given', function () {
    // A late, short day overridden by worked minutes only: the shift's thresholds decide, lateness stays.
    tx6Worked($this->asha, '2026-10-12', '09:40', '15:00');
    $minutes = $this->overrides->set($this->asha, '2026-10-12', null, 300, 'Biometric fault', $this->hr);
    expect($minutes->status)->toBe('half_day')->and($minutes->worked_minutes)->toBe(300)->and($minutes->late_minutes)->toBe(40)
        ->and($minutes->exceptions)->toContain('short_hours')->toContain('late');

    // A whole-day status wins outright: no exceptions, no lateness.
    tx6Worked($this->asha, '2026-10-13', '09:40', '15:00');
    $status = $this->overrides->set($this->asha, '2026-10-13', 'present', null, 'Client visit', $this->hr);
    expect($status->status)->toBe('present')->and($status->late_minutes)->toBe(0)->and($status->early_leave_minutes)->toBe(0)->and($status->exceptions)->toBeNull();

    // The calculation basis carries exactly the earlier keys; the payroll-facing day and the summary have no new key.
    expect(array_keys($status->calculation_basis))->toBe(['engine', 'schedule_id', 'shift_id', 'timezone', 'attendance_policy_version_id', 'overtime_policy_version_id',
        'holiday_id', 'grace_in', 'grace_out', 'unpaid_break_minutes', 'full_day_minutes', 'half_day_minutes', 'punch_ids', 'regularisation_ids', 'leave_request_id',
        'window', 'attendance_override_id']);
    $days = app(AttendanceOutput::class)->forEmployee($this->asha, '2026-10-12', '2026-10-13');
    expect($days->flatMap(fn (AttendanceDay $d) => array_keys($d->adjustments))->all())->not->toContain('half_statuses')
        ->and(array_keys($days->first()->toArray()))->toBe(['employee_id', 'date', 'status', 'scheduled_minutes', 'worked_minutes', 'break_minutes', 'late_minutes', 'early_minutes',
            'overtime_minutes', 'approved_overtime_minutes', 'overtime_status', 'is_paid_day', 'is_half_day', 'is_regularised', 'is_locked', 'calculation_version', 'adjustments', 'penalty_days'])
        ->and(app(AttendanceOutput::class)->summary($this->asha, '2026-10-12', '2026-10-13'))->toMatchArray(['days' => 2, 'paid_days' => 1.5, 'half_days' => 1, 'absent_days' => 0]);

    // The overrides store no half, their audit records no half column, and an override row written the earlier way (no
    // half columns at all) gives the very same day.
    expect(tx6Override($this->asha, '2026-10-13')->getAttributes())->toMatchArray(['first_half_status' => null, 'second_half_status' => null])
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendanceOverride::class)->get()
            ->flatMap(fn (AuditEvent $e) => $e->fieldChanges()->pluck('field'))->all())->not->toContain('first_half_status')->not->toContain('second_half_status');
    tx6Worked($this->ravi, '2026-10-13', '09:40', '15:00');
    AttendanceOverride::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-13', 'status' => 'present', 'reason' => 'Client visit']);
    $earlier = app(AttendanceProcessor::class)->process($this->ravi, '2026-10-13');
    $same = fn (AttendanceRecord $r) => $r->only(['status', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'exceptions', 'is_half_day_leave', 'scheduled_minutes', 'break_minutes'])
        + ['basis' => array_keys($r->calculation_basis)];
    expect($same($earlier))->toEqual($same($status));
    expect(PaidDayTally::fromDays($days))->toMatchArray(['present' => 1.5, 'absent' => 0.5, 'paid_days' => 1.5, 'half_days' => 1]);
});

// ---- Half statuses ------------------------------------------------------------------------------------------------------

it('works the day out from each combination of halves, like a split mark of the monthly upload', function () {
    $combinations = [
        '2026-10-01' => ['present', 'absent', 'half_day'],
        '2026-10-02' => ['absent', 'present', 'half_day'],
        '2026-10-05' => ['weekly_off', 'absent', 'half_day'],
        '2026-10-06' => ['present', 'weekly_off', 'present'],
        '2026-10-07' => ['wfh', 'present', 'present'],
        '2026-10-08' => ['on_duty', 'holiday', 'on_duty'],
        '2026-10-09' => ['holiday', 'weekly_off', 'holiday'],
        '2026-10-10' => ['present', 'absent', 'half_day'], // a Saturday (weekly off) set by half
        '2026-10-12' => ['field_duty', 'absent', 'half_day'],
    ];
    tx6Worked($this->asha, '2026-10-01', '09:50', '13:00'); // late and short: HR's halves win outright
    foreach ($combinations as $date => [$first, $second, $expected]) {
        $record = $this->overrides->set($this->asha, $date, null, null, 'Corrected by half', $this->hr, $first, $second);
        expect($record->status)->toBe($expected, $date)->and($record->late_minutes)->toBe(0)->and($record->exceptions)->toBeNull()
            ->and($record->calculation_basis['half_statuses'])->toBe(['first_half' => $first, 'second_half' => $second])
            ->and(tx6Override($this->asha, $date))->first_half_status->toBe($first)->second_half_status->toBe($second)->status->toBeNull();
    }
    expect(app(AttendanceOutput::class)->forEmployee($this->asha, '2026-10-01', '2026-10-01')->first()->adjustments['half_statuses'])->toBe(['first_half' => 'present', 'second_half' => 'absent'])
        ->and(tx6Override($this->asha, '2026-10-01')->halvesLabel())->toBe('Present / Absent');

    // Two equal halves are the whole-day status; one half alone, a half with a whole-day status, or a "half day" half is refused.
    $equal = $this->overrides->set($this->asha, '2026-10-13', null, null, 'Both halves', $this->hr, 'absent', 'absent');
    expect($equal->status)->toBe('absent')->and(tx6Override($this->asha, '2026-10-13'))->status->toBe('absent')->first_half_status->toBeNull()
        ->and($equal->calculation_basis)->not->toHaveKey('half_statuses');
    foreach ([[null, 'present', null, 'Give a status for both halves of the day.'], ['present', 'present', 'absent', 'Choose a status for the whole day or for each half, not both.'],
        [null, 'half_day', 'absent', 'Unknown attendance status [half_day] for a half of the day.'], [null, 'leave', 'present', 'Unknown attendance status [leave] for a half of the day.']] as [$status, $first, $second, $message]) {
        expect(fn () => $this->overrides->set($this->ravi, '2026-10-13', $status, null, 'Try', $this->hr, $first, $second))->toThrow(RuntimeException::class, $message);
    }

    // Setting the day again without halves clears them; clearing the override recalculates from the punches.
    $whole = $this->overrides->set($this->asha, '2026-10-01', 'present', null, 'Whole day after all', $this->hr);
    expect($whole->status)->toBe('present')->and(tx6Override($this->asha, '2026-10-01'))->first_half_status->toBeNull()->second_half_status->toBeNull()
        ->and($whole->calculation_basis)->not->toHaveKey('half_statuses');
});

it('leaves approved leave its half: full-day leave keeps the day, half-day leave keeps its half', function () {
    $type = LeaveType::query()->where('category', '!=', 'unpaid')->orderBy('id')->firstOrFail();
    $leave = fn (string $date, string $session) => LeaveRequest::create(['employee_id' => $this->asha->id, 'leave_type_id' => $type->id, 'from_date' => $date, 'to_date' => $date,
        'from_session' => $session, 'to_session' => $session, 'days' => $session === 'full' ? 1 : 0.5, 'dates' => [['date' => $date, 'days' => $session === 'full' ? 1 : 0.5, 'session' => $session]],
        'reason' => 'Family', 'status' => 'approved']);
    $leave('2026-10-06', 'full');
    $leave('2026-10-07', 'first_half');
    $leave('2026-10-08', 'second_half');
    $paid = app(LeaveDayResolver::class)->approvedLeaveOn($this->asha, now()->setDate(2026, 10, 7))->isPaid;

    $full = $this->overrides->set($this->asha, '2026-10-06', null, null, 'Try', $this->hr, 'present', 'absent');
    // First-half leave: the second half decides — present names the day, absent leaves it as leave.
    $worked = $this->overrides->set($this->asha, '2026-10-07', null, null, 'After lunch', $this->hr, 'absent', 'present');
    $away = $this->overrides->set($this->asha, '2026-10-08', null, null, 'Morning only', $this->hr, 'present', 'absent');
    expect($full->status)->toBe($paid ? 'leave' : 'unpaid_leave')->and($full->calculation_basis)->not->toHaveKey('half_statuses')
        ->and($worked->status)->toBe('present')->and($worked->is_half_day_leave)->toBeTrue()
        ->and($away->status)->toBe('present')->and($away->is_half_day_leave)->toBeTrue();
    // Second-half leave: the first half decides — absent there leaves the day as leave.
    $none = $this->overrides->set($this->asha, '2026-10-08', null, null, 'Morning absent too', $this->hr, 'absent', 'present');
    expect($none->status)->toBe($paid ? 'leave' : 'unpaid_leave')->and($none->is_half_day_leave)->toBeTrue();
    $gone = $this->overrides->set($this->asha, '2026-10-07', null, null, 'Afternoon absent', $this->hr, 'present', 'absent');
    expect($gone->status)->toBe($paid ? 'leave' : 'unpaid_leave')->and($gone->is_half_day_leave)->toBeTrue();
});

// ---- Paid days ----------------------------------------------------------------------------------------------------------

it('keeps the summary, the paid-day categories and the paid-days register in agreement on days set by half', function () {
    foreach (['2026-10-03', '2026-10-04', '2026-10-11'] as $weekend) {
        app(AttendanceProcessor::class)->process($this->asha, $weekend);
    }
    tx6Worked($this->asha, '2026-10-08');
    tx6Worked($this->asha, '2026-10-12');
    foreach (['2026-10-01' => ['present', 'absent'], '2026-10-02' => ['weekly_off', 'absent'], '2026-10-05' => ['present', 'weekly_off'], '2026-10-06' => ['holiday', 'present'],
        '2026-10-07' => ['holiday', 'weekly_off'], '2026-10-10' => ['present', 'absent'], '2026-10-13' => ['wfh', 'absent']] as $date => [$first, $second]) {
        $this->overrides->set($this->asha, $date, null, null, 'By half', $this->hr, $first, $second);
    }
    $this->overrides->set($this->asha, '2026-10-09', 'absent', null, 'Whole day', $this->hr);

    $output = app(AttendanceOutput::class);
    $summary = $output->summary($this->asha, '2026-10-01', '2026-10-13');
    $tally = PaidDayTally::fromDays($output->forEmployee($this->asha, '2026-10-01', '2026-10-13'));
    expect($summary)->toMatchArray(['days' => 13, 'paid_days' => 10.0, 'half_days' => 4, 'absent_days' => 1])
        // Each half in its own category: a weekly-off half counts as weekly off, not as present.
        ->and($tally)->toMatchArray(['present' => 4.5, 'weekly_off' => 4.5, 'holiday' => 1.0, 'absent' => 3.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'half_days' => 4, 'paid_days' => 10.0])
        ->and($tally['present'] + $tally['paid_leave'] + $tally['holiday'] + $tally['weekly_off'])->toBe($summary['paid_days'])
        ->and($tally['absent'] + $tally['unpaid_leave'])->toBe($summary['absent_days'] + 0.5 * $summary['half_days']);

    $register = app(PaidDaysRegister::class);
    $row = $register->figures([$this->asha->id], $this->company->id, $register->windows($this->company->id, 2026, 10))[$this->asha->id]['total'];
    $month = $output->summary($this->asha, '2026-10-01', '2026-10-31');
    expect($row['paid_days_processed'])->toBe($month['paid_days'])->and($row['half_days'])->toBe((float) $month['half_days'])
        ->and($row)->toMatchArray(['present' => 4.5, 'weekly_off' => 4.5, 'holiday' => 1.0, 'absent' => 3.0, 'paid_days' => 10.0]);
    // The day's paid quantity (arrears read it) agrees with the summary's reading of each day set by half.
    foreach ($output->forEmployee($this->asha, '2026-10-01', '2026-10-13') as $day) {
        $record = tx6Day($this->asha, $day->date);
        expect(AttendanceDay::paidQuantity($record->status, (bool) $record->is_half_day_leave, $record->exceptions ?? []))
            ->toBe(($day->isPaidDay ? 1.0 : 0.0) - ($day->isHalfDay ? 0.5 : 0.0), $day->date);
    }
});

// ---- Change attendance status for a range ---------------------------------------------------------------------------

it('changes a range for several employees and lists each refused employee or day with the reason', function () {
    $result = $this->overrides->setRange([$this->asha, $this->ravi], '2026-10-12', '2026-10-13', null, 'present', 'absent', 'Half days after the outage', $this->hr);
    expect($result['days'])->toBe(4)->and($result['refused'])->toBe([])
        ->and(collect($result['done'])->pluck('message', 'employee_id')->all())->toBe([
            $this->asha->id => '2 day(s) set to first half Present, second half Absent.',
            $this->ravi->id => '2 day(s) set to first half Present, second half Absent.',
        ])
        ->and(tx6Day($this->ravi, '2026-10-13')->status)->toBe('half_day')
        // One audited operation for the whole change.
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendanceOverride::class)->whereNotNull('operation_id')->distinct()->pluck('operation_id'))->toHaveCount(1);

    // Locked day (and accepted with corrections after lock: the locked day keeps its values, the difference is an arrear).
    AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-09', 'status' => 'present', 'shift_id' => $this->shift->id, 'is_locked' => true, 'processed_at' => now()]);
    $locked = $this->overrides->setRange([$this->ravi], '2026-10-08', '2026-10-09', 'absent', null, null, 'Absent', $this->hr);
    expect($locked['refused'])->toBe([['employee_id' => $this->ravi->id, 'label' => $this->ravi->auditLabel(), 'reason' => 'Locked (finalized days).', 'dates' => '09 Oct 2026']])
        ->and($locked['done'][0]['message'])->toBe('1 day(s) set to Absent; 1 day(s) refused.')->and(tx6Override($this->ravi, '2026-10-09'))->toBeNull();
    tx6Setting('attendance.allow_corrections_after_lock', true);
    expect($this->overrides->setRange([$this->ravi], '2026-10-09', '2026-10-09', null, 'present', 'absent', 'Left at noon', $this->hr)['refused'])->toBe([])
        ->and(tx6Day($this->ravi, '2026-10-09')->status)->toBe('present')
        ->and((float) AttendanceArrear::query()->where('employee_id', $this->ravi->id)->whereDate('date', '2026-10-09')->value('delta_days'))->toBe(-0.5);
    tx6Setting('attendance.allow_corrections_after_lock', false);

    // Closed attendance month (TX-3 assertOpen): refused while month close is on, unless corrections after lock are allowed.
    tx6Setting('attendance.month_close.enabled', true);
    AttendanceMonthClose::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 9, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'closed']);
    $closed = $this->overrides->setRange([$this->asha], '2026-09-29', '2026-10-01', 'present', null, null, 'Present', $this->hr);
    expect($closed['refused'])->toBe([['employee_id' => $this->asha->id, 'label' => $this->asha->auditLabel(), 'reason' => 'In a closed attendance month.', 'dates' => '29 Sep – 30 Sep 2026']])
        ->and($closed['days'])->toBe(1)->and(tx6Override($this->asha, '2026-09-30'))->toBeNull();
    tx6Setting('attendance.allow_corrections_after_lock', true);
    expect($this->overrides->setRange([$this->asha], '2026-09-30', '2026-09-30', 'present', null, null, 'Present', $this->hr)['refused'])->toBe([]);
    tx6Setting('attendance.allow_corrections_after_lock', false);
    tx6Setting('attendance.month_close.enabled', false);

    // Not employed that day: before joining and after leaving (only the employed days change); not yet joined at all.
    $joiner = forceLifecycle(tx6Hire('Meera', $this->delhi, 'EMP7', '2026-10-07'), LifecycleState::Probation);
    $gone = forceLifecycle(tx6Hire('Gopal', $this->delhi, 'EMP6', '2024-01-01'), LifecycleState::Exited, ['exit_date' => '2026-10-06']);
    $future = tx6Hire('Kiran', $this->delhi, 'EMP3', '2026-11-02');
    $people = $this->overrides->setRange([$joiner, $gone, $future], '2026-10-05', '2026-10-08', 'present', null, null, 'Present', $this->hr);
    expect(collect($people['refused'])->map(fn (array $r) => [$r['employee_id'], $r['dates'], $r['reason']])->all())->toBe([
        [$joiner->id, '05 Oct – 06 Oct 2026', 'Not employed on this day (joins 07 Oct 2026).'],
        [$gone->id, '07 Oct – 08 Oct 2026', 'Not employed on this day (left 06 Oct 2026).'],
        [$future->id, null, 'Not employed: the employee is preboarding.'],
    ])->and(collect($people['done'])->pluck('message', 'employee_id')->all())->toBe([
        $joiner->id => '2 day(s) set to Present; 2 day(s) refused.',
        $gone->id => '2 day(s) set to Present; 2 day(s) refused.',
    ])->and(tx6Override($joiner, '2026-10-06'))->toBeNull()->and(tx6Override($gone, '2026-10-06'))->not->toBeNull();

    // One's own attendance, and the range and inputs themselves.
    $self = tenantUser($this->tenant, ['attendance.view', 'attendance.override']);
    Employee::query()->whereKey($this->ravi->id)->update(['user_id' => $self->id]);
    $own = $this->overrides->setRange([$this->ravi->refresh()], '2026-10-12', '2026-10-12', 'present', null, null, 'Mine', $self);
    expect($own['refused'][0]['reason'])->toBe('You cannot change your own attendance.')->and($own['done'])->toBe([]);
    foreach ([['2026-10-14', '2026-10-15', 'A day can be changed once it has started: choose days up to today.'], ['2026-09-01', '2026-10-02', 'Change at most 31 days at a time.'],
        ['2026-10-12', '2026-10-11', 'The last day must not be before the first day.']] as [$from, $to, $message]) {
        expect(fn () => $this->overrides->setRange([$this->asha], $from, $to, 'present', null, null, 'Try', $this->hr))->toThrow(RuntimeException::class, $message);
    }
    expect(fn () => $this->overrides->setRange([$this->asha], '2026-10-12', '2026-10-12', null, null, null, 'Try', $this->hr))->toThrow(RuntimeException::class, 'Set a status, worked minutes, or both.')
        ->and(fn () => $this->overrides->setRange([$this->asha], '2026-10-12', '2026-10-12', 'present', null, null, ' ', $this->hr))->toThrow(RuntimeException::class, 'Give the reason for the change.')
        ->and(fn () => $this->overrides->setRange([$this->asha], '2026-10-12', '2026-10-12', 'present', null, null, 'Try', tenantUser($this->tenant, ['attendance.view'])))
        ->toThrow(RuntimeException::class, 'You may not change attendance status.');
});

it('notes the days approved full-day leave keeps', function () {
    $type = LeaveType::query()->orderBy('id')->firstOrFail();
    LeaveRequest::create(['employee_id' => $this->asha->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-13', 'to_date' => '2026-10-13', 'days' => 1,
        'dates' => [['date' => '2026-10-13', 'days' => 1, 'session' => 'full']], 'reason' => 'Family', 'status' => 'approved']);
    $result = $this->overrides->setRange([$this->asha], '2026-10-12', '2026-10-13', null, 'absent', 'present', 'Came after lunch', $this->hr);
    expect($result['done'][0]['message'])->toBe('2 day(s) set to first half Absent, second half Present; 1 day(s) of approved full-day leave keep the leave.')
        ->and(tx6Day($this->asha, '2026-10-13')->status)->toBeIn(['leave', 'unpaid_leave']);
});

// ---- The page, the bulk run and access ------------------------------------------------------------------------------

it('changes the selected employees\' days from the page and shows each result', function () {
    Livewire::test(AttendanceStatusChange::class)->assertOk()->assertCanSeeTableRecords([$this->asha, $this->ravi])
        ->callTableBulkAction('changeStatus', [$this->asha, $this->ravi], data: ['from' => '2026-10-12', 'to' => '2026-10-13', 'mode' => 'halves',
            'first_half' => 'present', 'second_half' => 'absent', 'reason' => 'Half days after the outage'])
        ->assertHasNoTableBulkActionErrors()
        ->assertSet('result.days', 4)
        ->assertSee('2 day(s) set to first half Present, second half Absent.');
    expect(tx6Day($this->asha, '2026-10-12')->status)->toBe('half_day')->and(tx6Override($this->ravi, '2026-10-13')->first_half_status)->toBe('present');

    // The whole day.
    Livewire::test(AttendanceStatusChange::class)
        ->callTableBulkAction('changeStatus', [$this->asha], data: ['from' => '2026-10-12', 'to' => '2026-10-12', 'mode' => 'whole', 'status' => 'wfh', 'reason' => 'Worked from home'])
        ->assertHasNoTableBulkActionErrors();
    expect(tx6Day($this->asha, '2026-10-12')->status)->toBe('wfh')->and(tx6Override($this->asha, '2026-10-12')->first_half_status)->toBeNull();
});

it('runs large selections as a WO-1 bulk run with the typed count, each employee done or refused on their own', function () {
    config(['peopleos.bulk.confirm_over' => 1]);
    AttendanceRecord::create(['employee_id' => $this->asha->id, 'date' => '2026-10-12', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);

    Livewire::test(AttendanceStatusChange::class)
        ->callTableBulkAction('changeStatus', [$this->asha, $this->ravi], data: ['from' => '2026-10-12', 'to' => '2026-10-13', 'mode' => 'halves',
            'first_half' => 'absent', 'second_half' => 'present', 'reason' => 'Late start', 'confirm_count' => '2'])
        ->assertHasNoTableBulkActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->operation)->toBe(ChangeAttendanceStatusOperation::KEY)->and($run->register)->toBe('attendance_status')->and($run->status)->toBe('completed')
        ->and($items[$this->asha->id]->outcome)->toBe('done')
        ->and($items[$this->asha->id]->message)->toBe('1 day(s) set to first half Absent, second half Present; 1 day(s) refused. Refused — 12 Oct 2026: Locked (finalized days).')
        ->and($items[$this->ravi->id]->outcome)->toBe('done')->and(tx6Day($this->ravi, '2026-10-13')->status)->toBe('half_day')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendanceOverride::class)->pluck('operation_id')->unique()->all())->toBe([$run->operation_id]);

    // Only locked days in the range: the employee is refused with them.
    $all = app(BulkOperations::class)->get(ChangeAttendanceStatusOperation::KEY);
    expect(fn () => $all->handle($this->asha, ['from' => '2026-10-12', 'to' => '2026-10-12', 'status' => 'absent', 'first_half' => null, 'second_half' => null, 'reason' => 'Try'], $this->hr))
        ->toThrow(RuntimeException::class, '12 Oct 2026: Locked (finalized days).');

    // Without bulk.run a large selection does not start; a direct one still runs with attendance.override alone.
    $this->actingAs(tenantUser($this->tenant, ['attendance.view', 'attendance.override']));
    Livewire::test(AttendanceStatusChange::class)
        ->callTableBulkAction('changeStatus', [$this->asha, $this->ravi], data: ['from' => '2026-10-09', 'to' => '2026-10-09', 'mode' => 'whole', 'status' => 'present', 'reason' => 'Try', 'confirm_count' => '2'])
        ->assertNotified('Not started');
    expect(tx6Override($this->ravi, '2026-10-09'))->toBeNull();
    config(['peopleos.bulk.confirm_over' => 25]);
    Livewire::test(AttendanceStatusChange::class)
        ->callTableBulkAction('changeStatus', [$this->asha, $this->ravi], data: ['from' => '2026-10-09', 'to' => '2026-10-09', 'mode' => 'whole', 'status' => 'present', 'reason' => 'Present'])
        ->assertHasNoTableBulkActionErrors();
    expect(tx6Override($this->ravi, '2026-10-09')->status)->toBe('present');
});

it('opens the page and the row edit only inside the right and the access scope', function () {
    // attendance.view alone: no page, no edit.
    $viewer = tenantUser($this->tenant, ['attendance.view']);
    $this->actingAs($viewer);
    expect(AttendanceStatusChange::canAccess())->toBeFalse();
    $this->get(AttendanceStatusChange::getUrl())->assertForbidden();

    // attendance.override inside Delhi: only Delhi's people are listed and changed.
    $scoped = tenantUser($this->tenant, ['attendance.view', 'attendance.override']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);
    Livewire::test(AttendanceStatusChange::class)->assertOk()->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi]);
    $outside = $this->overrides->setRange([$this->ravi], '2026-10-12', '2026-10-12', 'present', null, null, 'Try', $scoped);
    expect($outside['refused'][0]['reason'])->toBe('Outside your access scope.')->and(tx6Override($this->ravi, '2026-10-12'))->toBeNull();
    $this->get(AttendanceStatusChange::getUrl())->assertOk()->assertSee('Change attendance status');
});

// ---- Row "Edit" -----------------------------------------------------------------------------------------------------------

it('edits one day from the attendance records with the same form', function () {
    $day = tx6Worked($this->asha, '2026-10-12', '09:00', '18:00');
    Livewire::test(ListAttendanceRecords::class)->assertTableActionVisible('editStatus', $day)
        ->callTableAction('editStatus', $day, data: ['mode' => 'halves', 'first_half' => 'absent', 'second_half' => 'present', 'reason' => 'Came after lunch'])
        ->assertHasNoTableActionErrors()->assertNotified('Day recalculated: Half day');
    expect($day->refresh()->status)->toBe('half_day')->and(tx6Override($this->asha, '2026-10-12'))->first_half_status->toBe('absent')->second_half_status->toBe('present')
        ->and(ChangeAttendanceStatusOperation::defaultsFor($day))->toBe(['mode' => 'halves', 'first_half' => 'absent', 'second_half' => 'present', 'worked_minutes' => null]);

    // The whole day with worked minutes, through the same form; the form opens with the day's override.
    Livewire::test(ListAttendanceRecords::class)
        ->callTableAction('editStatus', $day, data: ['mode' => 'whole', 'status' => null, 'worked_minutes' => 480, 'reason' => 'Full day after all'])
        ->assertHasNoTableActionErrors();
    expect($day->refresh()->status)->toBe('present')->and(tx6Override($this->asha, '2026-10-12'))->first_half_status->toBeNull()->worked_minutes->toBe(480);

    // Not offered on a locked day (unless corrections after lock are allowed), on one's own day, or without the right.
    $locked = AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-09', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);
    Livewire::test(ListAttendanceRecords::class)->assertTableActionHidden('editStatus', $locked);
    tx6Setting('attendance.allow_corrections_after_lock', true);
    Livewire::test(ListAttendanceRecords::class)->assertTableActionVisible('editStatus', $locked);
    $self = tenantUser($this->tenant, ['attendance.view', 'attendance.override']);
    Employee::query()->whereKey($this->asha->id)->update(['user_id' => $self->id]);
    $this->actingAs($self);
    Livewire::test(ListAttendanceRecords::class)->assertTableActionHidden('editStatus', $day)->assertTableActionVisible('editStatus', $locked);
    $this->actingAs(tenantUser($this->tenant, ['attendance.view']));
    Livewire::test(ListAttendanceRecords::class)->assertTableActionHidden('editStatus', $locked);
});

it('takes the half statuses in "Override a day" too, the whole day staying the default', function () {
    Livewire::test(ListAttendanceOverrides::class)
        ->callAction('override', data: ['employee_id' => $this->asha->id, 'date' => '2026-10-12', 'status' => 'present', 'reason' => 'Whole day'])
        ->assertHasNoActionErrors();
    expect(tx6Override($this->asha, '2026-10-12'))->status->toBe('present')->first_half_status->toBeNull();
    Livewire::test(ListAttendanceOverrides::class)
        ->callAction('override', data: ['employee_id' => $this->asha->id, 'date' => '2026-10-13', 'mode' => 'halves', 'first_half' => 'present', 'second_half' => 'absent', 'reason' => 'Left at noon'])
        ->assertHasNoActionErrors();
    expect(tx6Day($this->asha, '2026-10-13')->status)->toBe('half_day');
    Livewire::test(ListAttendanceOverrides::class)->assertSee('Present / Absent')->assertActionVisible('changeStatus');
});

// ---- Upload -------------------------------------------------------------------------------------------------------------

it('imports old day-override files as before and reads the new half columns', function () {
    $old = tx6Run(tx6Stage("Employee code,Date,Status,Reason\nEMP1,2026-10-12,present,Biometric fault\n"));
    expect($old->rows()->value('result'))->toBe('Day now Present')->and(tx6Override($this->asha, '2026-10-12'))->first_half_status->toBeNull()->status->toBe('present');

    $new = tx6Run(tx6Stage("Employee code,Date,Status,First half,Second half,Worked minutes,Reason\nEMP1,2026-10-13,,Present,absent,,Left at noon\nEMP2,2026-10-13,,on duty,on duty,,Client\n"));
    expect($new->rows()->orderBy('row_number')->pluck('result')->all())->toBe(['Day now Half day', 'Day now On duty'])
        ->and(tx6Override($this->asha, '2026-10-13'))->first_half_status->toBe('present')->second_half_status->toBe('absent')
        ->and(tx6Override($this->ravi, '2026-10-13'))->status->toBe('on_duty')->first_half_status->toBeNull();

    $bad = tx6Stage("Employee code,Date,Status,First half,Second half,Reason\nEMP1,2026-10-09,present,present,absent,Both\nEMP1,2026-10-08,,present,,One half\nEMP2,2026-10-08,,half day,absent,Half day half\n");
    expect($bad->rows()->orderBy('row_number')->get()->map(fn ($r) => $r->errors)->all())->toBe([
        ['Give a status for the whole day or for each half, not both.'],
        ['Give both halves (First half and Second half), or neither.'],
        ["First half 'half day' is not one of present, absent, weekly_off, holiday, wfh, on_duty, field_duty."],
    ]);
    expect(str_getcsv(strtok(app(DayOverrideImports::class)->template(), "\n")))->toBe(['Employee code', 'Date', 'Status', 'First half', 'Second half', 'Worked minutes', 'Reason']);
});

// ---- Reprocessing -----------------------------------------------------------------------------------------------------

it('keeps a day set by half through reprocessing and new punches, and recalculates it once the override goes', function () {
    tx6Worked($this->asha, '2026-10-12');
    $this->overrides->set($this->asha, '2026-10-12', null, null, 'Left at noon', $this->hr, 'present', 'absent');
    punch($this->asha, '2026-10-12 19:00:00', 'out');
    app(AttendanceProcessor::class)->processRange($this->asha, '2026-10-12', '2026-10-12');
    expect(tx6Day($this->asha, '2026-10-12')->status)->toBe('half_day');

    $this->overrides->clear(tx6Override($this->asha, '2026-10-12'), 'Recorded by mistake');
    expect(tx6Day($this->asha, '2026-10-12'))->status->toBe('present')->calculation_basis->not->toHaveKey('half_statuses');
});

// ---- Boundaries -----------------------------------------------------------------------------------------------------------

it('lists the page on the time office desk, the registers and the setup centre, with no new permission', function () {
    $labels = collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Change attendance status')
        ->and(collect(config('peopleos_setup.modules.time_office.links') ?? [])->pluck('target')->all())->toContain(AttendanceStatusChange::class)
        ->and(app(BulkOperations::class)->get(ChangeAttendanceStatusOperation::KEY)->permission())->toBe('attendance.override')
        ->and(config('peopleos.permission_splits')['attendance.override'] ?? null)->toBe('attendance.manage');
    Livewire::test(ListAttendanceRecords::class)->assertActionVisible('changeStatus');
});

it('keeps TX-6 attendance code free of payroll words and adds only the two columns', function () {
    $files = ['Domain/Attendance/Services/AttendanceOverrides.php', 'Domain/Attendance/Models/AttendanceOverride.php', 'Domain/Attendance/Imports/DayOverrideImports.php',
        'Domain/Attendance/Support/PaidDayTally.php', 'Domain/Experience/BulkOperations/ChangeAttendanceStatusOperation.php', 'Filament/Pages/AttendanceStatusChange.php'];
    foreach ($files as $file) {
        expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
    expect(Schema::hasColumns('attendance_overrides', ['first_half_status', 'second_half_status']))->toBeTrue();
});
