<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Experience\Services\RoleSignals;
use App\Domain\Experience\Services\WorkforcePulse;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Filament\Pages\AdminCentre;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\SecurityPolicyPage;
use App\Filament\Pages\WorkforceCommandCentre;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| UX.16.22 role journeys: login → understand Home → act → reach the result, for each role, through the existing
| authorised paths (the same actions, services and policies as everywhere else). Each journey also checks the
| boundary next to it: what that role may not do stays refused.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'attendance.view', 'attendance.approve', 'task.view', 'employee.view']);
    assignSchedule($this->manager, weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'attendance.regularise', 'task.view']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
});

it('employee: Home → request leave → the request waits in My Work', function () {
    $this->actingAs($this->employee->user);
    $this->get(Home::getUrl())->assertOk()->assertSee('Request leave');

    Livewire::test(Home::class)
        ->callAction('requestLeave', data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28', 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Dentist'])
        ->assertHasNoActionErrors();
    expect($this->employee->leaveRequests()->where('reason', 'Dentist')->value('status'))->toBe('pending');

    $this->get(MyWork::getUrl())->assertOk()->assertSee('My requests')->assertSee('Earned leave');
    // The boundary: an employee cannot open administration.
    $this->get(AdminCentre::getUrl())->assertForbidden();
});

it('manager: Home → decision → approve in the Approval Center → done in My Work', function () {
    $request = app(Leaves::class)->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Trip to Kochi');
    $this->actingAs($this->manager->user);
    $this->get(Home::getUrl())->assertOk()->assertSee('Decisions waiting for you')->assertSee('Your team');

    Livewire::test(Approvals::class)->call('decide', 'leave:'.$request->id, 'approve', null)->assertNotified('Leave approved');
    expect($request->fresh()->status)->toBe('approved');
    $done = Livewire::test(MyWork::class)->call('setFilter', 'completed')->instance()->work['completed'];
    expect($done->pluck('key')->implode(' '))->toContain((string) $request->id);
});

it('HR: Home → operational issue → the employee → a lifecycle action, recorded', function () {
    $hr = tenantUser($this->tenant, ['employee.view', 'employee.update', 'employee.lifecycle', 'leave.view']);
    forceLifecycle($this->employee, LifecycleState::Probation, ['probation_end_date' => now()->subDays(3)->toDateString()]);
    $this->actingAs($hr);
    $this->get(Home::getUrl())->assertOk()->assertSee('People operations')->assertSee('Probation decisions overdue');

    $overdue = fn () => collect(app(RoleSignals::class)->operations($hr))->firstWhere('key', 'probation_overdue')['count'] ?? 0;
    $before = $overdue();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk()->assertSee('People operations');
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction('lifecycle', data: ['to_state' => LifecycleState::Confirmed->value, 'effective_date' => now()->toDateString(), 'audit_reason' => 'Probation completed'])
        ->assertHasNoActionErrors();
    expect($this->employee->fresh()->lifecycle_state)->toBe(LifecycleState::Confirmed)
        ->and($overdue())->toBe($before - 1);
});

it('executive: Home → workforce pulse → evidence, in aggregates only', function () {
    $exec = tenantUser($this->tenant, ['analytics.view', 'analytics.executive']);
    $this->actingAs($exec);
    $this->get(Home::getUrl())->assertOk()->assertSee('Workforce pulse')->assertSee('Open Workforce pulse');
    $this->get(WorkforceCommandCentre::getUrl())->assertOk();

    // The drill-down behind a figure: without people access it is counts by department, never names.
    $drill = app(WorkforcePulse::class)->drill($exec, 'joiners');
    expect($drill['aggregated'])->toBeTrue()->and(collect($drill['rows'])->pluck('person_id')->filter()->all())->toBe([]);
    // The boundary: no individual Employee 360.
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertForbidden();
});

it('administrator: Home → governance item → the control → change → audited, and the item clears', function () {
    $admin = tenantUser($this->tenant, ['security.manage', 'user.view', 'user.assign_roles', 'audit.view']);
    $this->actingAs($admin);
    $this->get(Home::getUrl())->assertOk()->assertSee('Governance')->assertSee('Multi-factor sign-in is not required');

    $this->get(SecurityPolicyPage::getUrl())->assertOk();
    Livewire::test(SecurityPolicyPage::class)->set('data.security__mfa_required', true)->call('save')->assertNotified('Security policy saved');
    expect(app(SecurityPolicy::class)->mfaRequired())->toBeTrue()
        ->and(AuditEvent::query()->where('module', 'settings')->orWhere('reason', 'Security policy update')->exists())->toBeTrue()
        ->and(collect(app(RoleSignals::class)->governance($admin)['attention'])->pluck('key')->all())->not->toContain('mfa_not_required');
    // The boundary: governance is not a back door into employee records.
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertForbidden();
});
