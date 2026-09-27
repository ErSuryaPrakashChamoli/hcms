<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Employees\RelationManagers\LeaveRelationManager;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveTypes\LeaveTypeResource;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    assignSchedule($this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'task.view']), weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'task.view']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->request = app(Leaves::class)->request($this->employee, $this->el, '2026-09-23', '2026-09-24', 'Trip');
    actAsTenant(null);
});

it('renders leave pages and the 360 tab', function () {
    $this->get(LeaveTypeResource::getUrl('index'))->assertOk()->assertSee('Earned leave');
    $this->get(LeaveRequestResource::getUrl('index'))->assertOk()->assertSee('Trip');
    $this->get(LeaveBalanceResource::getUrl('index'))->assertOk()->assertSee('24.0');
    $this->get(LeaveEncashmentResource::getUrl('index'))->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('Leave');
});

it('lets the employee apply from the 360 and the manager approve from the register', function () {
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);

    Livewire::test(LeaveRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->assertSee('EL 22.0')
        ->callTableAction('apply', data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Errand'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Leave requested: 1.0 day(s)');

    $this->actingAs($this->manager->user);
    $this->get(LeaveRequestResource::getUrl('index'))->assertOk()->assertSee('Errand');

    Livewire::test(ListLeaveRequests::class)
        ->callTableAction('approve', $this->request, data: ['note' => 'OK'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Leave approved');

    expect($this->request->fresh()->status)->toBe('approved');
});

it('restricts the register to own requests without leave.view and hides admin without permission', function () {
    actAsTenant($this->tenant);
    $other = employeeWithUser($this->manager, ['leave.apply']);
    $this->actingAs($other->user);

    $this->get(LeaveRequestResource::getUrl('index'))->assertOk()->assertDontSee('Trip');
    $this->get(LeaveTypeResource::getUrl('index'))->assertOk();
    $this->get(LeaveEncashmentResource::getUrl('index'))->assertForbidden();
});
