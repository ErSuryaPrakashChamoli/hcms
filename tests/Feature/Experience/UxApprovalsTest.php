<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyWork;
use App\Filament\Support\BeforeAfterPreview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| Experience Transformation §22 / §21 / §56: the Approval Center lists only what the viewer's existing
| policies let them decide, decisions go through the owning domain service, and Before → After previews
| show the real effect.
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
    $this->request = app(Leaves::class)->request($this->employee, $this->el, '2026-09-23', '2026-09-24', 'Trip to Kochi');
});

it('lists a direct report\'s leave for the manager with impact and Before → After', function () {
    $item = app(ApprovalCenter::class)->pending($this->manager->user)->firstWhere('id', 'leave:'.$this->request->id);

    expect($item)->not->toBeNull()
        ->and($item->subject)->toBe($this->employee->refresh()->display_name)
        ->and($item->reason)->toBe('Trip to Kochi')
        ->and($item->decisions)->toBe(['approve', 'reject'])
        ->and($item->changes[0]['label'])->toBe('Earned leave balance')
        ->and($item->changes[0]['before'])->toBe('24 days')
        ->and($item->changes[0]['after'])->toBe('22 days')
        ->and($item->impact)->toContain('No one else in the team is away');
});

it('never lists a request for its requester, for someone without leave.approve, or outside the approver\'s scope', function () {
    $center = app(ApprovalCenter::class);
    expect($center->pending($this->employee->user)->pluck('id'))->not->toContain('leave:'.$this->request->id);

    $noPermission = tenantUser($this->tenant, ['leave.view', 'task.view']);
    expect($center->pending($noPermission)->pluck('id'))->not->toContain('leave:'.$this->request->id);

    $scoped = tenantUser($this->tenant, ['leave.view', 'leave.approve', 'task.view']);
    app(AccessScopes::class)->assign($scoped, ['company' => [Company::factory()->create()->id]], 'Other company only');
    expect((new ApprovalCenter(app(TenantContext::class)))->pending($scoped)->pluck('id'))->not->toContain('leave:'.$this->request->id);
});

it('approves through the Leaves service, posts the balance and removes the item', function () {
    $message = app(ApprovalDecisions::class)->decide($this->manager->user, 'leave:'.$this->request->id, 'approve', 'Enjoy');

    $request = $this->request->fresh();
    expect($message)->toBe('Leave approved')
        ->and($request->status)->toBe('approved')
        ->and((int) $request->reviewed_by)->toBe((int) $this->manager->user_id)
        ->and($request->review_note)->toBe('Enjoy')
        ->and(app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id'))->not->toContain('leave:'.$this->request->id);
});

it('requires a reason to reject, and refuses forged, foreign or already-decided items', function () {
    $decisions = app(ApprovalDecisions::class);

    expect(fn () => $decisions->decide($this->manager->user, 'leave:'.$this->request->id, 'reject', '  '))->toThrow(InvalidArgumentException::class);
    expect($this->request->fresh()->status)->toBe('pending');

    expect(fn () => $decisions->decide($this->manager->user, 'leave:999999', 'approve'))->toThrow(AuthorizationException::class)
        ->and(fn () => $decisions->decide($this->manager->user, 'leave:'.$this->request->id, 'request_change', 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => $decisions->decide($this->employee->user, 'leave:'.$this->request->id, 'approve'))->toThrow(AuthorizationException::class);

    $decisions->decide($this->manager->user, 'leave:'.$this->request->id, 'reject', 'Team offsite that week');
    expect($this->request->fresh()->status)->toBe('rejected');
    expect(fn () => $decisions->decide($this->manager->user, 'leave:'.$this->request->id, 'approve'))->toThrow(AuthorizationException::class);
});

it('keeps other tenants\' requests out of the queue', function () {
    $other = provisionTenant('Other Co');
    actAsTenant($other);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $boss = employeeWithUser(null, ['leave.view', 'leave.approve', 'task.view']);
    assignSchedule($boss, weeklySchedule(generalShift()));
    $worker = employeeWithUser($boss, ['leave.apply']);
    forceLifecycle($worker, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($worker, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($worker);
    $foreign = app(Leaves::class)->request($worker, LeaveType::query()->where('code', 'EL')->first(), '2026-09-23', '2026-09-23', 'Elsewhere');

    actAsTenant($this->tenant);
    $ids = (new ApprovalCenter(app(TenantContext::class)))->pending($this->manager->user)->pluck('id');
    expect($ids)->toContain('leave:'.$this->request->id)->not->toContain('leave:'.$foreign->id);
    expect(fn () => app(ApprovalDecisions::class)->decide($this->manager->user, 'leave:'.$foreign->id, 'approve'))->toThrow(AuthorizationException::class);
});

it('decides attendance corrections and shows the corrected times', function () {
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot to punch out', '2026-09-18 09:30', '2026-09-18 18:45', $this->employee->user);
    $item = app(ApprovalCenter::class)->pending($this->manager->user)->firstWhere('id', 'regularisation:'.$reg->id);

    expect($item)->not->toBeNull()
        ->and(collect($item->changes)->pluck('after')->all())->toBe(['09:30', '18:45'])
        ->and($item->group())->toBe('today');

    app(ApprovalDecisions::class)->decide($this->manager->user, 'regularisation:'.$reg->id, 'approve');
    expect($reg->fresh()->status)->toBe('approved');
});

it('renders the Approval Center and My work, and decides from the page', function () {
    actAsTenant(null);
    $this->actingAs($this->manager->user);

    $this->get(Approvals::getUrl())->assertOk()->assertSee('Trip to Kochi')->assertSee('Earned leave balance')
        ->assertSee('data-approval-id="leave:'.$this->request->id.'"', false);
    $this->get(MyWork::getUrl(['tab' => 'today']))->assertOk();

    actAsTenant($this->tenant);
    Livewire::test(Approvals::class)->call('decide', 'leave:'.$this->request->id, 'approve', null)->assertNotified('Leave approved');
    expect($this->request->fresh()->status)->toBe('approved');

    // The requester sees it under "Completed" for the approver, never as their own decision.
    Livewire::test(Approvals::class)->assertSee('Completed');
});

it('previews working days and the balance before → after, and submits the same form to the Leaves service', function () {
    $preview = BeforeAfterPreview::leaveChanges($this->employee, $this->el, '2026-09-28', '2026-09-29');
    expect($preview['changes'])->toBe([
        ['label' => 'Working days', 'before' => null, 'after' => '2'],
        ['label' => 'Earned leave available', 'before' => '22', 'after' => '20'],
    ]);
    expect(BeforeAfterPreview::leaveChanges($this->employee, $this->el, '2026-09-29', '2026-09-28')['changes'])->toBe([]);

    actAsTenant($this->tenant);
    $this->actingAs($this->employee->user);
    Livewire::test(Home::class)
        ->callAction('requestLeave', data: ['leave_type_id' => $this->el->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-29', 'from_session' => 'full', 'to_session' => 'full', 'reason' => 'Family visit'])
        ->assertHasNoActionErrors()
        ->assertNotified('Leave requested: 2.0 day(s)');

    expect((float) $this->employee->leaveRequests()->where('reason', 'Family visit')->value('days'))->toBe(2.0);
});
