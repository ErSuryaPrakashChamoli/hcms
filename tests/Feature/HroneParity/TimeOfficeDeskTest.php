<?php

use App\Domain\Analytics\Models\Report;
use App\Domain\Attendance\Imports\AttendanceSheetImports;
use App\Domain\Attendance\Imports\DayOverrideImports;
use App\Domain\Attendance\Imports\ShiftRosterImports;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Models\WorkScheduleAssignment;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Imports\RowImportTypes;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Leave\Imports\LeaveBalanceImports;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Filament\Pages\TimeOfficeDesk;
use App\Filament\Resources\AttendanceArrears\Pages\ListAttendanceArrears;
use App\Filament\Resources\AttendanceOverrides\AttendanceOverrideResource;
use App\Filament\Resources\AttendanceOverrides\Pages\ListAttendanceOverrides;
use App\Filament\Resources\AttendancePenalties\Pages\ListAttendancePenalties;
use App\Filament\Resources\AttendancePunches\Pages\ListAttendancePunches;
use App\Filament\Resources\AttendanceRecords\Pages\ListAttendanceRecords;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\AttendanceRegularisations\Pages\ListAttendanceRegularisations;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use App\Filament\Resources\LeaveEncashments\Pages\ListLeaveEncashments;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LocationRequests\Pages\ListLocationRequests;
use App\Filament\Resources\PunchImports\PunchImportResource;
use App\Filament\Resources\ShiftOverrides\Pages\ListShiftOverrides;
use App\Filament\Resources\TimeOfficeImports\Pages\ListTimeOfficeImports;
use App\Filament\Resources\TimeOfficeImports\TimeOfficeImportResource;
use App\Filament\Resources\TimeOfficeRequests\Pages\ListTimeOfficeRequests;
use App\Filament\Resources\TimeOfficeRequests\TimeOfficeRequestResource;
use App\Filament\Support\TimeOfficeRegisters;
use App\Http\Controllers\ParityFileController;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Leave/LeaveTestHelpers.php';

/*
| HROne parity TX-1 (matrix 24: the Time Office panel, its registers' search / filters / views / reports / uploads, the
| detail flyers and AR "raise on behalf") and TX-2 (the narrower time-office rights split out of attendance.manage and
| leave.manage, with the broad keys still granting them).
*/

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00'); // a Friday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->sales = Department::factory()->create(['company_id' => $this->company->id, 'name' => 'Sales']);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->asha = tx12Hire('Asha', '2024-03-01', ['department_id' => $this->sales->id]);
    $this->ravi = tx12Hire('Ravi', '2025-06-15');
    assignSchedule($this->asha, $this->schedule);
    assignSchedule($this->ravi, $this->schedule);
    $this->requests = app(TimeOfficeRequests::class);
});

function tx12Hire(string $first, string $joined, array $position = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => $joined],
        ['company_id' => Company::query()->value('id'), 'location_id' => Location::query()->value('id')] + $position);
}

function tx12Policy(string $type, array $settings, string $code): Policy
{
    $policy = Policy::create(['type' => $type, 'name' => $code, 'code' => $code]);
    app(Policies::class)->draft($policy, $settings);
    app(Policies::class)->publish($policy, '2026-01-01');
    PolicyAssignmentRule::create(['policy_type' => $type, 'policy_id' => $policy->id, 'name' => 'All', 'conditions' => []]);

    return $policy;
}

// ---- TX-1: the desk ------------------------------------------------------------------------------------------------

it('opens the time office desk for time-office operators only, with HROne\'s registers as views of the existing ones', function () {
    $this->requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Client site');

    $this->get(TimeOfficeDesk::getUrl())->assertOk()
        ->assertSee('Checkins')->assertSee('Overtime &amp; comp off', false)->assertSee('Holiday')
        ->assertSee(TimeOfficeRequestResource::getUrl('index', ['view' => 'on_duty_cancellations']), false)
        ->assertSee(LeaveRequestResource::getUrl('index', ['view' => 'leave_cancellations']), false);
    $onDuty = collect((new TimeOfficeDesk)->deskSections())->firstWhere('label', 'Checkins')['items'];
    expect(collect($onDuty)->firstWhere('label', 'On duty requests')['count'])->toBe(1);

    // A manager approves time-office requests but runs no time office: no desk.
    $manager = tenantUser($this->tenant, ['employee.view', 'attendance.view', 'attendance.approve', 'attendance.regularise', 'leave.view', 'leave.approve']);
    $this->actingAs($manager);
    expect(TimeOfficeDesk::canAccess())->toBeFalse();
    $this->get(TimeOfficeDesk::getUrl())->assertForbidden();

    // A narrower right is enough, and the desk lists only what that person can open.
    $rosterer = tenantUser($this->tenant, ['attendance.view', 'attendance.roster']);
    $this->actingAs($rosterer);
    expect(TimeOfficeDesk::canAccess())->toBeTrue();
    $labels = collect((new TimeOfficeDesk)->deskSections())->flatMap(fn (array $s) => array_column($s['items'], 'label'));
    expect($labels)->toContain('Shift rosters')->not->toContain('Leave requests')->not->toContain('Day overrides');
});

it('shows HROne\'s request and cancellation registers as preset views of the time-office requests', function () {
    $late = generalShift(['name' => 'Late', 'code' => 'LATE', 'start_time' => '13:00:00', 'end_time' => '22:00:00']);
    $onDuty = $this->requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Client site');
    $cancelling = $this->requests->request($this->ravi, 'on_duty', ['from_date' => '2026-09-23', 'session' => 'full'], 'Audit');
    TimeOfficeRequest::query()->whereKey($cancelling->id)->update(['status' => 'cancel_requested', 'cancel_requested_at' => now()]);
    $shift = $this->requests->request($this->asha, 'shift_change', ['from_date' => '2026-09-28', 'to_date' => '2026-09-29', 'shift_id' => $late->id], 'Cover');

    Livewire::withQueryParams(['view' => 'on_duty'])->test(ListTimeOfficeRequests::class)
        ->assertCanSeeTableRecords([$onDuty, $cancelling])->assertCanNotSeeTableRecords([$shift]);
    Livewire::withQueryParams(['view' => 'on_duty_cancellations'])->test(ListTimeOfficeRequests::class)
        ->assertCanSeeTableRecords([$cancelling])->assertCanNotSeeTableRecords([$onDuty, $shift]);
    Livewire::withQueryParams(['view' => 'shift_requests'])->test(ListTimeOfficeRequests::class)
        ->assertCanSeeTableRecords([$shift])->assertCanNotSeeTableRecords([$onDuty, $cancelling]);
    $this->get(TimeOfficeRequestResource::getUrl('index', ['view' => 'restricted_holidays']))->assertOk();
});

// ---- TX-1: search and filters --------------------------------------------------------------------------------------

it('finds time-office requests by employee code, pasted codes, organisation, joining, on-duty type and raised / decided / asked-for dates', function () {
    $first = $this->requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-21', 'session' => 'full', 'category' => 'Client visit'], 'Client');
    $this->travelTo('2026-09-26 10:00:00');
    $second = $this->requests->request($this->ravi, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full', 'category' => 'Training'], 'Course');
    $this->requests->approve($second, 'ok', $this->hr);
    forceLifecycle($this->ravi, LifecycleState::NoticePeriod);

    Livewire::test(ListTimeOfficeRequests::class)
        ->searchTable($this->asha->employee_code)->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
        ->searchTable('')
        ->filterTable('employee_codes', ['codes' => $this->ravi->employee_code])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('organisation', ['department_id' => [$this->sales->id]])->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
        ->resetTableFilters()
        ->filterTable('workforce', ['joined_from' => '2025-01-01'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('workforce', ['lifecycle_state' => [LifecycleState::NoticePeriod->value]])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('category', ['Training'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('request_dates', ['from' => '2026-09-26'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('decision_dates', ['from' => '2026-09-01'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('effective_dates', ['until' => '2026-09-22'])->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
});

it('gives every time-office and leave register the same employee filters and its own date ranges', function () {
    $common = ['employee_codes', 'organisation', 'workforce', 'exclude_pending_resignation'];
    $registers = [
        ListTimeOfficeRequests::class => ['type', 'status', 'category', 'shift_id', 'effective_dates', 'request_dates', 'decision_dates'],
        ListAttendanceRegularisations::class => ['status', 'type', 'effective_dates', 'request_dates', 'decision_dates'],
        ListLeaveRequests::class => ['status', 'leave_type_id', 'department', 'period', 'cancellation', 'request_dates', 'decision_dates'],
        ListLeaveEncashments::class => ['status', 'leave_type_id', 'request_dates', 'decision_dates'],
        ListLocationRequests::class => ['status', 'request_dates', 'decision_dates'],
        ListAttendanceRecords::class => ['date', 'status', 'department', 'shift_id', 'work_schedule', 'overtime_status'],
        ListLeaveBalances::class => ['leave_type_id', 'period_year'],
        ListShiftOverrides::class => ['shift_id', 'source', 'effective_dates'],
        ListAttendanceOverrides::class => ['status', 'effective_dates'],
        ListAttendancePenalties::class => ['status', 'month'],
        ListAttendanceArrears::class => ['status', 'effective_dates'],
        ListAttendancePunches::class => ['processing_status', 'source_type', 'date'],
    ];
    foreach ($registers as $page => $filters) {
        $test = Livewire::test($page);
        foreach ([...$common, ...$filters] as $filter) {
            $test->assertTableFilterExists($filter)->assertTableFilterVisible($filter);
        }
    }

    $first = AttendanceRegularisation::create(['employee_id' => $this->asha->id, 'date' => '2026-09-21', 'type' => 'missed_punch', 'reason' => 'Forgot', 'status' => 'pending']);
    $second = AttendanceRegularisation::create(['employee_id' => $this->ravi->id, 'date' => '2026-09-24', 'type' => 'wfh', 'reason' => 'Rain', 'status' => 'pending']);
    Livewire::test(ListAttendanceRegularisations::class)
        ->searchTable($this->ravi->employee_code)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->searchTable('')
        ->filterTable('effective_dates', ['from' => '2026-09-22'])->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
        ->resetTableFilters()
        ->filterTable('type', ['missed_punch'])->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
});

it('filters attendance by the shift worked and the work schedule the day was processed with', function () {
    $late = generalShift(['name' => 'Late', 'code' => 'LATE', 'start_time' => '13:00:00', 'end_time' => '22:00:00']);
    $lateSchedule = weeklySchedule($late, ['name' => 'Late week', 'code' => 'LW']);
    WorkScheduleAssignment::query()->where('employee_id', $this->ravi->id)->delete();
    assignSchedule($this->ravi, $lateSchedule);
    $processor = app(AttendanceProcessor::class);
    $asha = $processor->process($this->asha, '2026-09-24');
    $ravi = $processor->process($this->ravi, '2026-09-24');

    Livewire::test(ListAttendanceRecords::class)
        ->filterTable('date', ['from' => '2026-09-01'])
        ->filterTable('shift_id', $late->id)->assertCanSeeTableRecords([$ravi])->assertCanNotSeeTableRecords([$asha])
        ->resetTableFilters()
        ->filterTable('date', ['from' => '2026-09-01'])
        ->filterTable('work_schedule', [$this->schedule->id])->assertCanSeeTableRecords([$asha])->assertCanNotSeeTableRecords([$ravi]);
});

it('lists leave cancellations as a view of the leave register', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'min_notice_days' => 0, 'negative_balance_limit' => 0, 'max_consecutive_days' => 0]]);
    foreach ([$this->asha, $this->ravi] as $employee) {
        forceLifecycle($employee, LifecycleState::Active);
        app(LeaveAccrual::class)->accrue($employee);
    }
    $el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $leaves = app(Leaves::class);
    $taken = $leaves->approve($leaves->request($this->asha, $el, '2026-10-05', '2026-10-06', 'Trip'), 'ok', $this->hr);
    $leaves->requestCancellation($taken, 'Plans changed', $this->hr);
    $pending = $leaves->request($this->ravi, $el, '2026-10-07', '2026-10-07', 'Errand');

    Livewire::withQueryParams(['view' => 'leave_cancellations'])->test(ListLeaveRequests::class)
        ->assertCanSeeTableRecords([$taken])->assertCanNotSeeTableRecords([$pending])
        ->assertTableColumnExists('cancel_requested_at');
    Livewire::withQueryParams(['view' => 'pending'])->test(ListLeaveRequests::class)
        ->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$taken]);
});

it('keeps an employee\'s own request lists as they were', function () {
    $user = tenantUser($this->tenant, ['attendance.regularise', 'leave.apply']);
    $this->asha->forceFill(['user_id' => $user->id])->save();
    $own = $this->requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Client site');
    $other = $this->requests->request($this->ravi, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Client site');
    $this->actingAs($user);

    Livewire::test(ListTimeOfficeRequests::class)
        ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
        ->assertTableFilterHidden('employee_codes')->assertTableFilterHidden('organisation')->assertTableFilterHidden('request_dates')
        ->assertActionDoesNotExist('posSaveView')->assertActionDoesNotExist('registerReport')
        ->assertActionVisible('newRequest');
    Livewire::test(ListLeaveRequests::class)->assertTableFilterHidden('cancellation')->assertActionDoesNotExist('registerReport');
});

// ---- TX-1: reports and uploads -------------------------------------------------------------------------------------

it('creates a register\'s report with its filters as the dataset shows them', function () {
    $this->requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full'], 'Client site');

    Livewire::test(ListTimeOfficeRequests::class)
        ->filterTable('status', ['pending'])
        ->filterTable('type', ['on_duty'])
        ->filterTable('request_dates', ['from' => '2026-09-01'])
        ->callAction('registerReport', ['template' => 'on_duty_requests', 'with_filters' => true])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $filters = collect(Report::query()->latest('id')->firstOrFail()->definition['filters']);
    expect($filters->where('field', 'status')->pluck('value'))->toContain('Pending')
        ->and($filters->where('field', 'type')->pluck('value'))->toContain('On duty')
        ->and($filters->firstWhere('field', 'applied_on'))->toMatchArray(['operator' => 'gte', 'value' => '2026-09-01']);
});

it('links each register to the uploads of its subject and opens the upload with its type chosen', function () {
    $group = TimeOfficeRegisters::uploads(['shift_roster', 'attendance_sheet', 'punches']);
    $urls = collect($group->getActions())->mapWithKeys(fn (Action $a) => [$a->getName() => $a->isVisible() ? $a->getUrl() : null]);
    expect($urls['registerUpload_shift_roster'])->toBe(TimeOfficeImportResource::getUrl('index', ['action' => 'create', 'actionArguments' => ['type' => ShiftRosterImports::TYPE]]))
        ->and($urls['registerUpload_punches'])->toBe(PunchImportResource::getUrl('index', ['action' => 'create']));

    Livewire::test(ListTimeOfficeImports::class)
        ->mountAction('create', ['type' => ShiftRosterImports::TYPE])
        ->assertActionDataSet(['type' => ShiftRosterImports::TYPE, 'mode' => 'both']);
    Livewire::test(ListTimeOfficeImports::class)
        ->mountAction('create', ['type' => 'not_a_type'])
        ->assertActionDataSet(['type' => null]);

    // Hidden from someone who may not upload that type.
    $this->actingAs(tenantUser($this->tenant, ['attendance.view', 'employee.import']));
    $visible = collect(TimeOfficeRegisters::uploads(['shift_roster'])->getActions())->filter(fn (Action $a) => $a->isVisible());
    expect($visible)->toBeEmpty();
});

// ---- TX-1: detail views --------------------------------------------------------------------------------------------

it('shows a time-office request\'s detail: earlier and new shift, documents, the day now and the debited leave', function () {
    Storage::fake(config('peopleos.documents.disk', 'local'));
    Storage::disk(config('peopleos.documents.disk', 'local'))->put('time-office/ticket.pdf', '%PDF-1.4 test');
    $late = generalShift(['name' => 'Late', 'code' => 'LATE', 'start_time' => '13:00:00', 'end_time' => '22:00:00']);
    $change = $this->requests->request($this->asha, 'shift_change', ['from_date' => '2026-09-28', 'to_date' => '2026-09-29', 'shift_id' => $late->id], 'Cover');
    $this->requests->approve($change, null, $this->hr);
    $onDuty = $this->requests->request($this->asha, 'on_duty', ['from_date' => '2026-09-24', 'session' => 'full', 'attachments' => ['time-office/ticket.pdf']], 'Client site');

    $this->get(TimeOfficeRequestResource::getUrl('view', ['record' => $change]))->assertOk()
        ->assertSee('Earlier shift')->assertSee('General')->assertSee('Late')->assertSee('Mon, 28 Sep 2026');
    $this->get(TimeOfficeRequestResource::getUrl('view', ['record' => $onDuty]))->assertOk()
        ->assertSee('ticket.pdf')->assertSee('The day now')->assertSee('Working day (not processed yet)');

    // The document opens behind a signed link, audited; someone outside the request may not open it.
    $this->get(ParityFileController::url('time-office-request', $onDuty, 0))->assertOk();
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', 'ATTACHMENT_DOWNLOADED')->exists())->toBeTrue();
    $this->actingAs(tenantUser($this->tenant, ['attendance.regularise']));
    $this->get(ParityFileController::url('time-office-request', $onDuty, 0))->assertForbidden();
    $this->actingAs($this->hr);

    // Short leave beyond the monthly limit: the leave it took off the balance.
    $el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $short = TimeOfficeRequest::create(['employee_id' => $this->asha->id, 'type' => 'short_leave', 'status' => 'approved', 'from_date' => '2026-09-24', 'to_date' => '2026-09-24', 'start_time' => '09:00', 'end_time' => '10:00', 'minutes' => 60, 'reason' => 'Bank']);
    app(LeaveBalances::class)->post($this->asha, $el, 2026, 'adjustment', -0.5, $short, 'Short leave beyond the monthly limit', "sl-{$short->id}", '2026-09-24');
    expect(TimeOfficeRequestResource::debitedLeave($short))->toContain($el->name)->toContain('0.5');
    $this->get(TimeOfficeRequestResource::getUrl('view', ['record' => $short]))->assertOk()->assertSee('Leave debited');
});

it('shows the restricted-holiday usage on the request\'s detail', function () {
    $calendar = HolidayCalendar::create(['name' => 'India', 'code' => 'IN', 'optional_requires_selection' => true, 'restricted_holiday_quota' => 2]);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);
    $onam = Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-29', 'name' => 'Onam', 'type' => 'optional']);
    $request = $this->requests->request($this->asha, 'restricted_holiday', ['holiday_id' => $onam->id], 'Festival');

    expect($this->requests->restrictedHolidayQuota($this->asha, $onam))->toBe(['taken' => 1, 'quota' => 2, 'year' => '2026']);
    $this->get(TimeOfficeRequestResource::getUrl('view', ['record' => $request]))->assertOk()->assertSee('Onam')->assertSee('1 of 2 for 2026');
});

it('shows a leave request\'s detail with the balance left after it', function () {
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual', 'min_notice_days' => 0, 'negative_balance_limit' => 0, 'max_consecutive_days' => 0]]);
    forceLifecycle($this->asha, LifecycleState::Active);
    app(LeaveAccrual::class)->accrue($this->asha);
    $el = LeaveType::query()->where('code', 'EL')->firstOrFail();
    $leave = app(Leaves::class)->request($this->asha, $el, '2026-10-05', '2026-10-06', 'Trip');
    $available = LeaveBalance::query()->where('employee_id', $this->asha->id)->where('leave_type_id', $el->id)->firstOrFail()->available();

    expect(LeaveRequestResource::remainingBalance($leave))->toBe(rtrim(rtrim(number_format($available, 1), '0'), '.').' day(s) available after this request');
    $this->get(LeaveRequestResource::getUrl('view', ['record' => $leave]))->assertOk()
        ->assertSee('Remaining balance')->assertSee('after this request')->assertSee('None attached');
});

// ---- TX-1: regularisation raised on behalf -------------------------------------------------------------------------

it('lets HR raise a regularisation on behalf: approved now as today, or sent for approval', function () {
    Livewire::test(ListAttendanceRegularisations::class)
        ->callAction('raiseOnBehalf', ['employee_id' => $this->asha->id, 'date' => '2026-09-24', 'type' => 'missed_punch', 'requested_in' => '2026-09-24 09:00', 'requested_out' => '2026-09-24 18:00', 'reason' => 'Card not read', 'decision' => 'send'])
        ->assertHasNoActionErrors();
    $sent = AttendanceRegularisation::query()->where('employee_id', $this->asha->id)->sole();
    expect($sent->status)->toBe('pending')->and($sent->requested_by)->toBe($this->hr->id);

    Livewire::test(ListAttendanceRegularisations::class)
        ->callAction('raiseOnBehalf', ['employee_id' => $this->ravi->id, 'date' => '2026-09-24', 'type' => 'missed_punch', 'requested_in' => '2026-09-24 09:00', 'requested_out' => '2026-09-24 18:00', 'reason' => 'Card not read', 'decision' => 'approve'])
        ->assertHasNoActionErrors();
    $approved = AttendanceRegularisation::query()->where('employee_id', $this->ravi->id)->sole();
    expect($approved->status)->toBe('approved')->and($approved->reviewed_by)->toBe($this->hr->id)
        ->and(AttendanceRecord::query()->where('employee_id', $this->ravi->id)->whereDate('date', '2026-09-24')->value('status'))->toBe('present');

    $this->get(AttendanceRegularisationResource::getUrl('view', ['record' => $sent]))->assertOk()
        ->assertSee('Requested check-in')->assertSee('The day now')->assertSee('Working day (not processed yet)');

    $this->actingAs(tenantUser($this->tenant, ['attendance.view', 'attendance.approve']));
    Livewire::test(ListAttendanceRegularisations::class)->assertActionHidden('raiseOnBehalf');
});

// ---- TX-2: finer time-office rights --------------------------------------------------------------------------------

it('declares each narrower right inside its module, split out of the broad key that still grants it', function () {
    $catalogue = collect(config('peopleos.permissions'))->flatMap(fn (array $keys) => array_keys($keys));
    foreach (config('peopleos.permission_splits') as $narrow => $broad) {
        expect($catalogue)->toContain($narrow)->toContain($broad)
            ->and(explode('.', $narrow)[0])->toBe(explode('.', $broad)[0]);
    }
    expect(array_keys(config('peopleos.permission_splits')))->toEqualCanonicalizing(['attendance.roster', 'attendance.override', 'attendance.punch', 'attendance.process', 'attendance.arrears', 'leave.adjust', 'leave.carry_forward']);

    $broad = tenantUser($this->tenant, ['attendance.manage', 'leave.manage']);
    foreach (array_keys(config('peopleos.permission_splits')) as $narrow) {
        expect($broad->hasPermission($narrow))->toBeTrue("attendance.manage / leave.manage must still grant {$narrow}");
    }
    $narrowOnly = tenantUser($this->tenant, ['attendance.roster', 'leave.adjust']);
    expect($narrowOnly->hasPermission('attendance.roster'))->toBeTrue()
        ->and($narrowOnly->hasPermission('attendance.manage'))->toBeFalse()
        ->and($narrowOnly->hasPermission('attendance.punch'))->toBeFalse()
        ->and($narrowOnly->hasPermission('leave.manage'))->toBeFalse();
});

it('lets each narrower right do its own actions only, and the broad key all of them as before', function () {
    $record = app(AttendanceProcessor::class)->process($this->asha, '2026-09-24');
    $as = function (array $permissions) {
        $user = tenantUser($this->tenant, $permissions);
        $this->actingAs($user);

        return $user;
    };

    $as(['attendance.view', 'attendance.roster', 'employee.import']);
    Livewire::test(ListShiftOverrides::class)->assertActionVisible('roster');
    Livewire::test(ListAttendanceRecords::class)->assertActionHidden('manualPunch')->assertActionHidden('process')->assertActionHidden('processRange')
        ->assertTableActionHidden('reprocess', $record);
    expect(AttendanceOverrideResource::canAccess())->toBeFalse()
        ->and(RowImportTypes::allowed(auth()->user(), ShiftRosterImports::TYPE))->toBeTrue()
        ->and(RowImportTypes::allowed(auth()->user(), DayOverrideImports::TYPE))->toBeFalse();

    $as(['attendance.view', 'attendance.punch', 'employee.import']);
    Livewire::test(ListAttendanceRecords::class)->assertActionVisible('manualPunch')->assertActionHidden('process');
    Livewire::test(ListShiftOverrides::class)->assertActionHidden('roster');
    expect(PunchImportResource::canAccess())->toBeTrue();

    $as(['attendance.view', 'attendance.process']);
    Livewire::test(ListAttendanceRecords::class)->assertActionVisible('process')->assertActionVisible('processRange')->assertActionHidden('manualPunch')
        ->assertTableActionVisible('reprocess', $record);

    $as(['attendance.view', 'attendance.override', 'employee.import']);
    expect(AttendanceOverrideResource::canAccess())->toBeTrue()
        ->and(AttendanceSheetImports::allowed(auth()->user()))->toBeTrue()
        ->and(RowImportTypes::allowed(auth()->user(), DayOverrideImports::TYPE))->toBeTrue();

    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    forceLifecycle($this->asha, LifecycleState::Active);
    app(LeaveAccrual::class)->accrue($this->asha);
    $balance = LeaveBalance::query()->where('employee_id', $this->asha->id)->firstOrFail();
    $as(['leave.view', 'leave.adjust', 'employee.import']);
    Livewire::test(ListLeaveBalances::class)->assertTableActionVisible('adjust', $balance)->assertActionHidden('accrue');
    expect(RowImportTypes::allowed(auth()->user(), LeaveBalanceImports::TYPE))->toBeTrue();

    // The broad keys alone (a custom role, or one the tenant edited) keep every action they had.
    $as(['attendance.view', 'attendance.manage', 'leave.view', 'leave.manage', 'employee.import']);
    Livewire::test(ListShiftOverrides::class)->assertActionVisible('roster');
    Livewire::test(ListAttendanceRecords::class)->assertActionVisible('manualPunch')->assertActionVisible('process')->assertTableActionVisible('reprocess', $record);
    Livewire::test(ListLeaveBalances::class)->assertTableActionVisible('adjust', $balance)->assertActionVisible('accrue');
    expect(AttendanceOverrideResource::canAccess())->toBeTrue()->and(PunchImportResource::canAccess())->toBeTrue()
        ->and(RowImportTypes::allowed(auth()->user(), LeaveBalanceImports::TYPE))->toBeTrue();

    // Without any of them: none.
    $as(['attendance.view', 'leave.view']);
    Livewire::test(ListShiftOverrides::class)->assertActionHidden('roster');
    Livewire::test(ListAttendanceRecords::class)->assertActionHidden('manualPunch')->assertActionHidden('process')->assertTableActionHidden('reprocess', $record);
    Livewire::test(ListLeaveBalances::class)->assertTableActionHidden('adjust', $balance);
});

it('gives the narrower rights to the role templates holding the broad keys, and never to a role the tenant took the broad key from', function () {
    $keys = fn (string $slug) => Role::query()->where('slug', $slug)->firstOrFail()->permissions()->pluck('key');
    $attendance = ['attendance.roster', 'attendance.override', 'attendance.punch', 'attendance.process', 'attendance.arrears'];
    $leave = ['leave.adjust', 'leave.carry_forward'];

    expect($keys('tenant-hr-admin'))->toContain(...$attendance, ...$leave)
        ->and($keys('attendance-admin'))->toContain(...$attendance)
        ->and($keys('hr-manager'))->toContain(...$leave);
    foreach (['manager', 'employee', 'payroll-admin', 'auditor', 'hr-executive'] as $slug) {
        expect($keys($slug)->intersect([...$attendance, ...$leave])->all())->toBe([], "{$slug} must not get the narrower rights");
    }
    expect($keys('attendance-admin'))->not->toContain('leave.adjust')->and($keys('hr-manager'))->not->toContain('attendance.roster');

    // A deploy that introduces the keys: the role the tenant took attendance.manage from gets no attendance key; the
    // leave keys still follow leave.manage, which it holds.
    $role = Role::query()->where('slug', 'tenant-hr-admin')->firstOrFail();
    $role->permissions()->detach(Permission::query()->whereIn('key', ['attendance.manage', ...$attendance, ...$leave])->pluck('id'));
    $role->forceFill(['template_keys' => array_values(array_diff($role->template_keys, [...$attendance, ...$leave]))])->saveQuietly();
    $admin = Role::query()->where('slug', 'attendance-admin')->firstOrFail();
    $admin->permissions()->detach(Permission::query()->whereIn('key', $attendance)->pluck('id'));
    $admin->forceFill(['template_keys' => array_values(array_diff($admin->template_keys, $attendance))])->saveQuietly();

    $this->artisan('peopleos:sync-permissions')->assertSuccessful();
    actAsTenant($this->tenant);

    expect($keys('tenant-hr-admin'))->not->toContain('attendance.manage')->not->toContain('attendance.roster')->not->toContain('attendance.punch')
        ->toContain('leave.adjust', 'leave.carry_forward')
        ->and($keys('attendance-admin'))->toContain(...$attendance);
    $this->artisan('peopleos:sync-permissions')->assertSuccessful(); // later deploys do not hand them over either
    actAsTenant($this->tenant);
    expect($keys('tenant-hr-admin'))->not->toContain('attendance.roster');
    expect(app(PermissionRegistry::class)->isKnown('attendance.roster'))->toBeTrue();
});
