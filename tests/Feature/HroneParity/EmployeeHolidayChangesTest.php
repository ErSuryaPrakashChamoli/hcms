<?php

use App\Domain\Attendance\Jobs\ProcessAttendanceDay;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\EmployeeHolidayChange;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\HolidayLibraryEntry;
use App\Domain\Attendance\Models\HolidayLibraryList;
use App\Domain\Attendance\Services\AttendanceGlance;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\EmployeeHolidayChanges;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Attendance\Services\HolidayYear;
use App\Domain\Attendance\Services\RosterGrid;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Experience\BulkOperations\ChangeHolidayOperation;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Offboarding\Support\DataInventory;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Pages\AttendanceCalendar;
use App\Filament\Pages\HolidayChanges;
use App\Filament\Pages\MyHolidays;
use App\Filament\Pages\TimeOfficeDesk;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';

/*
| HROne parity TX-12 (matrix 24 §24.7.2 #1–4, 6, 7; §24.8 #10; §24.13 #5 — "Manage holiday" / "Change holiday"; owner
| decision TIM-3 / D-TX3): HR assigns, removes or replaces a holiday for chosen employees on top of their holiday calendar
| (employee_holiday_changes, read by HolidayResolver after the calendar — the change wins), within the back-dating limit
| `attendance.holiday_change_backdate_months` (2); processed days are recalculated; the changes are listed with Undo; My
| holidays, the attendance calendar, the roster board and the glance show them. Managers only with `team.holiday_assign`.
| Today is Wednesday 14 Oct 2026; the schedule is Monday to Friday, General 09:00–18:00.
*/

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create(['name' => 'Acme India']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi', 'code' => 'DEL']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai', 'code' => 'MUM']);
    $this->schedule = weeklySchedule(generalShift());
    $this->asha = txholHire('Asha', $this->delhi, 'EMP1');
    $this->ravi = txholHire('Ravi', $this->mumbai, 'EMP2');
    foreach ([$this->asha, $this->ravi] as $employee) {
        assignSchedule($employee, $this->schedule);
    }
    $this->calendar = HolidayCalendar::create(['name' => 'India 2026', 'code' => 'IN26', 'year' => 2026, 'optional_requires_selection' => true, 'restricted_holiday_quota' => 2]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $this->calendar->id, 'name' => 'All', 'priority' => 1, 'conditions' => [], 'status' => 'active']);
    $holiday = fn (string $date, string $name, string $type) => Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'name' => $name, 'date' => $date, 'type' => $type]);
    $this->gandhi = $holiday('2026-10-02', 'Gandhi Jayanti', 'public');   // Friday, past
    $this->dussehra = $holiday('2026-10-20', 'Dussehra', 'company');      // Tuesday, coming
    $this->karva = $holiday('2026-10-29', 'Karva Chauth', 'optional');    // Thursday, restricted (needs choosing)
    $this->changes = app(EmployeeHolidayChanges::class);
});

function txholHire(string $first, Location $location, string $code, string $joining = '2025-01-01'): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joining, 'employee_code' => $code],
        ['company_id' => $location->company_id, 'location_id' => $location->id]);
}

function txholSetting(string $key, mixed $value): void
{
    app(SettingsRepository::class)->set($key, $value, 'test');
}

function txholDay(Employee $employee, string $date): ?AttendanceRecord
{
    return AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();
}

function txholHoliday(Employee $employee, string $date): ?string
{
    return app(HolidayResolver::class)->holidayOn($employee, Carbon::parse($date))?->name;
}

/** Assign / remove / replace through the service, as HR. */
function txholApply(object $test, array $employees, array $data, string $reason = 'Local holiday', $actor = null): array
{
    return $test->changes->apply($employees, $test->changes->prepare($data), $reason, $actor ?? $test->hr);
}

function txholTyped(string $name, string $date, bool $restricted = false, string $action = 'assign', ?int $remove = null): array
{
    return array_filter(['action' => $action, 'source' => 'typed', 'name' => $name, 'date' => $date, 'restricted' => $restricted, 'remove_holiday_id' => $remove], fn ($v) => $v !== null);
}

// ---- Defaults --------------------------------------------------------------------------------------------------------

it('keeps every holiday coming from the calendars by default: no change rows, the same answers and calculation basis', function () {
    expect(Schema::hasTable('employee_holiday_changes'))->toBeTrue()->and(EmployeeHolidayChange::query()->count())->toBe(0)
        ->and(app(SettingsRepository::class)->get('attendance.holiday_change_backdate_months'))->toBe(2)
        ->and(app(SettingsRepository::class)->get('team.holiday_assign'))->toBeFalse()
        ->and(collect(config('peopleos_setup.modules.time_office.settings'))->pluck('key'))->toContain('attendance.holiday_change_backdate_months', 'team.holiday_assign');

    $resolver = app(HolidayResolver::class);
    foreach (['2026-10-02', '2026-10-12', '2026-10-20', '2026-10-29'] as $date) {
        $day = $resolver->dayOn($this->asha, Carbon::parse($date));
        expect($day['change'])->toBeNull()
            ->and($day['holiday']?->id)->toBe($resolver->calendarHolidayOn($this->asha, Carbon::parse($date))?->id)
            ->and($resolver->holidayOn($this->asha, Carbon::parse($date))?->id)->toBe($day['holiday']?->id);
    }
    expect(txholHoliday($this->asha, '2026-10-29'))->toBeNull(); // a restricted holiday not chosen is not a day off

    $record = app(AttendanceProcessor::class)->process($this->asha, '2026-10-02');
    expect($record->status)->toBe('holiday')->and($record->holiday_name)->toBe('Gandhi Jayanti')
        ->and($record->calculation_basis)->not->toHaveKey('holiday_change_id')->and($record->calculation_basis['holiday_id'])->toBe($this->gandhi->id);
});

// ---- Assign, remove, replace for chosen people only -------------------------------------------------------------------

it('assigns a typed holiday to the chosen employees only and recalculates their processed day', function () {
    app(AttendanceProcessor::class)->process($this->asha, '2026-10-12');
    app(AttendanceProcessor::class)->process($this->ravi, '2026-10-12');
    expect(txholDay($this->asha, '2026-10-12')->status)->toBe('absent');

    $result = txholApply($this, [$this->asha], txholTyped('Foundation Day', '2026-10-12'), 'Office anniversary');

    $change = EmployeeHolidayChange::query()->sole();
    expect($result['refused'])->toBe([])
        ->and($result['done'][0]['message'])->toBe('Holiday given: Foundation Day (Mon, 12 Oct 2026); 1 processed day(s) recalculated.')
        ->and($change->only(['employee_id', 'action', 'effect', 'name', 'holiday_type', 'is_restricted', 'source', 'reason', 'status', 'created_by']))
        ->toBe(['employee_id' => $this->asha->id, 'action' => 'assign', 'effect' => 'add', 'name' => 'Foundation Day', 'holiday_type' => 'company', 'is_restricted' => false,
            'source' => 'typed', 'reason' => 'Office anniversary', 'status' => 'active', 'created_by' => $this->hr->id])
        ->and(txholHoliday($this->asha, '2026-10-12'))->toBe('Foundation Day')
        ->and(txholHoliday($this->ravi, '2026-10-12'))->toBeNull();

    $day = txholDay($this->asha, '2026-10-12');
    expect($day->status)->toBe('holiday')->and($day->holiday_name)->toBe('Foundation Day')
        ->and($day->calculation_basis['holiday_change_id'])->toBe($change->id)->and($day->calculation_basis['holiday_id'])->toBeNull()
        ->and(txholDay($this->ravi, '2026-10-12')->status)->toBe('absent');

    // Audited, with the reason, in one operation.
    $event = AuditEvent::query()->withoutGlobalScopes()->where('entity_type', EmployeeHolidayChange::class)->sole();
    expect($event->reason)->toBe('Office anniversary')->and($event->operation_id)->not->toBeNull();
});

it('removes a calendar holiday for the chosen employee only', function () {
    app(AttendanceProcessor::class)->process($this->ravi, '2026-10-02');
    expect(txholDay($this->ravi, '2026-10-02')->status)->toBe('holiday');

    $result = txholApply($this, [$this->ravi], ['action' => 'remove', 'remove_holiday_id' => $this->gandhi->id], 'Works the site shutdown');

    expect($result['done'][0]['message'])->toBe('Holiday taken away: Gandhi Jayanti (Fri, 02 Oct 2026); 1 processed day(s) recalculated.')
        ->and(EmployeeHolidayChange::query()->sole()->only(['effect', 'holiday_id', 'holiday_type', 'source']))->toBe(['effect' => 'remove', 'holiday_id' => $this->gandhi->id, 'holiday_type' => 'public', 'source' => 'calendar'])
        ->and(txholHoliday($this->ravi, '2026-10-02'))->toBeNull()
        ->and(txholHoliday($this->asha, '2026-10-02'))->toBe('Gandhi Jayanti')
        ->and(txholDay($this->ravi, '2026-10-02')->status)->toBe('absent')
        ->and(txholDay($this->ravi, '2026-10-02')->holiday_name)->toBeNull();
});

it('replaces a holiday with another day as one change of two rows, undone together', function () {
    $result = txholApply($this, [$this->asha], txholTyped('Local fair', '2026-10-23', true, 'replace', $this->dussehra->id));
    $rows = EmployeeHolidayChange::query()->orderBy('id')->get();

    expect($result['done'][0]['message'])->toBe('Holiday taken away: Dussehra (Tue, 20 Oct 2026); Holiday given: Local fair (Fri, 23 Oct 2026), restricted.')
        ->and($rows)->toHaveCount(2)->and($rows->pluck('effect')->all())->toBe(['remove', 'add'])->and($rows->pluck('action')->unique()->all())->toBe(['replace'])
        ->and($rows->pluck('group_key')->unique())->toHaveCount(1)
        ->and(txholHoliday($this->asha, '2026-10-20'))->toBeNull()
        ->and(app(HolidayResolver::class)->holidayOn($this->asha, Carbon::parse('2026-10-23'))?->type)->toBe('optional')
        ->and(txholHoliday($this->ravi, '2026-10-20'))->toBe('Dussehra');

    // Undo from either row undoes both, with who, when and why; the calendar decides again.
    $this->changes->undo($rows[1], 'Fair moved', $this->hr);
    $rows = EmployeeHolidayChange::query()->orderBy('id')->get();
    expect($rows->pluck('status')->unique()->all())->toBe(['undone'])->and($rows->pluck('active')->unique()->all())->toBe([null])
        ->and($rows->pluck('undo_reason')->unique()->all())->toBe(['Fair moved'])->and($rows->pluck('undone_by')->unique()->all())->toBe([$this->hr->id])
        ->and(txholHoliday($this->asha, '2026-10-20'))->toBe('Dussehra')->and(txholHoliday($this->asha, '2026-10-23'))->toBeNull()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', EmployeeHolidayChange::class)->where('reason', 'Fair moved')->count())->toBe(2)
        ->and(fn () => $this->changes->undo($rows[0]->refresh(), 'Again', $this->hr))->toThrow(RuntimeException::class, 'This change was already undone.');

    // The day is free again for a new change.
    expect(txholApply($this, [$this->asha], txholTyped('Local fair', '2026-10-23'))['done'])->toHaveCount(1);
});

it('picks the holiday from a holiday calendar or the published holiday library', function () {
    $mumbai = HolidayCalendar::create(['name' => 'Mumbai 2026', 'code' => 'MUM26', 'year' => 2026]);
    $ganesh = Holiday::create(['holiday_calendar_id' => $mumbai->id, 'name' => 'Ganesh Chaturthi', 'date' => '2026-09-14', 'type' => 'public']);
    txholApply($this, [$this->asha], ['action' => 'assign', 'source' => 'calendar', 'holiday_id' => $ganesh->id]);
    expect(EmployeeHolidayChange::query()->sole()->only(['name', 'holiday_type', 'source', 'holiday_id']))->toBe(['name' => 'Ganesh Chaturthi', 'holiday_type' => 'public', 'source' => 'calendar', 'holiday_id' => $ganesh->id])
        ->and(txholHoliday($this->asha, '2026-09-14'))->toBe('Ganesh Chaturthi');

    $list = HolidayLibraryList::create(['country_code' => 'IN', 'year' => 2026, 'region_code' => HolidayLibraryList::NATIONAL, 'status' => 'published', 'published_at' => now()]);
    $entry = HolidayLibraryEntry::create(['holiday_library_list_id' => $list->id, 'date' => '2026-11-24', 'name' => 'Guru Tegh Bahadur Martyrdom', 'type' => 'optional']);
    txholApply($this, [$this->ravi], ['action' => 'assign', 'source' => 'library', 'library_entry_id' => $entry->id, 'restricted' => true]);
    expect(EmployeeHolidayChange::query()->where('employee_id', $this->ravi->id)->sole()->only(['name', 'holiday_type', 'is_restricted', 'source', 'holiday_library_entry_id']))
        ->toBe(['name' => 'Guru Tegh Bahadur Martyrdom', 'holiday_type' => 'optional', 'is_restricted' => true, 'source' => 'library', 'holiday_library_entry_id' => $entry->id])
        ->and(HolidayChanges::libraryOptions())->toHaveKey('India · National 2026')
        ->and(collect(HolidayChanges::calendarHolidayOptions())->keys()->all())->toContain('India 2026', 'Mumbai 2026');

    // A withdrawn list is no longer offered, whatever id the form sends.
    $list->update(['status' => 'withdrawn']);
    expect(fn () => $this->changes->prepare(['action' => 'assign', 'source' => 'library', 'library_entry_id' => $entry->id]))->toThrow(RuntimeException::class, 'Choose the holiday from the holiday library.');
});

// ---- Precedence (D-TX3) -----------------------------------------------------------------------------------------------

it('lets the employee\'s change win over the calendar for that date, and only for that employee', function () {
    txholApply($this, [$this->asha], txholTyped('Foundation Day', '2026-10-12'));
    // The calendar later gets a holiday on the same day: the employee keeps the holiday HR gave them.
    Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'name' => 'Calendar Day', 'date' => '2026-10-12', 'type' => 'company']);
    $day = app(HolidayResolver::class)->dayOn($this->asha, Carbon::parse('2026-10-12'));
    expect($day['holiday']->name)->toBe('Foundation Day')->and($day['change']->name)->toBe('Foundation Day')
        ->and(app(HolidayResolver::class)->calendarHolidayOn($this->asha, Carbon::parse('2026-10-12'))->name)->toBe('Calendar Day')
        ->and(txholHoliday($this->ravi, '2026-10-12'))->toBe('Calendar Day');

    // A removal wins over the calendar holiday of the day.
    txholApply($this, [$this->asha], ['action' => 'remove', 'remove_holiday_id' => $this->dussehra->id]);
    expect(app(HolidayResolver::class)->dayOn($this->asha, Carbon::parse('2026-10-20')))->toMatchArray(['holiday' => null])
        ->and(app(HolidayResolver::class)->dayOn($this->asha, Carbon::parse('2026-10-20'))['change']->effect)->toBe('remove');
});

// ---- Refusals ---------------------------------------------------------------------------------------------------------

it('refuses each employee on their own with the reason', function () {
    $joiner = txholHire('Neel', $this->delhi, 'EMP3', '2026-10-13');
    $hrEmployee = txholHire('Hari', $this->delhi, 'EMP4');
    $hrEmployee->update(['user_id' => $this->hr->id]);
    AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-09', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);

    $result = txholApply($this, [$this->asha, $joiner, $hrEmployee->refresh()], txholTyped('Foundation Day', '2026-10-12'));
    expect(collect($result['refused'])->pluck('reason', 'employee_id')->all())->toBe([
        $joiner->id => 'Not employed on 12 Oct 2026 (joins 13 Oct 2026).',
        $hrEmployee->id => 'You cannot change your own holidays.',
    ])->and(collect($result['done'])->pluck('employee_id')->all())->toBe([$this->asha->id]);

    $refusal = fn (array $data, Employee $employee) => txholApply($this, [$employee], $data)['refused'][0]['reason'] ?? null;
    expect($refusal(txholTyped('Again', '2026-10-12'), $this->asha))->toBe('Already changed on 12 Oct 2026 (Holiday given: Foundation Day (Mon, 12 Oct 2026)): undo that change first.')
        ->and($refusal(txholTyped('Twice', '2026-10-02'), $this->ravi))->toBe('Already a holiday on 02 Oct 2026 (Gandhi Jayanti).')
        ->and($refusal(['action' => 'remove', 'remove_holiday_id' => $this->karva->id], $this->ravi))->toBe('No holiday on 29 Oct 2026 on the employee\'s holiday calendar.')
        ->and($refusal(txholTyped('Locked', '2026-10-09'), $this->ravi))->toBe('09 Oct 2026 is locked (finalized days).');

    // A restricted holiday the employee chose: their request is cancelled instead.
    $priya = employeeWithUser(null, config('peopleos.roles.employee.permissions'));
    assignSchedule($priya, $this->schedule);
    $requests = app(TimeOfficeRequests::class);
    $requests->approve($requests->request($priya, 'restricted_holiday', ['holiday_id' => $this->karva->id], 'Festival', $priya->user), 'ok', $this->hr);
    expect(txholHoliday($priya, '2026-10-29'))->toBe('Karva Chauth')
        ->and($refusal(['action' => 'remove', 'remove_holiday_id' => $this->karva->id], $priya))->toBe('Karva Chauth on 29 Oct 2026 is a restricted holiday the employee chose: cancel their restricted-holiday request instead.');

    // Corrections after lock: the locked day takes the change (processing keeps its values and records any arrear).
    txholSetting('attendance.allow_corrections_after_lock', true);
    expect(txholApply($this, [$this->ravi], txholTyped('Locked', '2026-10-09'))['done'])->toHaveCount(1);

    // Whole-change refusals.
    expect(fn () => $this->changes->prepare(txholTyped('Same day', '2026-10-20', false, 'replace', $this->dussehra->id)))->toThrow(RuntimeException::class, 'The new holiday must be on another day than the one it replaces.')
        ->and(fn () => $this->changes->prepare(['action' => 'assign', 'source' => 'typed', 'date' => '2026-10-21']))->toThrow(RuntimeException::class, 'Give the holiday a name.')
        ->and(fn () => $this->changes->prepare(['action' => 'remove']))->toThrow(RuntimeException::class, 'Choose the holiday to remove.')
        ->and(fn () => $this->changes->prepare(['action' => 'swap']))->toThrow(RuntimeException::class, 'Choose assign, remove or replace holiday.')
        ->and(fn () => $this->changes->apply([$this->asha], $this->changes->prepare(txholTyped('X', '2026-10-21')), '  ', $this->hr))->toThrow(RuntimeException::class, 'Give the reason for the change.');
});

it('keeps changes within the back-dating limit (two months before the current month by default) and up to the end of next year', function () {
    expect($this->changes->earliestDate()->toDateString())->toBe('2026-08-01')->and($this->changes->latestDate()->toDateString())->toBe('2027-12-31')
        ->and(fn () => $this->changes->prepare(txholTyped('Too old', '2026-07-31')))->toThrow(RuntimeException::class, '31 Jul 2026 is before 01 Aug 2026: holidays further back cannot be changed here. Ask your administrator.')
        ->and(fn () => $this->changes->prepare(txholTyped('Too far', '2028-01-03')))->toThrow(RuntimeException::class, 'Choose a day up to 31 Dec 2027.')
        ->and($this->changes->prepare(txholTyped('Just in', '2026-08-03'))['add']['date'])->toBe('2026-08-03');

    // A calendar holiday further back cannot be removed either; nor is it offered.
    $old = Holiday::create(['holiday_calendar_id' => $this->calendar->id, 'name' => 'Independence Day', 'date' => '2026-07-15', 'type' => 'public']);
    expect(fn () => $this->changes->prepare(['action' => 'remove', 'remove_holiday_id' => $old->id]))->toThrow(RuntimeException::class, 'is before 01 Aug 2026')
        ->and(HolidayChanges::calendarHolidayOptions()['India 2026'])->not->toHaveKey($old->id)->toHaveKey($this->gandhi->id);

    // The setting: 0 = from this month; a change made earlier cannot be undone once it falls behind the limit.
    txholApply($this, [$this->asha], txholTyped('Monsoon day', '2026-09-30'));
    txholSetting('attendance.holiday_change_backdate_months', 0);
    expect($this->changes->earliestDate()->toDateString())->toBe('2026-10-01')
        ->and(fn () => $this->changes->prepare(txholTyped('September', '2026-09-30')))->toThrow(RuntimeException::class, 'is before 01 Oct 2026')
        ->and(fn () => $this->changes->undo(EmployeeHolidayChange::query()->sole(), 'Mistake', $this->hr))->toThrow(RuntimeException::class, '30 Sep 2026 is before 01 Oct 2026');
    // A prepared change checked again when applied later (a bulk run): the limit of that moment applies.
    expect(fn () => $this->changes->apply([$this->ravi], ['action' => 'assign', 'remove' => null, 'add' => ['date' => '2026-09-29', 'name' => 'Late', 'type' => 'company', 'restricted' => false, 'source' => 'typed']], 'x', $this->hr))
        ->toThrow(RuntimeException::class, 'is before 01 Oct 2026');
    txholSetting('attendance.holiday_change_backdate_months', 99);
    expect($this->changes->backdateMonths())->toBe(24);
});

// ---- Reprocessing ----------------------------------------------------------------------------------------------------

it('recalculates only the days already processed, through the attendance processing job', function () {
    app(AttendanceProcessor::class)->process($this->asha, '2026-10-12');
    Queue::fake();

    // Replace: the processed day (12 Oct) is queued; the coming day (20 Oct) and an unprocessed past day are not.
    txholApply($this, [$this->asha], ['action' => 'replace', 'remove_holiday_id' => $this->dussehra->id, 'source' => 'typed', 'name' => 'Foundation Day', 'date' => '2026-10-12']);
    txholApply($this, [$this->ravi], txholTyped('Foundation Day', '2026-10-12'));
    Queue::assertPushed(ProcessAttendanceDay::class, 1);
    Queue::assertPushed(ProcessAttendanceDay::class, fn (ProcessAttendanceDay $job) => $job->employeeId === $this->asha->id && $job->date === '2026-10-12' && $job->reason === 'holiday_change'
        && $job->tenantId === $this->tenant->id);

    // Undo recalculates the day again.
    $this->changes->undo(EmployeeHolidayChange::query()->where('employee_id', $this->asha->id)->firstOrFail(), 'Mistake', $this->hr);
    Queue::assertPushed(ProcessAttendanceDay::class, 2);
});

// ---- My holidays, the calendar, the board and the glance ---------------------------------------------------------------

it('shows the employee\'s changed holidays on My holidays, the attendance calendar, the roster board and the glance', function () {
    $priya = employeeWithUser(null, config('peopleos.roles.employee.permissions'));
    assignSchedule($priya, $this->schedule);
    txholApply($this, [$priya], txholTyped('Team day', '2026-11-13'));
    txholApply($this, [$priya], txholTyped('Chhath', '2026-11-16', true));
    txholApply($this, [$priya], ['action' => 'remove', 'remove_holiday_id' => $this->dussehra->id]);

    $year = app(HolidayYear::class)->for($priya, 2026);
    expect(collect($year['fixed'])->pluck('name')->all())->toBe(['Gandhi Jayanti', 'Team day'])
        ->and(collect($year['fixed'])->firstWhere('name', 'Team day'))->toMatchArray(['id' => null, 'type' => 'Company holiday', 'note' => 'Given to you by HR', 'weekday' => 'Friday', 'past' => false])
        ->and(collect($year['restricted'])->pluck('choice', 'name')->all())->toBe(['Karva Chauth' => 'not_chosen', 'Chhath' => 'given'])
        ->and(collect($year['removed'])->pluck('name')->all())->toBe(['Dussehra'])
        ->and(collect(app(HolidayYear::class)->for($this->asha, 2026)['fixed'])->pluck('name')->all())->toBe(['Gandhi Jayanti', 'Dussehra'])
        ->and(app(HolidayYear::class)->for($this->asha, 2026)['removed'])->toBe([]);

    $this->actingAs($priya->user);
    $this->get(MyHolidays::getUrl())->assertOk()->assertSee('Fixed holidays (2)')->assertSee('Restricted holidays (2)')->assertSee('Team day')->assertSee('Given to you by HR')
        ->assertSee('Not holidays for you this year')->assertSee('Dussehra');
    Livewire::test(MyHolidays::class)->call('setTab', 'restricted')->assertSee('Chhath')->assertSee('Given by HR');

    // No calendar applies any more: the holidays HR gave are still listed.
    HolidayCalendarRule::query()->update(['status' => 'inactive']);
    $this->get(MyHolidays::getUrl())->assertOk()->assertSee('Team day')->assertDontSee('No holiday calendar applies to you');
    HolidayCalendarRule::query()->update(['status' => 'active']);

    // HR's attendance calendar of the employee, the roster board and the glance.
    $this->actingAs($this->hr);
    $this->get(AttendanceCalendar::getUrl(['employee' => $priya->id, 'month' => '2026-11']))->assertOk()->assertSee('Team day')->assertSee('Company holiday · given by HR');
    $this->get(AttendanceCalendar::getUrl(['employee' => $priya->id, 'month' => '2026-10']))->assertOk()->assertSee('Taken away for this employee');
    $cells = app(RosterGrid::class)->cells([$priya, $this->asha], Carbon::parse('2026-10-19'), Carbon::parse('2026-11-16'));
    expect($cells[$priya->id]['2026-11-13']['holiday'])->toBe('Team day')->and($cells[$priya->id]['2026-10-20']['holiday'])->toBeNull()
        ->and($cells[$this->asha->id]['2026-10-20']['holiday'])->toBe('Dussehra')->and($cells[$this->asha->id]['2026-11-13']['holiday'])->toBeNull()
        ->and(app(AttendanceGlance::class)->offDays($priya, Carbon::parse('2026-11-16'), 1))->toEqual([['date' => Carbon::parse('2026-11-16'), 'kind' => 'holiday', 'label' => 'Chhath (restricted holiday)']]);
});

// ---- The page ---------------------------------------------------------------------------------------------------------

it('changes holidays from the page wizard for the employees chosen by codes, search or filters, and lists each result', function () {
    $meena = txholHire('Meena', $this->delhi, 'EMP3');
    Livewire::test(HolidayChanges::class)->assertOk()
        ->callAction('changeHoliday', data: ['action' => 'assign', 'codes' => 'EMP1, EMP2', 'source' => 'typed', 'name' => 'Foundation Day', 'date' => '2026-10-12', 'restricted' => false, 'reason' => 'Anniversary'])
        ->assertHasNoActionErrors()
        ->assertSet('result.title', 'Assign holiday: Foundation Day on Mon, 12 Oct 2026')
        ->assertSee('Holiday given: Foundation Day (Mon, 12 Oct 2026).');
    expect(EmployeeHolidayChange::query()->pluck('employee_id')->sort()->values()->all())->toBe([$this->asha->id, $this->ravi->id]);

    // Organisation filter (location Delhi) with a search pick; one of them refused (already changed).
    Livewire::test(HolidayChanges::class)
        ->callAction('changeHoliday', data: ['action' => 'assign', 'location_id' => [$this->delhi->id], 'source' => 'typed', 'name' => 'Foundation Day', 'date' => '2026-10-12', 'reason' => 'Anniversary'])
        ->assertHasNoActionErrors()
        ->assertSet('result.done.0.employee_id', $meena->id)->assertSet('result.refused.0.employee_id', $this->asha->id);
    expect(HolidayChanges::chosenIds(['employee_ids' => [$this->ravi->id], 'codes' => 'EMP3'], $this->hr))->toBe([$this->ravi->id, $meena->id])
        ->and(fn () => HolidayChanges::chosenIds([], $this->hr))->toThrow(RuntimeException::class, 'Choose the employees')
        ->and(count(HolidayChanges::chosenIds(['everyone' => true], $this->hr)))->toBe(3);

    // The list with its filters, and Undo from a row (reason required).
    $change = EmployeeHolidayChange::query()->where('employee_id', $meena->id)->sole();
    Livewire::test(HolidayChanges::class)->assertCanSeeTableRecords(EmployeeHolidayChange::all())
        ->filterTable('status', 'active')->assertCanSeeTableRecords([$change])
        ->callTableAction('undo', $change, data: ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    Livewire::test(HolidayChanges::class)->callTableAction('undo', $change, data: ['reason' => 'Wrong office'])->assertHasNoTableActionErrors()->assertNotified('Holiday change undone');
    expect($change->refresh()->status)->toBe('undone')->and(txholHoliday($meena, '2026-10-12'))->toBeNull();
    Livewire::test(HolidayChanges::class)->assertTableActionHidden('undo', $change)->filterTable('status', 'undone')->assertCanSeeTableRecords([$change]);
});

it('opens the wizard from ?action=changeHoliday and from the time office desk and the setup centre', function () {
    // The page mounts the URL's action on load (Filament's wire:init), and the action mounts by its name.
    $this->get(HolidayChanges::getUrl(['action' => 'changeHoliday']))->assertOk()->assertSee('Holiday changes')->assertSee("mountAction('changeHoliday'", false);
    Livewire::test(HolidayChanges::class)->mountAction('changeHoliday')->assertActionMounted('changeHoliday');

    $labels = collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Holiday changes')
        ->and(TimeOfficeDesk::OPERATOR_PERMISSIONS)->toContain('attendance.holiday_change')
        ->and(collect(config('peopleos_setup.modules.time_office.links'))->pluck('target')->all())->toContain(HolidayChanges::class);
});

it('runs large selections as a WO-1 bulk run with the typed count, each employee done or refused', function () {
    config(['peopleos.bulk.confirm_over' => 1]);
    AttendanceRecord::create(['employee_id' => $this->ravi->id, 'date' => '2026-10-12', 'status' => 'present', 'is_locked' => true, 'processed_at' => now()]);

    Livewire::test(HolidayChanges::class)
        ->callAction('changeHoliday', data: ['action' => 'assign', 'codes' => 'EMP1 EMP2', 'source' => 'typed', 'name' => 'Foundation Day', 'date' => '2026-10-12', 'reason' => 'Anniversary', 'confirm_count' => '2'])
        ->assertHasNoActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    $items = BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id');
    expect($run->operation)->toBe(ChangeHolidayOperation::KEY)->and($run->register)->toBe('holiday_changes')->and($run->status)->toBe('completed')
        ->and($items[$this->asha->id]->outcome)->toBe('done')->and($items[$this->asha->id]->message)->toBe('Holiday given: Foundation Day (Mon, 12 Oct 2026).')
        ->and($items[$this->ravi->id]->outcome)->toBe('refused')->and($items[$this->ravi->id]->message)->toBe('12 Oct 2026 is locked (finalized days).')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', EmployeeHolidayChange::class)->pluck('operation_id')->unique()->all())->toBe([$run->operation_id])
        ->and(app(BulkOperations::class)->get(ChangeHolidayOperation::KEY)->permission())->toBe('attendance.holiday_change');

    // Without bulk.run a large selection does not start.
    $this->actingAs(tenantUser($this->tenant, ['attendance.view', 'attendance.holiday_change']));
    Livewire::test(HolidayChanges::class)
        ->callAction('changeHoliday', data: ['action' => 'assign', 'codes' => 'EMP1 EMP2', 'source' => 'typed', 'name' => 'Diwali eve', 'date' => '2026-11-06', 'reason' => 'x', 'confirm_count' => '2'])
        ->assertNotified('Not changed');
    expect(EmployeeHolidayChange::query()->where('name', 'Diwali eve')->count())->toBe(0);
});

// ---- Rights and scope -------------------------------------------------------------------------------------------------

it('opens only with attendance.view and the holiday change right (attendance.manage grants it), inside the access scope', function () {
    $this->actingAs(tenantUser($this->tenant, ['attendance.view']));
    expect(HolidayChanges::canAccess())->toBeFalse();
    $this->get(HolidayChanges::getUrl())->assertForbidden();

    $this->actingAs(tenantUser($this->tenant, ['attendance.view', 'attendance.manage']));
    expect(HolidayChanges::canAccess())->toBeTrue();

    // Scoped to Delhi: only Delhi's people are chosen and listed; Mumbai's are refused.
    txholApply($this, [$this->ravi], txholTyped('Mumbai day', '2026-10-21'));
    $scoped = tenantUser($this->tenant, ['attendance.view', 'attendance.holiday_change']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->delhi->id]]);
    $this->actingAs($scoped);
    expect(HolidayChanges::chosenIds(['codes' => 'EMP1 EMP2'], $scoped))->toBe([$this->asha->id])
        ->and(txholApply($this, [$this->ravi], txholTyped('Try', '2026-10-22'), 'x', $scoped)['refused'][0]['reason'])->toBe('Outside your access scope.');
    Livewire::test(HolidayChanges::class)->assertOk()->assertCanNotSeeTableRecords(EmployeeHolidayChange::query()->withoutGlobalScopes()->get());
    txholApply($this, [$this->asha], txholTyped('Delhi day', '2026-10-22'), 'x', $scoped);
    Livewire::test(HolidayChanges::class)->assertCanSeeTableRecords(EmployeeHolidayChange::query()->where('employee_id', $this->asha->id)->get());
});

it('declares the narrower right split out of attendance.manage and gives it only to the roles holding the broad key', function () {
    expect(config('peopleos.permission_splits')['attendance.holiday_change'] ?? null)->toBe('attendance.manage')
        ->and(collect(config('peopleos.permissions.attendance'))->keys())->toContain('attendance.holiday_change')
        ->and(tenantUser($this->tenant, ['attendance.manage'])->hasPermission('attendance.holiday_change'))->toBeTrue()
        ->and(tenantUser($this->tenant, ['attendance.holiday_change'])->hasPermission('attendance.manage'))->toBeFalse();

    $keys = fn (string $slug) => Role::query()->where('slug', $slug)->firstOrFail()->permissions()->pluck('key');
    expect($keys('tenant-hr-admin'))->toContain('attendance.holiday_change')->and($keys('attendance-admin'))->toContain('attendance.holiday_change');
    foreach (['manager', 'employee', 'payroll-admin', 'auditor', 'hr-manager'] as $slug) {
        expect($keys($slug)->all())->not->toContain('attendance.holiday_change');
    }

    // A deploy introducing the key: a role the tenant took attendance.manage from gets nothing.
    $role = Role::query()->where('slug', 'tenant-hr-admin')->firstOrFail();
    $role->permissions()->detach(Permission::query()->whereIn('key', ['attendance.manage', 'attendance.holiday_change'])->pluck('id'));
    $role->forceFill(['template_keys' => array_values(array_diff($role->template_keys, ['attendance.holiday_change']))])->saveQuietly();
    $this->artisan('peopleos:sync-permissions')->assertSuccessful();
    actAsTenant($this->tenant);
    expect($keys('tenant-hr-admin'))->not->toContain('attendance.holiday_change');
});

it('lets a manager change the holidays of the people who report to them only with the team option', function () {
    $user = tenantUser($this->tenant, ['attendance.view', 'attendance.approve', 'attendance.regularise', 'employee.view', 'leave.view']);
    $manager = txholHire('Mohan', $this->delhi, 'MGR1');
    $manager->update(['user_id' => $user->id]);
    ReportingRelationship::create(['employee_id' => $this->asha->id, 'manager_id' => $manager->id, 'type' => 'line', 'is_primary' => true, 'effective_from' => '2025-01-01']);
    $this->actingAs($user);

    // Off (the default): no page, every change refused.
    expect(HolidayChanges::canAccess())->toBeFalse()
        ->and(fn () => txholApply($this, [$this->asha], txholTyped('Team outing', '2026-10-23'), 'x', $user))->toThrow(RuntimeException::class, 'You may not change holidays.');
    $this->get(HolidayChanges::getUrl())->assertForbidden();

    // On: their direct reports, with a reason; not other people; never their own.
    txholSetting('team.holiday_assign', true);
    expect(HolidayChanges::canAccess())->toBeTrue()
        ->and(HolidayChanges::chosenIds(['everyone' => true], $user))->toBe([$this->asha->id]);
    $result = txholApply($this, [$this->asha, $this->ravi, $manager->refresh()], txholTyped('Team outing', '2026-10-23'), 'Offsite', $user);
    expect(collect($result['done'])->pluck('employee_id')->all())->toBe([$this->asha->id])
        ->and(collect($result['refused'])->pluck('reason', 'employee_id')->all())->toBe([
            $this->ravi->id => 'You may change holidays only for the people who report to you.',
            $manager->id => 'You cannot change your own holidays.',
        ]);
    txholApply($this, [$this->ravi], txholTyped('Other team', '2026-10-26'));
    Livewire::test(HolidayChanges::class)->assertOk()
        ->assertCanSeeTableRecords(EmployeeHolidayChange::query()->where('employee_id', $this->asha->id)->get())
        ->assertCanNotSeeTableRecords(EmployeeHolidayChange::query()->where('employee_id', $this->ravi->id)->get());
    // No bulk run for managers: the operation needs the HR right.
    expect(app(BulkOperations::class)->allows($user, ChangeHolidayOperation::KEY))->toBeFalse();
});

// ---- Data and boundaries ----------------------------------------------------------------------------------------------

it('classifies the table, scopes the model and keeps TX-12 attendance code free of payroll words', function () {
    expect(DataInventory::TABLES['attendance'])->toContain('employee_holiday_changes');
    $files = ['Domain/Attendance/Services/EmployeeHolidayChanges.php', 'Domain/Attendance/Models/EmployeeHolidayChange.php', 'Domain/Attendance/Services/HolidayResolver.php',
        'Domain/Experience/BulkOperations/ChangeHolidayOperation.php', 'Filament/Pages/HolidayChanges.php'];
    foreach ($files as $file) {
        expect((bool) preg_match('/\b(payroll|pay|salary|wage|wages|deduction|deductions|earning|earnings|ctc|payslip)\b/i', file_get_contents(app_path($file))))->toBeFalse("{$file} must not use payroll words");
    }
});
