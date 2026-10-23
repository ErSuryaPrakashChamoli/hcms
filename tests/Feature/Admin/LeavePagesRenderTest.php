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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

it('lets an admin apply on behalf of an employee from the register', function () {
    actAsTenant($this->tenant);

    Livewire::test(ListLeaveRequests::class)
        ->mountAction('applyOnBehalf')
        ->fillForm(['employee_id' => $this->employee->id])
        ->fillForm(['leave_type_id' => $this->el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Errand'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Leave requested: 1.0 day(s)');

    expect($this->employee->leaveRequests()->where('reason', 'Errand')->exists())->toBeTrue();
});

it('uploads a supporting document with the request and drops it when the request is refused', function () {
    Storage::fake('local');
    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);
    $form = ['leave_type_id' => $this->el->id, 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Medical'];

    Livewire::test(LeaveRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->callTableAction('apply', data: $form + ['from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'document_upload' => UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\nsick note")])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Leave requested: 1.0 day(s)');

    $request = $this->employee->leaveRequests()->where('reason', 'Medical')->sole();
    expect($request->document)->not->toBeNull()
        ->and($request->document->employee_id)->toBe($this->employee->id)
        ->and($request->document->title)->toBe('Earned leave: supporting document');
    Storage::disk('local')->assertExists($request->document->path);

    // Overlaps the existing "Trip" request: refused, and the uploaded file is not left behind.
    Livewire::test(LeaveRelationManager::class, ['ownerRecord' => $this->employee, 'pageClass' => ViewEmployee::class])
        ->callTableAction('apply', data: $form + ['from_date' => '2026-09-23', 'to_date' => '2026-09-23', 'document_upload' => UploadedFile::fake()->createWithContent('again.pdf', "%PDF-1.4\nsick note")])
        ->assertNotified('Cannot apply');

    expect($this->employee->documents()->count())->toBe(1);
});
