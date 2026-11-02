<?php

use App\Domain\Attendance\Contracts\LeaveOnDay;
use App\Domain\Attendance\Models\AttendanceArrear;
use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Models\AttendanceOverride;
use App\Domain\Attendance\Models\AttendancePunch;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\PunchCorrections;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\AttendanceLeaveOnDay;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AttendanceCalendar;
use App\Filament\Pages\PaidDays;
use App\Filament\Pages\RosterBoard;
use App\Filament\Resources\AttendancePunches\Pages\ListAttendancePunches;
use App\Filament\Resources\AttendanceRecords\Pages\ListAttendanceRecords;
use App\Http\Controllers\ParityFileController;
use App\Livewire\Attendance\DayDrawer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| HROne parity TX-7 (matrix 24 §24.5.1 #3, #6, #7; §24.5.2 #1, #3; §24.5.4 #5; §24.13 #7 HR part + team option; owner decision
| D-TX5): punches are excluded / restored with a reason (never deleted; processing skips an excluded punch and the day is
| recalculated), added by hand from the HR day drawer, and the drawer (punches, check-in evidence for the geo-access audience,
| leave with cancel through the LeaveOnDay contract, TX-6's override form, reprocess) opens from attendance records, the
| attendance calendar, the roster board and the paid-days breakdown. Managers change their team's punches only with
| `team.punch_edit` (off by default), never their own.
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
    $this->other = Company::factory()->create(['name' => 'Acme Gulf']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi', 'code' => 'DEL']);
    $this->dubai = Location::factory()->create(['company_id' => $this->other->id, 'name' => 'Dubai', 'code' => 'DXB']);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->asha = tx7Hire('Asha', $this->delhi, 'EMP1');
    $this->ravi = tx7Hire('Ravi', $this->delhi, 'EMP2');
    $this->omar = tx7Hire('Omar', $this->dubai, 'EMP3');
    foreach ([$this->asha, $this->ravi, $this->omar] as $employee) {
        assignSchedule($employee, $this->schedule);
    }
    $this->corrections = app(PunchCorrections::class);
});

function tx7Hire(string $first, Location $location, string $code, string $joining = '2025-01-01'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joining, 'employee_code' => $code],
        ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

function tx7Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function tx7Punch(Employee $employee, string $at, string $direction = 'auto', array $meta = [], string $source = 'web'): AttendancePunch
{
    return app(PunchIngestion::class)->record($employee, $at, $direction, $source, null, null, $meta);
}

function tx7Day(Employee $employee, string $date): ?AttendanceRecord
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

/** @return array<string, mixed> the calculated fields of a day (what processing writes) */
function tx7Fields(?AttendanceRecord $record): array
{
    $fields = collect($record?->getAttributes() ?? [])->only(['status', 'first_in', 'last_out', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'overtime_status', 'exceptions', 'calculation_basis', 'shift_id', 'is_locked'])->all();
    ksort($fields);

    return $fields;
}

function tx7Leave(Employee ...$employees): LeaveType
{
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'min_notice_days' => 0, 'negative_balance_limit' => 0, 'max_consecutive_days' => 0]], [], 'TX7_LEAVE');
    foreach ($employees as $employee) {
        forceLifecycle($employee, LifecycleState::Active);
        app(LeaveAccrual::class)->accrue($employee);
    }

    return LeaveType::query()->where('code', 'EL')->firstOrFail();
}

function tx7Manager(object $test, Employee ...$reports): array
{
    $user = tenantUser($test->tenant, ['attendance.view', 'attendance.approve', 'attendance.regularise', 'employee.view', 'leave.view', 'leave.approve']);
    $manager = tx7Hire('Meena', $test->delhi, 'MGR1');
    $manager->update(['user_id' => $user->id]);
    foreach ($reports as $report) {
        ReportingRelationship::create(['employee_id' => $report->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    }

    return [$user, $manager];
}

// ---- Defaults -------------------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default: the same punches counted, no exclusion columns written, the team option off, no drawer for employees', function () {
    expect(app(SettingsRepository::class)->get('team.punch_edit'))->toBeFalse()
        ->and(collect(config('peopleos_setup.modules.time_office.settings'))->pluck('key'))->toContain('team.punch_edit')
        ->and(Schema::hasColumns('attendance_punches', ['excluded_at', 'excluded_by', 'exclusion_reason', 'status_before_exclusion']))->toBeTrue();

    tx7Punch($this->asha, '2026-10-13 09:02:00', 'in');
    tx7Punch($this->asha, '2026-10-13 18:04:00', 'out');
    // A refused check-in stays out, as before.
    AttendancePunch::create(['employee_id' => $this->asha->id, 'punched_at' => '2026-10-13 12:00:00', 'direction' => 'auto', 'source' => 'web', 'source_type' => 'web',
        'fingerprint' => 'refused-1', 'received_at' => now(), 'processing_status' => 'rejected', 'processing_error' => 'Outside the window']);
    [$from, $to] = app(AttendanceProcessor::class)->windowFor($this->asha, now()->setDate(2026, 10, 13)->startOfDay());
    $earlier = AttendancePunch::query()->where('employee_id', $this->asha->id)->where('processing_status', '!=', 'rejected')->whereBetween('punched_at', [$from, $to])->orderBy('punched_at')->orderBy('id')->pluck('id')->all();
    expect(app(PunchIngestion::class)->punchesBetween($this->asha, $from, $to)->pluck('id')->all())->toBe($earlier)->toHaveCount(2)
        ->and(AttendancePunch::query()->whereNotNull('excluded_at')->orWhereNotNull('status_before_exclusion')->orWhereNotNull('exclusion_reason')->count())->toBe(0)
        ->and(tx7Day($this->asha, '2026-10-13')->status)->toBe('present');

    // The employee's own list and calendar: no drawer, no new row actions.
    $user = tenantUser($this->tenant, ['attendance.regularise']);
    $this->asha->update(['user_id' => $user->id]);
    $this->actingAs($user);
    expect(DayDrawer::available())->toBeFalse();
    $own = tx7Day($this->asha, '2026-10-13');
    Livewire::test(ListAttendanceRecords::class)->assertTableActionHidden('day', $own)->assertTableActionHidden('calendar', $own);
    Livewire::test(AttendanceCalendar::class)->assertSet('employee', $this->asha->id)->assertDontSeeHtml('data-open-day=');
    $this->get(ListAttendanceRecords::getUrl())->assertOk()->assertDontSeeLivewire(DayDrawer::class);
});

// ---- Exclude and restore ----------------------------------------------------------------------------------------------

it('excludes a punch with a reason: kept as evidence, skipped by processing, the day recalculated; restore puts it back exactly', function () {
    $wrong = tx7Punch($this->asha, '2026-10-13 06:00:00', 'in', ['latitude' => 28.61, 'longitude' => 77.21]);
    tx7Punch($this->asha, '2026-10-13 09:00:00', 'in');
    tx7Punch($this->asha, '2026-10-13 18:00:00', 'out');
    // Ravi has the same day without the wrong punch: what Asha's day must look like once it is excluded.
    tx7Punch($this->ravi, '2026-10-13 09:00:00', 'in');
    tx7Punch($this->ravi, '2026-10-13 18:00:00', 'out');
    $before = tx7Fields(tx7Day($this->asha, '2026-10-13'));
    $evidence = collect($wrong->refresh()->getAttributes())->only(['punched_at', 'direction', 'source', 'source_type', 'fingerprint', 'latitude', 'longitude', 'payload', 'attendance_device_id', 'received_at'])->all();
    expect(tx7Day($this->asha, '2026-10-13')->first_in->format('H:i'))->toBe('06:00')->and($wrong->processing_status)->toBe('processed');

    $this->corrections->exclude($wrong, '  Test punch from the new device  ', $this->hr);
    $wrong->refresh();
    $day = tx7Day($this->asha, '2026-10-13');
    expect($wrong->processing_status)->toBe('excluded')->and($wrong->status_before_exclusion)->toBe('processed')
        ->and($wrong->exclusion_reason)->toBe('Test punch from the new device')->and($wrong->excluded_by)->toBe($this->hr->id)->and($wrong->excluded_at)->not->toBeNull()
        ->and(collect($wrong->getAttributes())->only(array_keys($evidence))->all())->toBe($evidence)
        ->and(AttendancePunch::query()->whereKey($wrong->id)->exists())->toBeTrue()
        ->and($day->first_in->format('H:i'))->toBe('09:00')->and($day->worked_minutes)->toBe(480)
        ->and(collect(tx7Fields($day))->except('calculation_basis')->all())->toBe(collect(tx7Fields(tx7Day($this->ravi, '2026-10-13')))->except('calculation_basis')->all())
        ->and(app(PunchIngestion::class)->punchesBetween($this->asha, now()->setDate(2026, 10, 13)->startOfDay(), now()->setDate(2026, 10, 13)->endOfDay())->pluck('id'))->not->toContain($wrong->id);
    $audit = AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendancePunch::class)->where('entity_id', (string) $wrong->id)->orderBy('id')->get();
    expect($audit->map(fn ($e) => $e->action)->all())->toBe([AuditAction::StatusChange])
        ->and($audit->first()->reason)->toBe('Test punch from the new device')->and($audit->first()->metadata['punch'])->toBe('excluded');

    // Processing again (a later punch, the nightly run) never counts it and never marks it failed.
    app(AttendanceProcessor::class)->process($this->asha, '2026-10-13');
    expect($wrong->refresh()->processing_status)->toBe('excluded')->and(tx7Day($this->asha, '2026-10-13')->first_in->format('H:i'))->toBe('09:00');

    $this->corrections->restore($wrong, 'It was a real early start', $this->hr);
    $wrong->refresh();
    expect($wrong->processing_status)->toBe('processed')->and($wrong->excluded_at)->toBeNull()->and($wrong->excluded_by)->toBeNull()
        ->and($wrong->exclusion_reason)->toBeNull()->and($wrong->status_before_exclusion)->toBeNull()
        ->and(tx7Fields(tx7Day($this->asha, '2026-10-13')))->toBe($before)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendancePunch::class)->where('entity_id', (string) $wrong->id)->orderBy('id')->get()->map(fn ($e) => $e->action)->all())
        ->toBe([AuditAction::StatusChange, AuditAction::Restore]);

    // From the punch register too.
    Livewire::test(ListAttendancePunches::class)
        ->assertTableActionVisible('exclude', $wrong)->assertTableActionHidden('restore', $wrong)
        ->callTableAction('exclude', $wrong, data: ['reason' => 'Duplicate'])->assertHasNoTableActionErrors();
    expect($wrong->refresh()->processing_status)->toBe('excluded');
    Livewire::test(ListAttendancePunches::class)->assertTableActionHidden('exclude', $wrong)->assertTableActionVisible('restore', $wrong)
        ->callTableAction('restore', $wrong, data: ['reason' => 'Not a duplicate']);
    expect($wrong->refresh()->processing_status)->toBe('processed');
});

it('adds a punch by hand with a reason and recalculates the day', function () {
    tx7Punch($this->asha, '2026-10-13 09:00:00', 'in');
    expect(tx7Day($this->asha, '2026-10-13')->status)->toBe('incomplete');

    $punch = $this->corrections->add($this->asha, now()->setDateTime(2026, 10, 13, 18, 0), 'out', 'Forgot to punch out', $this->hr);
    expect($punch->source_type)->toBe('manual')->and($punch->note)->toBe('Forgot to punch out')->and($punch->recorded_by)->toBe($this->hr->id)
        ->and(tx7Day($this->asha, '2026-10-13')->status)->toBe('present')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', AttendancePunch::class)->where('entity_id', (string) $punch->id)->value('reason'))->toBe('Forgot to punch out')
        // The same minute and direction again is already on record.
        ->and($this->corrections->add($this->asha, now()->setDateTime(2026, 10, 13, 18, 0), 'out', 'Again', $this->hr))->toBeNull();
});

it('refuses with a reason: no reason, one\'s own punch, outside the scope, without the right, twice, a refused check-in, future and not employed', function () {
    $punch = tx7Punch($this->asha, '2026-10-13 09:00:00', 'in');
    $omars = tx7Punch($this->omar, '2026-10-13 09:00:00', 'in');
    expect(fn () => $this->corrections->exclude($punch, '   ', $this->hr))->toThrow(RuntimeException::class, 'Give the reason for excluding the punch.');

    // One's own punch, even for HR.
    $this->asha->update(['user_id' => $this->hr->id]);
    expect(fn () => $this->corrections->exclude($punch->refresh(), 'Mine', $this->hr))->toThrow(RuntimeException::class, 'You cannot change your own punches.')
        ->and(fn () => $this->corrections->add($this->asha->refresh(), now()->setDateTime(2026, 10, 13, 18, 0), 'out', 'Mine', $this->hr))->toThrow(RuntimeException::class, 'You cannot change your own punches.');
    $this->asha->update(['user_id' => null]);

    // Outside the access scope (HR of Acme India only).
    $scoped = tenantUser($this->tenant, ['attendance.view', 'attendance.punch']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$this->company->id]]);
    expect($this->corrections->refusal($scoped, $this->omar))->toBe('Outside your access scope.')
        ->and($this->corrections->refusal($scoped, $this->asha->refresh()))->toBeNull()
        ->and(fn () => $this->corrections->exclude($omars, 'Wrong', $scoped))->toThrow(RuntimeException::class, 'Outside your access scope.');

    // Without attendance.punch (TX-2's split right; attendance.manage still grants it) and with the team option off.
    $viewer = tenantUser($this->tenant, ['attendance.view', 'attendance.override']);
    expect($this->corrections->refusal($viewer, $this->asha))->toBe('You may not change punches.')
        ->and($this->corrections->refusal(tenantUser($this->tenant, ['attendance.manage']), $this->asha))->toBeNull();

    // Twice; a refused check-in; restoring what is not excluded.
    $this->corrections->exclude($punch->refresh(), 'Wrong', $this->hr);
    expect(fn () => $this->corrections->exclude($punch->refresh(), 'Again', $this->hr))->toThrow(RuntimeException::class, 'already excluded');
    $refused = AttendancePunch::create(['employee_id' => $this->asha->id, 'punched_at' => '2026-10-13 12:00:00', 'direction' => 'auto', 'source' => 'web', 'source_type' => 'web',
        'fingerprint' => 'refused-2', 'received_at' => now(), 'processing_status' => 'rejected', 'processing_error' => 'Outside the window']);
    expect(fn () => $this->corrections->exclude($refused, 'x', $this->hr))->toThrow(RuntimeException::class, 'refused at check-in')
        ->and(fn () => $this->corrections->restore($refused, 'x', $this->hr))->toThrow(RuntimeException::class, 'Only an excluded punch can be restored.')
        ->and(fn () => $this->corrections->restore($punch->refresh(), '', $this->hr))->toThrow(RuntimeException::class, 'Give the reason for restoring the punch.');

    // Adding: the future, before joining, after leaving, a bad direction.
    $joiner = tx7Hire('Kiran', $this->delhi, 'EMP4', '2026-10-14');
    $gone = forceLifecycle(tx7Hire('Gopal', $this->delhi, 'EMP5', '2024-01-01'), LifecycleState::Exited, ['exit_date' => '2026-10-09']);
    expect(fn () => $this->corrections->add($this->asha, now()->addHour(), 'in', 'Later', $this->hr))->toThrow(RuntimeException::class, 'cannot be in the future')
        ->and(fn () => $this->corrections->add($joiner, now()->setDateTime(2026, 10, 13, 9, 0), 'in', 'Early', $this->hr))->toThrow(RuntimeException::class, 'joins 14 Oct 2026')
        ->and(fn () => $this->corrections->add($gone, now()->setDateTime(2026, 10, 12, 9, 0), 'in', 'Late', $this->hr))->toThrow(RuntimeException::class, 'left 09 Oct 2026')
        ->and(fn () => $this->corrections->add($this->asha, now()->setDateTime(2026, 10, 13, 9, 0), 'sideways', 'x', $this->hr))->toThrow(RuntimeException::class, 'Choose In, Out or Auto.');
});

it('refuses locked days and closed months unless corrections after lock are allowed, and then records the difference as an arrear', function () {
    $in = tx7Punch($this->asha, '2026-10-12 09:00:00', 'in');
    $out = tx7Punch($this->asha, '2026-10-12 18:00:00', 'out');
    $locked = tx7Day($this->asha, '2026-10-12');
    $locked->update(['is_locked' => true]);
    $before = tx7Fields($locked->refresh());

    expect(fn () => $this->corrections->exclude($in, 'Wrong', $this->hr))->toThrow(RuntimeException::class, 'This day is locked (finalized days).')
        ->and(fn () => $this->corrections->add($this->asha, now()->setDateTime(2026, 10, 12, 13, 0), 'auto', 'Lunch', $this->hr))->toThrow(RuntimeException::class, 'locked')
        ->and($in->refresh()->processing_status)->toBe('processed');

    tx7Setting('attendance.allow_corrections_after_lock', true);
    $this->corrections->exclude($in->refresh(), 'Wrong', $this->hr);
    // In only missing: still a paid day — nothing to correct.
    expect(tx7Fields(tx7Day($this->asha, '2026-10-12')))->toBe($before)
        ->and(AttendanceArrear::query()->where('employee_id', $this->asha->id)->count())->toBe(0);
    $this->corrections->exclude($out->refresh(), 'Wrong too', $this->hr);
    $arrear = AttendanceArrear::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-12')->first();
    expect(tx7Fields(tx7Day($this->asha, '2026-10-12')))->toBe($before) // the locked day keeps its values
        ->and($arrear->status)->toBe('pending')->and($arrear->status_after)->toBe('absent')->and((float) $arrear->delta_days)->toBe(-1.0);
    $this->corrections->restore($out->refresh(), 'It was right', $this->hr);
    expect($arrear->refresh()->status)->toBe('void');
    tx7Setting('attendance.allow_corrections_after_lock', false);

    // A closed attendance month (TX-3): refused while month close is on, unless corrections after lock are allowed.
    $september = tx7Punch($this->asha, '2026-09-29 09:00:00', 'in');
    tx7Setting('attendance.month_close.enabled', true);
    AttendanceMonthClose::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 9, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'closed']);
    expect(fn () => $this->corrections->exclude($september, 'Wrong', $this->hr))->toThrow(RuntimeException::class, 'This day is in a closed attendance month.')
        // Other months stay open.
        ->and($this->corrections->exclude(tx7Punch($this->ravi, '2026-10-13 09:00:00', 'in'), 'Wrong', $this->hr)->processing_status)->toBe('excluded');
    tx7Setting('attendance.allow_corrections_after_lock', true);
    expect($this->corrections->exclude($september->refresh(), 'Wrong', $this->hr)->processing_status)->toBe('excluded');
});

// ---- The day drawer ---------------------------------------------------------------------------------------------------

it('opens the day drawer from attendance records, the calendar, the roster board and the paid-days breakdown', function () {
    tx7Punch($this->asha, '2026-10-13 09:00:00', 'in');
    tx7Punch($this->asha, '2026-10-13 18:00:00', 'out');
    $record = tx7Day($this->asha, '2026-10-13');

    // Attendance records: "Day" opens the drawer, "Calendar" the employee's month.
    Livewire::test(ListAttendanceRecords::class)
        ->assertTableActionVisible('day', $record)
        ->assertTableActionHasUrl('calendar', AttendanceCalendar::getUrl(['employee' => $this->asha->id, 'month' => '2026-10']), $record)
        ->callTableAction('day', $record)
        ->assertDispatched('pos-day-drawer-open', employee: $this->asha->id, date: '2026-10-13');

    // The calendar: for the HR side every day opens the day panel (as the employee's own month does), whose
    // "Punches & corrections" opens the drawer.
    Livewire::test(AttendanceCalendar::class, ['employee' => $this->asha->id, 'month' => '2026-10'])
        ->assertSeeHtml('data-panel-day="2026-10-13"')
        ->mountAction('day', ['date' => '2026-10-13'])->callAction('dayCorrections')
        ->assertDispatched('pos-day-drawer-open', employee: $this->asha->id, date: '2026-10-13');

    // The roster board: a "Day" link under each employed day (the cell keeps opening "Edit day").
    Livewire::test(RosterBoard::class)
        ->assertSeeHtml('data-open-day="'.$this->asha->id.'|2026-10-13"')
        ->assertSeeHtml('data-cell="'.$this->asha->id.'|2026-10-13" data-code="GEN"');

    // The paid-days breakdown: "Change" on an arrear row.
    AttendanceArrear::create(['employee_id' => $this->asha->id, 'date' => '2026-10-12', 'status_before' => 'present', 'status_after' => 'absent', 'paid_before' => 1, 'paid_after' => 0, 'delta_days' => -1, 'status' => 'pending', 'note' => 'Test']);
    Livewire::withQueryParams(['company' => (string) $this->company->id, 'month' => '2026-10', 'detail' => (string) $this->asha->id])->test(PaidDays::class)
        ->assertSeeHtml('data-open-day="2026-10-12"');

    // The shell renders the drawer once on these pages for attendance viewers.
    $this->get(ListAttendanceRecords::getUrl())->assertOk()->assertSeeLivewire(DayDrawer::class);
    $this->get(AttendanceCalendar::getUrl(['employee' => $this->asha->id]))->assertOk()->assertSeeLivewire(DayDrawer::class);

    // The drawer itself: the day, its punches with source, the calendar link.
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')
        ->assertSee('Asha Test · Tue 13 Oct 2026')->assertSee('Present')->assertSee('General (GEN)')
        ->assertSee('09:00:00')->assertSee('18:00:00')->assertSee('In · Web')->assertSeeHtml('data-punch-action="exclude"')
        ->assertSeeHtml('data-day-reprocess')->assertSeeHtml('data-day-calendar');
    // A day outside the viewer's reach is "Not available".
    $scoped = tenantUser($this->tenant, ['attendance.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [$this->other->id]]);
    $this->actingAs($scoped);
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertSee('Not available')->assertDontSee('09:00:00');
});

it('changes punches from the drawer, read-only when asked, and reprocesses the day', function () {
    $wrong = tx7Punch($this->asha, '2026-10-13 06:00:00', 'in');
    tx7Punch($this->asha, '2026-10-13 09:00:00', 'in');

    $drawer = Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13');
    $drawer->call('startForm', 'exclude:'.$wrong->id)->assertSeeHtml('data-day-form="exclude:'.$wrong->id.'"')
        ->set('reason', '')->call('excludePunch', $wrong->id)->assertSet('error', 'Give the reason for excluding the punch.')
        ->set('reason', 'Wrong device')->call('excludePunch', $wrong->id)
        ->assertSet('error', null)->assertSet('form', null)->assertDispatched('pos-attendance-day-changed', employee: $this->asha->id, date: '2026-10-13')
        ->assertSee('Excluded')->assertSee('Wrong device')->assertSeeHtml('data-punch-action="restore"');
    expect($wrong->refresh()->processing_status)->toBe('excluded');

    $drawer->call('startForm', 'add')->assertSet('punchDate', '2026-10-13')->assertSet('punchTime', '09:00')
        ->set('punchTime', '18:00')->set('direction', 'out')->set('reason', 'Forgot to punch out')->call('addPunch')->assertSet('error', null);
    expect(tx7Day($this->asha, '2026-10-13')->status)->toBe('present');

    $drawer->set('reason', 'Real')->call('restorePunch', $wrong->id)->assertSet('error', null);
    expect($wrong->refresh()->processing_status)->toBe('processed');

    // Reprocess (attendance.process on the day).
    AttendanceRecord::query()->whereKey(tx7Day($this->asha, '2026-10-13')->id)->update(['status' => 'absent']);
    $drawer->call('reprocess')->assertSet('error', null);
    expect(tx7Day($this->asha, '2026-10-13')->status)->toBe('present');

    // Read-only: mounted read-only by the host, or opened read-only — no action is offered or taken.
    Livewire::test(DayDrawer::class, ['readOnly' => true])->call('show', $this->asha->id, '2026-10-13')
        ->assertDontSeeHtml('data-punch-action=')->assertDontSeeHtml('data-day-add-punch')->assertDontSeeHtml('data-day-reprocess')
        ->set('reason', 'x')->call('excludePunch', $wrong->id)->assertSet('error', 'This day is read-only here.');
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13', true)->assertDontSeeHtml('data-punch-action=');
    expect($wrong->refresh()->processing_status)->toBe('processed');
});

it('shows coordinates, network and photos only to the geo-access check-in audience (nobody by default)', function () {
    Storage::disk('local')->put('attendance-photos/'.$this->asha->id.'/2026-10/selfie.jpg', 'jpeg-bytes');
    $punch = tx7Punch($this->asha, '2026-10-13 09:00:00', 'in', ['latitude' => 28.6139, 'longitude' => 77.2090, 'photos' => ['attendance-photos/'.$this->asha->id.'/2026-10/selfie.jpg'],
        'ip_address' => '10.1.2.3', 'source_type' => 'mobile'], 'mobile');
    $photo = ParityFileController::url('punch-photo', $punch, 0);

    // Default: HR (attendance.view in scope) sees the punch, not its location or photos.
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')
        ->assertSee('In · Mobile')->assertSee('Check-in evidence kept')->assertDontSee('28.6139')->assertDontSee('10.1.2.3')->assertDontSeeHtml('data-punch-photos');
    $this->get($photo)->assertForbidden();

    // Chosen for HR: coordinates, IP and the photo behind a signed, audited link.
    tx7Setting('geo_dashboard.checkins_visible_to', ['hr']);
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')
        ->assertSee('Location 28.6139, 77.209')->assertSee('IP 10.1.2.3')->assertSeeHtml('data-punch-photos')->assertSee('Photo 1');
    $this->get($photo)->assertOk();
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', 'ATTACHMENT_DOWNLOADED')->where('entity_type', AttendancePunch::class)->count())->toBe(1);

    // Managers: only once chosen for managers, and only for the people who report to them.
    [$managerUser] = tx7Manager($this, $this->asha);
    $this->actingAs($managerUser);
    app()->forgetInstance(RoleLens::class);
    $this->get($photo)->assertForbidden();
    tx7Setting('geo_dashboard.checkins_visible_to', ['hr', 'manager']);
    app()->forgetInstance(RoleLens::class);
    $this->get($photo)->assertOk();
    $ravis = tx7Punch($this->ravi, '2026-10-13 09:00:00', 'in', ['photos' => ['attendance-photos/'.$this->asha->id.'/2026-10/selfie.jpg'], 'source_type' => 'mobile'], 'mobile');
    $this->get(ParityFileController::url('punch-photo', $ravis, 0))->assertForbidden();
});

it('cancels the day\'s leave through the leave contract (Leave\'s own cancellation), only for people who may', function () {
    expect(app(LeaveOnDay::class))->toBeInstanceOf(AttendanceLeaveOnDay::class);
    $el = tx7Leave($this->asha);
    $leave = app(Leaves::class)->request($this->asha, $el, '2026-10-13', '2026-10-13', 'Family function', requester: $this->hr);
    app(Leaves::class)->approve($leave, 'Fine', $this->hr);
    expect(tx7Day($this->asha, '2026-10-13')?->status)->toBe('leave');

    $onDay = app(LeaveOnDay::class)->onDay($this->asha, now()->setDate(2026, 10, 13), $this->hr);
    expect($onDay)->toHaveCount(1)->and($onDay[0])->toMatchArray(['id' => $leave->id, 'code' => 'EL', 'status' => 'approved', 'session' => 'full', 'can_cancel' => true]);

    // Attendance HR without leave.manage: shown, not cancellable.
    $timeOffice = tenantUser($this->tenant, ['attendance.*', 'leave.view']);
    $this->actingAs($timeOffice);
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertSee('Family function')->assertSeeHtml('data-leave="'.$leave->id.'"')
        ->assertDontSeeHtml('data-leave-cancel=')
        ->set('reason', 'x')->call('cancelLeave', $leave->id)->assertSet('error', 'You may not cancel this leave.');
    expect(fn () => app(LeaveOnDay::class)->cancel($leave->id, 'x', $timeOffice))->toThrow(RuntimeException::class, 'You may not cancel this leave.');

    // HR with leave.manage: cancelled the way Leave requests cancels it — balance restored, the day recalculated.
    $this->actingAs($this->hr);
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertSeeHtml('data-leave-cancel="'.$leave->id.'"')
        ->call('startForm', 'cancel:'.$leave->id)->set('reason', '')->call('cancelLeave', $leave->id)->assertSet('error', 'Give the reason for cancelling the leave.')
        ->set('reason', 'Came to work')->call('cancelLeave', $leave->id)->assertSet('error', null)->assertSet('message', 'Leave cancelled.')
        ->assertSee('No leave on this day.');
    expect($leave->refresh()->status)->toBe('cancelled')->and(tx7Day($this->asha, '2026-10-13')->status)->toBe('absent')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::LeaveCancelled->value)->where('entity_id', (string) $leave->id)->exists())->toBeTrue();
});

it('sets and removes the day\'s override with TX-6\'s form in the drawer, never on one\'s own day', function () {
    tx7Punch($this->asha, '2026-10-13 09:00:00', 'in');
    tx7Punch($this->asha, '2026-10-13 18:00:00', 'out');

    $drawer = Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertSeeHtml('data-day-override')
        ->call('startForm', 'override')->assertSet('overrideData.mode', 'whole')
        ->set('overrideData.mode', 'halves')->set('overrideData.first_half', 'present')->set('overrideData.second_half', 'absent')
        ->call('saveOverride')->assertHasErrors(['overrideData.reason']);
    $drawer->set('overrideData.reason', 'Left at noon')->call('saveOverride')->assertHasNoErrors()->assertSet('error', null)
        ->assertDispatched('pos-attendance-day-changed');
    $override = AttendanceOverride::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-13')->first();
    expect($override->first_half_status)->toBe('present')->and($override->second_half_status)->toBe('absent')->and($override->reason)->toBe('Left at noon')
        ->and(tx7Day($this->asha, '2026-10-13')->status)->toBe('half_day');

    // The form opens with the day's override; "Remove" clears it with a reason.
    $drawer->call('startForm', 'override')->assertSet('overrideData.mode', 'halves')->assertSet('overrideData.first_half', 'present')->call('cancelForm');
    $drawer->call('startForm', 'clear')->set('reason', '')->call('clearOverride')->assertSet('error', 'Give the reason for removing the override.')
        ->set('reason', 'Stayed the whole day')->call('clearOverride')->assertSet('error', null);
    expect(AttendanceOverride::query()->where('employee_id', $this->asha->id)->count())->toBe(0)->and(tx7Day($this->asha, '2026-10-13')->status)->toBe('present');

    // One's own day: no override, no punch changes.
    $this->asha->update(['user_id' => $this->hr->id]);
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertDontSeeHtml('data-day-override')->assertDontSeeHtml('data-punch-action=')
        ->call('saveOverride')->assertSet('error', 'You may not change this day.');
});

it('lets managers change their team\'s punches only with the team option, within scope, never their own', function () {
    $ashas = tx7Punch($this->asha, '2026-10-13 06:00:00', 'in');
    $ravis = tx7Punch($this->ravi, '2026-10-13 06:00:00', 'in');
    [$managerUser, $manager] = tx7Manager($this, $this->asha);
    $own = tx7Punch($manager, '2026-10-13 09:00:00', 'in');
    $this->actingAs($managerUser);

    // Off (default): managers see the day, change nothing.
    expect($this->corrections->refusal($managerUser, $this->asha))->toBe('You may not change punches.');
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertSee('06:00:00')->assertDontSeeHtml('data-punch-action=')
        ->set('reason', 'Wrong')->call('excludePunch', $ashas->id)->assertSet('error', 'You may not change punches.');
    expect($ashas->refresh()->processing_status)->toBe('processed');

    // On: their direct reports, with a reason; not other people; never their own.
    tx7Setting('team.punch_edit', true);
    Livewire::test(DayDrawer::class)->call('show', $this->asha->id, '2026-10-13')->assertSeeHtml('data-punch-action="exclude"')
        ->set('reason', 'Test punch')->call('excludePunch', $ashas->id)->assertSet('error', null);
    expect($ashas->refresh()->processing_status)->toBe('excluded')->and($ashas->excluded_by)->toBe($managerUser->id)
        ->and($this->corrections->add($this->asha, now()->setDateTime(2026, 10, 13, 9, 0), 'in', 'Came in at nine', $managerUser))->not->toBeNull()
        ->and($this->corrections->restore($ashas->refresh(), 'It was right', $managerUser)->processing_status)->toBe('processed')
        ->and($this->corrections->refusal($managerUser, $this->ravi))->toBe('You may change punches only for the people who report to you.')
        ->and(fn () => $this->corrections->exclude($ravis, 'Wrong', $managerUser))->toThrow(RuntimeException::class, 'only for the people who report to you')
        ->and(fn () => $this->corrections->exclude($own, 'Mine', $managerUser))->toThrow(RuntimeException::class, 'You cannot change your own punches.');

    // Within scope: a manager scoped elsewhere still reaches the people who report to them (relationship scope), and the
    // team option never stretches past it.
    app(AccessScopes::class)->assign($managerUser, ['company' => [$this->other->id]]);
    expect($this->corrections->refusal($managerUser->fresh(), $this->asha))->toBeNull();
    ReportingRelationship::query()->where('employee_id', $this->asha->id)->update(['effective_to' => '2026-10-01']);
    expect($this->corrections->refusal($managerUser->fresh(), $this->asha))->toBe('You may change punches only for the people who report to you.');
});

it('keeps TX-7 attendance and leave code free of payroll words', function () {
    $files = ['Domain/Attendance/Services/PunchCorrections.php', 'Domain/Attendance/Services/AttendanceDayDetails.php', 'Domain/Attendance/Contracts/LeaveOnDay.php',
        'Domain/Leave/Services/AttendanceLeaveOnDay.php', 'Livewire/Attendance/DayDrawer.php', 'Filament/Support/CheckInEvidence.php'];
    foreach ($files as $file) {
        expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
    expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(resource_path('views/livewire/attendance/day-drawer.blade.php'))))->toBeFalse()
        ->and((bool) preg_match('/\b(payroll|pay|salary|wage|ctc|payslip)\b/i', file_get_contents(database_path('migrations/2027_01_03_200001_add_exclusion_to_attendance_punches.php'))))->toBeFalse();
});
