<?php

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Services\AttendanceProcessor;
use App\Filament\Pages\AttendanceExceptionCentre;
use App\Filament\Resources\AttendanceDevices\AttendanceDeviceResource;
use App\Filament\Resources\AttendanceRecords\AttendanceRecordResource;
use App\Filament\Resources\AttendanceRegularisations\AttendanceRegularisationResource;
use App\Filament\Resources\AttendanceRegularisations\Pages\ListAttendanceRegularisations;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\AttendanceRelationManager;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\WorkSchedules\WorkScheduleResource;
use Livewire\Livewire;

require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->shift = generalShift();
    $this->schedule = weeklySchedule($this->shift);
    $this->employee = employeeWithUser(null, ['attendance.regularise', 'task.view']);
    assignSchedule($this->employee, $this->schedule);
    HolidayCalendar::create(['name' => 'National', 'code' => 'NAT']);
    AttendanceDevice::create(['name' => 'Gate', 'code' => 'GATE']);
    punch($this->employee, '2026-09-23 09:50:00');
    punch($this->employee, '2026-09-23 18:00:00');
    $this->record = app(AttendanceProcessor::class)->process($this->employee, '2026-09-23');
    actAsTenant(null);
});

it('renders the attendance configuration, records, exception centre and 360 tab', function () {
    $this->get(ShiftResource::getUrl('index'))->assertOk()->assertSee('General');
    $this->get(ShiftResource::getUrl('create'))->assertOk();
    $this->get(ShiftResource::getUrl('edit', ['record' => $this->shift]))->assertOk();
    $this->get(WorkScheduleResource::getUrl('index'))->assertOk()->assertSee('Mon–Fri');
    $this->get(WorkScheduleResource::getUrl('edit', ['record' => $this->schedule]))->assertOk()->assertSee('Applies to');
    $this->get(HolidayCalendarResource::getUrl('index'))->assertOk()->assertSee('National');
    $this->get(AttendanceDeviceResource::getUrl('index'))->assertOk()->assertSee('Gate');
    $this->get(AttendanceRecordResource::getUrl('index'))->assertOk()->assertSee('Late coming');
    $this->get(AttendanceExceptionCentre::getUrl())->assertOk()->assertSee('Late coming');
    $this->get(AttendanceRegularisationResource::getUrl('index'))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('Attendance');
});

it('lets the employee request and HR approve a regularisation from the admin', function () {
    $this->travelTo('2026-09-24 09:00:00');
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);

    Livewire::test(AttendanceRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->callTableAction('regularise', $this->record, data: ['type' => 'late', 'reason' => 'Doctor visit'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Regularisation requested');

    $request = $this->employee->attendanceRegularisations()->first();
    expect($request->status)->toBe('pending');

    $this->actingAs($this->admin);
    Livewire::test(ListAttendanceRegularisations::class)
        ->callTableAction('approve', $request, data: ['note' => 'Fine'])
        ->assertNotified('Approved and reprocessed');

    expect($request->fresh()->status)->toBe('approved')
        ->and($this->record->fresh()->is_regularised)->toBeTrue();
});

it('assigns a schedule from the 360 and hides attendance admin without permission', function () {
    actAsTenant($this->tenant);
    $other = employeeWithUser();

    Livewire::test(ViewEmployee::class, ['record' => $other->getRouteKey()])
        ->callAction('assignSchedule', data: ['work_schedule_id' => $this->schedule->id, 'effective_from' => '2026-10-01', 'audit_reason' => 'New joiner'])
        ->assertHasNoActionErrors()
        ->assertNotified('Schedule assigned');
    expect($other->scheduleAssignments()->count())->toBe(1);

    $this->actingAs(tenantUser($this->tenant, ['employee.view']));
    $this->get(ShiftResource::getUrl('index'))->assertForbidden();
    $this->get(AttendanceExceptionCentre::getUrl())->assertForbidden();
});
