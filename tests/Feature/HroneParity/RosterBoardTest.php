<?php

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Services\ReportLibrary;
use App\Domain\Analytics\Services\ReportRunner;
use App\Domain\Attendance\Imports\ShiftRosterImports;
use App\Domain\Attendance\Models\AttendanceMonthClose;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\ShiftOverride;
use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Models\WorkScheduleRule;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\RosterGrid;
use App\Domain\Attendance\Services\ShiftResolver;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\RosterBoard;
use App\Filament\Pages\TimeOfficeDesk;
use App\Filament\Resources\ShiftOverrides\Pages\ListShiftOverrides;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| HROne parity TX-5 (matrix 24 §24.2.1 #1, 2, 5, 7, 9; §24.8 #8; the HR part of §24.13 #2): the roster board (employees ×
| days of the resolved shift with weekly-off, holiday and leave marks), "Edit day", "Assign shift" for a range with
| weekly-off weekdays and automatic shift correction, "Remove rostered days", per-employee refusals, the roster upload's new
| columns and the day_roster dataset. Today is Wednesday 14 Oct 2026; the board opens on the week of Mon 12 Oct.
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
    $this->general = generalShift();
    $this->night = generalShift(['name' => 'Night', 'code' => 'NGT', 'start_time' => '21:00:00', 'end_time' => '06:00:00']);
    $this->evening = generalShift(['name' => 'Evening', 'code' => 'EVE', 'start_time' => '14:00:00', 'end_time' => '23:00:00']);
    $this->schedule = weeklySchedule($this->general);
    $this->asha = tx5Hire('Asha', $this->delhi, 'EMP1');
    $this->ravi = tx5Hire('Ravi', $this->mumbai, 'EMP2');
    assignSchedule($this->asha, $this->schedule);
    assignSchedule($this->ravi, $this->schedule);
    $this->roster = app(ShiftRoster::class);
});

function tx5Hire(string $first, Location $location, string $code, string $joining = '2025-01-01'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joining, 'employee_code' => $code],
        ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

function tx5Setting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function tx5Override(Employee $employee, string $date): ?ShiftOverride
{
    return ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

/** Stage, inspect, map automatically, validate (the upload pipeline). */
function tx5Stage(string $csv): EmployeeImport
{
    $service = app(ShiftRosterImports::class);
    $path = EmployeeImports::directory().'/'.uniqid().'.csv';
    Storage::disk('local')->put($path, $csv);
    $import = $service->register($path, 'roster.csv', auth()->user());
    $service->inspect($import);
    $service->map($import->refresh(), $import->refresh()->mapping);

    return $service->validate($import->refresh());
}

function tx5Run(EmployeeImport $import): EmployeeImport
{
    $service = app(ShiftRosterImports::class);
    $service->approve($import, auth()->user(), 'Roster file');

    return $service->run($import->refresh(), auth()->user());
}

// ---- Defaults ----------------------------------------------------------------------------------------------------------

it('keeps the earlier roster paths, the resolver\'s answer and automatic shift correction exactly as before', function () {
    $this->schedule->update(['auto_shift_ids' => [$this->general->id, $this->evening->id]]);
    $this->roster->assign([$this->asha], '2026-10-12', '2026-10-12', $this->night, 'Cover');

    // No choice stored, the same answer keys as before, and no audit change of the new column.
    $override = tx5Override($this->asha, '2026-10-12');
    expect($override->getAttributes()['auto_shift'])->toBeNull()->and($override->allowsAutoShift())->toBeFalse()
        ->and(array_keys(app(ShiftResolver::class)->resolve($this->asha, Carbon::parse('2026-10-12'))))->toBe(['schedule', 'shift', 'weekly_off', 'anchor', 'override'])
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', ShiftOverride::class)->get()
            ->flatMap(fn (AuditEvent $e) => $e->fieldChanges()->pluck('field'))->all())->not->toContain('auto_shift');

    // The schedule corrects days that are not rostered; a rostered day keeps its shift unless its roster allows correction.
    foreach (['2026-10-12', '2026-10-13'] as $date) {
        punch($this->asha, "{$date} 13:58:00", 'in');
        punch($this->asha, "{$date} 19:30:00", 'out');
    }
    $rostered = app(AttendanceProcessor::class)->process($this->asha, '2026-10-12');
    $scheduled = app(AttendanceProcessor::class)->process($this->asha, '2026-10-13');
    expect($rostered->shift_id)->toBe($this->night->id)->and($rostered->calculation_basis)->not->toHaveKey('auto_shift')
        ->and($scheduled->shift_id)->toBe($this->evening->id)->and($scheduled->calculation_basis['auto_shift'])->toBe('EVE');

    // Re-rostering a day through the earlier path drops a choice made on the board.
    $this->roster->assignPattern([$this->asha], '2026-10-19', '2026-10-19', $this->night, [], true, 'Board', $this->hr);
    $this->roster->assign([$this->asha], '2026-10-19', '2026-10-19', $this->general, 'Earlier path');
    expect(tx5Override($this->asha, '2026-10-19')->getAttributes()['auto_shift'])->toBeNull();
});

it('reads weekday names for weekly offs and refuses anything else', function () {
    expect(ShiftRoster::weekdays(['Sun', 'saturday', 'SAT', '']))->toBe(['sat', 'sun'])
        ->and(fn () => ShiftRoster::weekdays(['Funday']))->toThrow(RuntimeException::class, 'not a weekday')
        ->and(fn () => $this->roster->assignPattern([$this->asha], '2026-10-19', '2026-10-25', $this->night, ['sat'], false, '  ', $this->hr))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $this->roster->assignPattern([$this->asha], '2026-10-25', '2026-10-19', $this->night, [], false, 'Back', $this->hr))->toThrow(RuntimeException::class, 'end date');
});

// ---- The board ---------------------------------------------------------------------------------------------------------

it('shows the resolved shift with rostered, leave, holiday, weekly-off, locked and not-employed marks', function () {
    $calendar = HolidayCalendar::create(['name' => 'India', 'code' => 'IN']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-10-15', 'name' => 'Dussehra']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);
    $type = LeaveType::query()->orderBy('id')->firstOrFail();
    LeaveRequest::create(['employee_id' => $this->asha->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-13', 'to_date' => '2026-10-13', 'days' => 1,
        'dates' => [['date' => '2026-10-13', 'days' => 1, 'session' => 'full']], 'reason' => 'Family', 'status' => 'approved']);
    LeaveRequest::create(['employee_id' => $this->ravi->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-16', 'to_date' => '2026-10-16', 'days' => 1,
        'dates' => [['date' => '2026-10-16', 'days' => 1, 'session' => 'full']], 'reason' => 'Trip', 'status' => 'pending']);
    $this->roster->assignPattern([$this->asha], '2026-10-14', '2026-10-14', $this->night, [], true, 'Cover', $this->hr);
    AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-12', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);
    $kiran = forceLifecycle(tx5Hire('Kiran', $this->delhi, 'EMP3', '2026-10-16'), LifecycleState::Probation);

    $cells = app(RosterGrid::class)->cells([$this->asha, $this->ravi, $kiran], Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
    expect($cells[$this->asha->id]['2026-10-12'])->toMatchArray(['code' => 'GEN', 'times' => '09:00–18:00', 'rostered' => false, 'weekly_off' => false, 'schedule' => 'Mon–Fri', 'active' => true])
        ->and($cells[$this->asha->id]['2026-10-13'])->toMatchArray(['code' => 'GEN', 'leave' => $type->code, 'leave_paid' => (bool) $type->is_paid])
        ->and($cells[$this->asha->id]['2026-10-14'])->toMatchArray(['code' => 'NGT', 'rostered' => true, 'source' => 'Roster', 'roster_reason' => 'Cover', 'auto_shift' => true])
        ->and($cells[$this->asha->id]['2026-10-15'])->toMatchArray(['code' => 'GEN', 'holiday' => 'Dussehra'])
        ->and($cells[$this->asha->id]['2026-10-17'])->toMatchArray(['weekly_off' => true, 'code' => null])
        ->and($cells[$this->ravi->id]['2026-10-16'])->toMatchArray(['leave' => null, 'leave_pending' => $type->name])
        ->and($cells[$this->ravi->id]['2026-10-12'])->toMatchArray(['locked' => true, 'status' => 'present'])
        ->and($cells[$kiran->id]['2026-10-15']['active'])->toBeFalse()->and($cells[$kiran->id]['2026-10-16']['active'])->toBeTrue();

    // The page: one column per day of the week, each cell with its code; HR with attendance.roster gets "Edit day" on it.
    $page = Livewire::test(RosterBoard::class)->assertOk()
        ->assertCanSeeTableRecords([$this->asha, $this->ravi, $kiran])
        ->assertSee('Week of 12 Oct 2026')
        ->assertSeeHtml('data-cell="'.$this->asha->id.'|2026-10-14" data-code="NGT"')
        ->assertSeeHtml('data-cell="'.$this->asha->id.'|2026-10-17" data-code="WO"')
        ->assertSeeHtml('data-cell="'.$kiran->id.'|2026-10-12" data-code="·"')
        ->assertSeeHtml('Holiday: Dussehra')
        ->assertSeeHtml('mountAction(&#039;editCell&#039;, {&quot;employee&quot;:'.$this->asha->id.',&quot;date&quot;:&quot;2026-10-14&quot;})');
    expect($page->instance()->days())->toHaveCount(7);

    // A month, and the next one.
    $page->set('period', 'month')->assertSee('October 2026');
    expect($page->instance()->days())->toHaveCount(31);
    $page->call('next')->assertSet('start', '2026-11-01')->assertSee('November 2026');
    expect($page->instance()->days())->toHaveCount(30);

    // Without attendance.roster: the board reads, nothing is editable.
    $this->actingAs(tenantUser($this->tenant, ['attendance.view']));
    Livewire::test(RosterBoard::class)->assertOk()->assertDontSeeHtml('mountAction(&#039;editCell')
        ->assertActionHidden('editCell')->assertTableBulkActionHidden('assignShift')->assertTableBulkActionHidden('removeShift');
    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    expect(RosterBoard::canAccess())->toBeFalse();
    $this->get(RosterBoard::getUrl())->assertForbidden();
});

it('gives the board exactly the answers the resolver gives day by day (assignments, rules, rotations, rostered days)', function () {
    $rotation = WorkSchedule::create(['name' => 'Rotation', 'code' => 'ROT', 'effective_from' => '2026-01-05', 'pattern' => [
        ['mon' => $this->general->id, 'tue' => $this->general->id, 'wed' => $this->general->id, 'thu' => $this->general->id, 'fri' => $this->general->id, 'sat' => null, 'sun' => null],
        ['mon' => $this->night->id, 'tue' => $this->night->id, 'wed' => $this->night->id, 'thu' => $this->night->id, 'fri' => null, 'sat' => $this->night->id, 'sun' => null],
    ]]);
    WorkScheduleRule::create(['work_schedule_id' => $rotation->id, 'name' => 'Mumbai', 'priority' => 1, 'status' => 'active', 'effective_from' => '2026-01-01',
        'conditions' => [['field' => 'location_id', 'operator' => 'equals', 'value' => $this->mumbai->id]]]);
    $neha = tx5Hire('Neha', $this->mumbai, 'EMP4');                       // the Mumbai rule
    $dev = tx5Hire('Dev', $this->delhi, 'EMP5');                          // no schedule at all
    $inactive = WorkSchedule::create(['name' => 'Old', 'code' => 'OLD', 'status' => 'inactive', 'pattern' => [['mon' => $this->evening->id]]]);
    assignSchedule($this->ravi, $inactive, '2026-10-20');                   // inactive from the 20th: the rule takes over
    assignSchedule($this->asha, weeklySchedule($this->evening, ['code' => 'EV', 'name' => 'Evenings']), '2026-10-22');
    $this->roster->assignPattern([$this->asha, $neha], '2026-10-05', '2026-10-11', $this->night, ['sun'], true, 'Rotation', $this->hr);

    $employees = Employee::query()->whereKey([$this->asha->id, $this->ravi->id, $neha->id, $dev->id])->with('person')->get();
    $between = app(ShiftResolver::class)->resolveBetween($employees, '2026-10-01', '2026-10-31');
    $resolver = app(ShiftResolver::class);
    $shape = fn (array $a) => ['schedule' => $a['schedule']?->id, 'shift' => $a['shift']?->id, 'weekly_off' => $a['weekly_off'], 'anchor' => $a['anchor']?->toDateString(),
        'override' => ($a['override'] ?? null)?->id, 'auto_shift' => $a['auto_shift'] ?? null, 'keys' => array_keys($a)];
    foreach ($employees as $employee) {
        for ($day = Carbon::parse('2026-10-01'); $day->lte(Carbon::parse('2026-10-31')); $day->addDay()) {
            expect($shape($between[$employee->id][$day->toDateString()]))->toBe($shape($resolver->resolve($employee, $day->copy())), "{$employee->employee_code} {$day->toDateString()}");
        }
    }
    expect($between[$neha->id]['2026-10-13']['shift']->id)->toBe($this->general->id)  // first week of the rotation
        ->and($between[$neha->id]['2026-10-20']['shift']->id)->toBe($this->night->id)   // second week
        ->and($between[$this->ravi->id]['2026-10-20']['schedule']->id)->toBe($rotation->id)
        ->and($between[$dev->id]['2026-10-14'])->toMatchArray(['schedule' => null, 'shift' => null, 'weekly_off' => false]);
});

// ---- Edit a day ----------------------------------------------------------------------------------------------------------

it('edits one day from the board: a shift with automatic correction, a weekly off, then back to the schedule', function () {
    $edit = fn (array $data) => Livewire::test(RosterBoard::class)
        ->callAction('editCell', data: $data + ['reason' => 'Desk change'], arguments: ['employee' => $this->asha->id, 'date' => '2026-10-13'])
        ->assertHasNoActionErrors();

    $edit(['what' => 'shift', 'shift_id' => $this->night->id, 'auto_shift' => true])->assertSet('result.done.0.employee_id', $this->asha->id);
    expect(tx5Override($this->asha, '2026-10-13'))->shift_id->toBe($this->night->id)->allowsAutoShift()->toBeTrue()->source->toBe('roster')->reason->toBe('Desk change')
        // A past day is recalculated on the rostered shift.
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-13')->value('shift_id'))->toBe($this->night->id);

    $edit(['what' => 'weekly_off']);
    expect(tx5Override($this->asha, '2026-10-13')->shift_id)->toBeNull()
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-13')->value('status'))->toBe('weekly_off');

    $edit(['what' => 'schedule']);
    expect(tx5Override($this->asha, '2026-10-13'))->toBeNull()
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-13')->value('shift_id'))->toBe($this->general->id);

    // The reason is required; a day of someone outside the viewer's scope is refused and nothing is written.
    Livewire::test(RosterBoard::class)->callAction('editCell', data: ['what' => 'weekly_off', 'reason' => ''], arguments: ['employee' => $this->asha->id, 'date' => '2026-10-13'])
        ->assertHasActionErrors(['reason' => 'required']);
    $scoped = tenantUser($this->tenant, ['attendance.view', 'attendance.roster']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);
    Livewire::test(RosterBoard::class)->assertCanSeeTableRecords([$this->asha])->assertCanNotSeeTableRecords([$this->ravi])
        ->callAction('editCell', data: ['what' => 'weekly_off', 'reason' => 'Try'], arguments: ['employee' => $this->ravi->id, 'date' => '2026-10-13'])
        ->assertNotified('Not changed');
    expect(tx5Override($this->ravi, '2026-10-13'))->toBeNull();
});

// ---- Assign shift / remove -----------------------------------------------------------------------------------------------

it('assigns a shift for a range with weekly-off weekdays to the selected employees and shows the result', function () {
    Livewire::test(RosterBoard::class)
        ->callTableBulkAction('assignShift', [$this->asha, $this->ravi], data: ['from' => '2026-10-19', 'to' => '2026-10-25', 'shift_id' => $this->night->id,
            'weekly_off_days' => ['wed', 'sun'], 'auto_shift' => false, 'reason' => 'Night rotation'])
        ->assertHasNoTableBulkActionErrors()
        ->assertSet('result.days', 14)
        ->assertSeeHtml('data-done="'.$this->asha->id.'"');

    foreach ([$this->asha, $this->ravi] as $employee) {
        $days = ShiftOverride::query()->where('employee_id', $employee->id)->orderBy('date')->get()->mapWithKeys(fn (ShiftOverride $o) => [$o->date->toDateString() => $o->shift_id])->all();
        expect($days)->toBe(['2026-10-19' => $this->night->id, '2026-10-20' => $this->night->id, '2026-10-21' => null, '2026-10-22' => $this->night->id,
            '2026-10-23' => $this->night->id, '2026-10-24' => $this->night->id, '2026-10-25' => null]);
    }
    expect(ShiftOverride::query()->pluck('source')->unique()->all())->toBe(['roster'])
        ->and(ShiftOverride::query()->get()->every(fn (ShiftOverride $o) => in_array($o->getAttributes()['auto_shift'], [0, false, '0'], true)))->toBeTrue()
        // One audited operation for the change.
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', ShiftOverride::class)->whereNotNull('operation_id')->distinct()->pluck('operation_id'))->toHaveCount(1);

    // Only a shift: no weekly off weekday, every day takes the shift; no shift: every day a weekly off.
    $result = $this->roster->assignPattern([$this->asha], '2026-10-26', '2026-10-27', null, [], false, 'Plant shutdown', $this->hr);
    expect($result['done'][0]['message'])->toBe('2 day(s) rostered: 2 weekly off.')
        ->and(tx5Override($this->asha, '2026-10-26')->shift_id)->toBeNull();
});

it('honours automatic shift correction on rostered days the way the work schedule\'s correction works', function () {
    $this->schedule->update(['auto_shift_ids' => [$this->general->id, $this->evening->id]]);
    $this->roster->assignPattern([$this->asha], '2026-10-12', '2026-10-13', $this->night, [], true, 'Flexible cover', $this->hr);
    $this->roster->assignPattern([$this->ravi], '2026-10-12', '2026-10-12', $this->night, [], false, 'Fixed cover', $this->hr);
    punch($this->asha, '2026-10-12 13:58:00', 'in');
    punch($this->asha, '2026-10-12 19:30:00', 'out');
    punch($this->asha, '2026-10-13 21:03:00', 'in');
    punch($this->asha, '2026-10-14 06:01:00', 'out');
    punch($this->ravi, '2026-10-12 13:58:00', 'in');
    punch($this->ravi, '2026-10-12 19:30:00', 'out');
    $processor = app(AttendanceProcessor::class);

    expect(app(ShiftResolver::class)->resolve($this->asha, Carbon::parse('2026-10-12')))->toHaveKey('auto_shift', true)
        ->and($processor->process($this->asha, '2026-10-12'))->shift_id->toBe($this->evening->id)->calculation_basis->toMatchArray(['auto_shift' => 'EVE'])
        // The rostered shift is one of the shifts to choose from (it is not in the schedule's list).
        ->and($processor->process($this->asha, '2026-10-13')->shift_id)->toBe($this->night->id)
        // Not allowed on Ravi's rostered day: the rostered shift as is.
        ->and($processor->process($this->ravi, '2026-10-12')->shift_id)->toBe($this->night->id);

    // An approved shift change gives way to the shift asked for and its cancellation restores the choice.
    $requests = app(TimeOfficeRequests::class);
    $this->roster->assignPattern([$this->asha], '2026-10-19', '2026-10-19', $this->night, [], true, 'Flexible cover', $this->hr);
    $request = $requests->request($this->asha, 'shift_change', ['from_date' => '2026-10-19', 'to_date' => '2026-10-19', 'shift_id' => $this->general->id], 'Doctor');
    $requests->approve($request, null, $this->hr);
    expect(tx5Override($this->asha, '2026-10-19'))->shift_id->toBe($this->general->id)->allowsAutoShift()->toBeFalse();
    $requests->cancel($request->refresh(), 'Not needed', $this->hr);
    expect(tx5Override($this->asha, '2026-10-19'))->shift_id->toBe($this->night->id)->allowsAutoShift()->toBeTrue();
});

it('removes the rostered days of a range and recalculates the past ones on the schedule', function () {
    $this->roster->assignPattern([$this->asha, $this->ravi], '2026-10-12', '2026-10-20', $this->night, [], false, 'Night week', $this->hr);
    expect(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-12')->value('shift_id'))->toBe($this->night->id);

    Livewire::test(RosterBoard::class)
        ->callTableBulkAction('removeShift', [$this->asha], data: ['from' => '2026-10-12', 'to' => '2026-10-16', 'reason' => 'Back to days'])
        ->assertHasNoTableBulkActionErrors()->assertSet('result.done.0.message', '5 rostered day(s) removed: back to the schedule.');

    expect(ShiftOverride::query()->where('employee_id', $this->asha->id)->count())->toBe(4)
        ->and(ShiftOverride::query()->where('employee_id', $this->ravi->id)->count())->toBe(9)
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-12')->value('shift_id'))->toBe($this->general->id);
    expect($this->roster->removePattern([$this->asha], '2026-10-12', '2026-10-16', 'Again', $this->hr)['done'][0]['message'])->toBe('Nothing was rostered on these days.');
});

// ---- Refusals ------------------------------------------------------------------------------------------------------------

it('refuses each employee on their own: locked days, closed months, outside the scope, not employed', function () {
    // Locked: refused, the other employee done; with corrections after lock the locked day keeps its values.
    AttendanceRecord::create(['employee_id' => $this->asha->id, 'date' => '2026-10-12', 'status' => 'present', 'shift_id' => $this->general->id, 'is_locked' => true, 'processed_at' => now()]);
    $result = $this->roster->assignPattern([$this->asha, $this->ravi], '2026-10-12', '2026-10-13', $this->night, [], false, 'Cover', $this->hr);
    expect($result['refused'])->toHaveCount(1)->and($result['refused'][0]['employee_id'])->toBe($this->asha->id)->and($result['refused'][0]['reason'])->toContain('is locked (finalized days) for')
        ->and($result['done'][0]['employee_id'])->toBe($this->ravi->id)->and($result['days'])->toBe(2)
        ->and(tx5Override($this->asha, '2026-10-13'))->toBeNull();
    tx5Setting('attendance.allow_corrections_after_lock', true);
    expect($this->roster->assignPattern([$this->asha], '2026-10-12', '2026-10-13', $this->night, [], false, 'Cover', $this->hr)['refused'])->toBe([])
        ->and(AttendanceRecord::query()->where('employee_id', $this->asha->id)->whereDate('date', '2026-10-12')->first())->shift_id->toBe($this->general->id)->status->toBe('present');
    tx5Setting('attendance.allow_corrections_after_lock', false);

    // Closed attendance month (TX-3): refused while month close is on, unless corrections after lock are allowed.
    tx5Setting('attendance.month_close.enabled', true);
    AttendanceMonthClose::create(['company_id' => $this->company->id, 'year' => 2026, 'month' => 9, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'closed']);
    $closed = $this->roster->assignPattern([$this->ravi], '2026-09-29', '2026-10-01', $this->night, [], false, 'Cover', $this->hr);
    expect($closed['refused'][0]['reason'])->toContain('closed attendance month')->and(tx5Override($this->ravi, '2026-10-01'))->toBeNull();
    tx5Setting('attendance.allow_corrections_after_lock', true);
    expect($this->roster->assignPattern([$this->ravi], '2026-09-29', '2026-10-01', $this->night, [], false, 'Cover', $this->hr)['refused'])->toBe([]);
    tx5Setting('attendance.allow_corrections_after_lock', false);

    // Outside the actor's access scope.
    $scoped = tenantUser($this->tenant, ['attendance.view', 'attendance.roster']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);
    $outside = $this->roster->assignPattern([$this->ravi], '2026-10-19', '2026-10-19', $this->night, [], false, 'Cover', $scoped);
    expect($outside['refused'][0]['reason'])->toBe('Outside your access scope.')->and(tx5Override($this->ravi, '2026-10-19'))->toBeNull();
    $this->actingAs($this->hr);

    // Not employed: before joining (pre-boarding), after leaving, and only the employed days of a joiner's range.
    $future = tx5Hire('Kiran', $this->delhi, 'EMP3', '2026-11-02');
    $gone = forceLifecycle(tx5Hire('Gopal', $this->delhi, 'EMP6', '2024-01-01'), LifecycleState::Exited, ['exit_date' => '2026-09-30']);
    $joiner = forceLifecycle(tx5Hire('Meera', $this->delhi, 'EMP7', '2026-10-21'), LifecycleState::Probation);
    $people = $this->roster->assignPattern([$future, $gone, $joiner], '2026-10-19', '2026-10-25', $this->night, [], false, 'Cover', $this->hr);
    expect(collect($people['refused'])->pluck('reason', 'employee_id')->all())->toBe([
        $future->id => 'Not active: the employee is preboarding.',
        $gone->id => 'Not active on any day from 19 Oct 2026 to 25 Oct 2026 (left 30 Sep 2026).',
    ])->and($people['done'][0]['message'])->toBe('5 day(s) rostered: 5 on Night; 2 day(s) outside the employment left as they were.')
        ->and(ShiftOverride::query()->where('employee_id', $joiner->id)->orderBy('date')->value('date')->toDateString())->toBe('2026-10-21');
});

it('runs large selections as a WO-1 bulk run with the typed count, each employee refused or done on their own', function () {
    config(['peopleos.bulk.confirm_over' => 1]);
    AttendanceRecord::create(['employee_id' => $this->asha->id, 'date' => '2026-10-12', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);

    Livewire::test(RosterBoard::class)
        ->callTableBulkAction('assignShift', [$this->asha, $this->ravi], data: ['from' => '2026-10-12', 'to' => '2026-10-18', 'shift_id' => $this->night->id,
            'weekly_off_days' => ['sat', 'sun'], 'auto_shift' => true, 'reason' => 'Night week', 'confirm_count' => '2'])
        ->assertHasNoTableBulkActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->operation)->toBe('roster_board.assign_shift')->and($run->register)->toBe('roster_board')->and($run->status)->toBe('completed')
        ->and($items[$this->asha->id]->outcome)->toBe('refused')->and($items[$this->asha->id]->message)->toContain('locked')
        ->and($items[$this->ravi->id]->outcome)->toBe('done')->and($items[$this->ravi->id]->message)->toContain('5 on Night, 2 weekly off; automatic shift correction allowed')
        ->and(tx5Override($this->ravi, '2026-10-17')->shift_id)->toBeNull()->and(tx5Override($this->ravi, '2026-10-16')->allowsAutoShift())->toBeTrue()
        // Every audit event of the run carries the run's operation id.
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', ShiftOverride::class)->pluck('operation_id')->unique()->all())->toBe([$run->operation_id]);

    Livewire::test(RosterBoard::class)->callTableBulkAction('removeShift', [$this->asha, $this->ravi], data: ['from' => '2026-10-12', 'to' => '2026-10-18', 'reason' => 'Undo', 'confirm_count' => '2']);
    expect(BulkRun::query()->latest('id')->first()->operation)->toBe('roster_board.remove_shift')->and(tx5Override($this->ravi, '2026-10-16'))->toBeNull();

    // Without bulk.run a large selection does not start; a direct one still runs with attendance.roster alone.
    $this->actingAs(tenantUser($this->tenant, ['attendance.view', 'attendance.roster']));
    Livewire::test(RosterBoard::class)
        ->callTableBulkAction('assignShift', [$this->asha, $this->ravi], data: ['from' => '2026-10-19', 'to' => '2026-10-19', 'shift_id' => $this->night->id, 'reason' => 'Cover', 'confirm_count' => '2'])
        ->assertNotified('Not started');
    expect(tx5Override($this->ravi, '2026-10-19'))->toBeNull();
    config(['peopleos.bulk.confirm_over' => 25]);
    Livewire::test(RosterBoard::class)
        ->callTableBulkAction('assignShift', [$this->asha, $this->ravi], data: ['from' => '2026-10-19', 'to' => '2026-10-19', 'shift_id' => $this->night->id, 'reason' => 'Cover'])
        ->assertHasNoTableBulkActionErrors();
    expect(tx5Override($this->ravi, '2026-10-19')->shift_id)->toBe($this->night->id);
});

// ---- Upload --------------------------------------------------------------------------------------------------------------

it('imports old roster files as before and reads the new weekly-off and automatic correction columns', function () {
    $old = tx5Run(tx5Stage("Employee code,Date,To date,Shift code (WO = weekly off)\nEMP1,2026-10-19,2026-10-20,GEN\n"));
    expect($old->rows()->value('result'))->toBe('2 day(s) on General')
        ->and(ShiftOverride::query()->where('employee_id', $this->asha->id)->get()->every(fn (ShiftOverride $o) => $o->getAttributes()['auto_shift'] === null && $o->shift_id === $this->general->id))->toBeTrue();

    $new = tx5Stage("Employee code,Date,To date,Shift code (WO = weekly off),Weekly off on,Auto shift correction\nEMP2,2026-10-19,2026-10-25,NGT,Sat;Sun,Yes\nEMP2,2026-10-26,,GEN,Funday,\nEMP2,2026-10-27,,GEN,,maybe\n");
    expect($new->create_count)->toBe(1)->and($new->error_count)->toBe(2)
        ->and($new->rows()->where('row_number', 2)->value('errors'))->toBe(["Weekly off on: 'funday' is not a weekday (use Mon … Sun)."])
        ->and($new->rows()->where('row_number', 3)->value('errors'))->toBe(['Auto shift correction must be yes or no.']);
    tx5Run($new);
    $days = ShiftOverride::query()->where('employee_id', $this->ravi->id)->orderBy('date')->get();
    expect($days)->toHaveCount(7)
        ->and($days->mapWithKeys(fn (ShiftOverride $o) => [$o->date->toDateString() => $o->shift_id])->only(['2026-10-23', '2026-10-24', '2026-10-25'])->all())
        ->toBe(['2026-10-23' => $this->night->id, '2026-10-24' => null, '2026-10-25' => null])
        ->and($days->every(fn (ShiftOverride $o) => $o->allowsAutoShift() && $o->source === 'import'))->toBeTrue()
        ->and(app(ShiftRosterImports::class)->format()['columns'])->toHaveCount(6);
});

// ---- Dataset, desk, boundaries -------------------------------------------------------------------------------------------

it('reports rostered days in the day_roster dataset, inside the reader\'s rights and scope', function () {
    $this->roster->assignPattern([$this->asha, $this->ravi], '2026-10-19', '2026-10-20', $this->night, ['tue'], true, 'Night cover', $this->hr);
    $registry = app(DatasetRegistry::class);
    $runner = app(ReportRunner::class);
    $dataset = $registry->get('day_roster');
    expect($dataset->category())->toBe('Attendance')->and($dataset->permissions())->toBe(['attendance.view'])
        ->and(config('peopleos.analytics.unattended_datasets.day_roster'))->toBeNull();

    $rows = collect($runner->execute('day_roster', ['fields' => ['employee_code', 'date', 'shift_code', 'shift', 'shift_timings', 'is_weekly_off', 'auto_shift', 'source', 'reason', 'changed_by', 'changed_on']], $this->hr)->rows);
    expect($rows)->toHaveCount(4)
        ->and($rows->firstWhere(fn ($r) => $r['employee_code'] === 'EMP1' && $r['date'] === '2026-10-19'))->toMatchArray(['shift_code' => 'NGT', 'shift_timings' => '21:00–06:00', 'is_weekly_off' => false,
            'auto_shift' => true, 'source' => 'Roster', 'reason' => 'Night cover', 'changed_by' => $this->hr->name, 'changed_on' => '2026-10-14'])
        ->and($rows->firstWhere(fn ($r) => $r['employee_code'] === 'EMP1' && $r['date'] === '2026-10-20'))->toMatchArray(['shift_code' => 'WO', 'shift' => 'Weekly off', 'is_weekly_off' => true]);
    foreach (['day_roster_this_month', 'day_roster_changes'] as $key) {
        expect($runner->execute('day_roster', app(ReportLibrary::class)->find($key)['definition'], $this->hr)->rows)->toHaveCount(4, $key);
    }

    expect(fn () => $runner->execute('day_roster', ['fields' => ['employee_code']], tenantUser($this->tenant, ['analytics.reports'])))->toThrow(RuntimeException::class, 'cannot report')
        ->and(fn () => $runner->execute('day_roster', ['fields' => ['employee_code']], null))->toThrow(RuntimeException::class, 'not available without a signed-in user');
    $scoped = tenantUser($this->tenant, ['attendance.view', 'analytics.reports']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);
    expect(collect($runner->execute('day_roster', ['fields' => ['employee_code']], $scoped)->rows)->pluck('employee_code')->unique()->values()->all())->toBe(['EMP1']);
});

it('lists the board on the time office desk and the shift roster register for people who may open it', function () {
    $rosterer = tenantUser($this->tenant, ['attendance.view', 'attendance.roster']);
    $this->actingAs($rosterer);
    $labels = collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Shift rosters')->toContain('Roster board');
    Livewire::test(ListShiftOverrides::class)->assertActionVisible('rosterBoard')->assertActionVisible('roster');
    $this->get(RosterBoard::getUrl())->assertOk()->assertSee('Roster board');
});

it('keeps TX-5 attendance code free of payroll words and needs no new table', function () {
    $files = ['Domain/Attendance/Services/ShiftRoster.php', 'Domain/Attendance/Services/ShiftResolver.php', 'Domain/Attendance/Services/RosterGrid.php',
        'Domain/Attendance/Models/ShiftOverride.php', 'Domain/Attendance/Imports/ShiftRosterImports.php',
        'Domain/Experience/BulkOperations/AssignShiftOperation.php', 'Domain/Experience/BulkOperations/RemoveShiftOperation.php'];
    foreach ($files as $file) {
        expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
    expect(Schema::hasColumn('attendance_shift_overrides', 'auto_shift'))->toBeTrue();
});
