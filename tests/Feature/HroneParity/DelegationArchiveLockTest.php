<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\SelfServiceLock;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Loans\Services\Loans;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\InboxArchive;
use App\Domain\Workflow\Models\TaskDelegation;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\InboxArchives;
use App\Domain\Workflow\Services\TaskDelegations;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\OutOfOffice;
use App\Filament\Pages\TaskInbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne setup parity P4 (Inbox → Delegate, Archive inbox, Employee request lock): out-of-office delegation decided in
| the delegator's stead and audited as such, archiving Approval Center items (manual, bulk, nightly), and the lock on
| employees' own self-service requests. Every switch is off by default.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'attendance.view', 'attendance.approve', 'task.view', 'task.act', 'task.delegate', 'employee.view']);
    assignSchedule($this->manager, weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'attendance.regularise', 'payroll.request', 'servicedesk.request', 'task.view', 'task.act']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    // The colleague who stands in: no leave approval of their own.
    $this->colleague = employeeWithUser(null, ['task.view', 'task.act', 'task.delegate', 'leave.apply']);
    $this->actingAs($this->employee->user);
    $this->leave = app(Leaves::class)->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Trip');
    $this->set = fn (string $key, mixed $value) => app(SettingsRepository::class)->set($key, $value, 'test');
    $this->center = fn () => new ApprovalCenter(app(TenantContext::class));
});

/** Out of office for the manager, handing the listed request types to the colleague. */
function dalDelegate(array $types = [], string $from = '2026-09-21', string $to = '2026-09-30'): TaskDelegation
{
    $manager = test()->manager->user;

    return app(TaskDelegations::class)->create($manager, $manager, test()->colleague->user, $from, $to, $types, 'Annual leave');
}

it('keeps today\'s inbox by default: no delegation, no archive, no lock', function () {
    $defaults = config('peopleos.settings');
    expect($defaults['inbox.delegation.types'])->toBe([])->and($defaults['inbox.archive.types'])->toBe([])->and($defaults['inbox.archive.enabled'])->toBeFalse()
        ->and($defaults['inbox.ess_lock.enabled'])->toBeFalse()->and(TaskDelegations::switchedOn())->toBeFalse()->and(app(InboxArchives::class)->active())->toBeFalse();

    expect(fn () => dalDelegate())->toThrow(RuntimeException::class, 'not switched on')
        ->and(app(ApprovalCenter::class)->pending($this->colleague->user))->toBeEmpty()
        ->and(fn () => app(InboxArchives::class)->archive($this->manager->user, app(ApprovalCenter::class)->find($this->manager->user, 'leave:'.$this->leave->id)))->toThrow(AuthorizationException::class);

    // A long-overdue task does not lock anything while the lock is off.
    WorkflowTask::query()->create(['workflow_instance_id' => dalRun($this->employee)->id, 'node_id' => 'x', 'type' => 'task', 'title' => 'Old', 'assignee_id' => $this->employee->user_id, 'sequence' => 1, 'status' => TaskStatus::Pending, 'due_at' => now()->subDays(30)]);
    expect(app(SelfServiceLock::class)->refusal($this->employee, 'leave', $this->employee->user))->toBeNull();
});

/** A running workflow on an employee (for tasks to hang on). */
function dalRun($employee)
{
    [$nodes, $edges] = linear([['id' => 'wait', 'type' => 'wait', 'name' => 'Wait', 'config' => ['hours' => 100]]]);

    return app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $employee);
}

it('lets the delegate decide the delegator\'s requests in their stead, audited as the delegate on behalf of the delegator', function () {
    ($this->set)('inbox.delegation.types', ['leave']);
    $delegation = dalDelegate();
    expect(NotificationDelivery::query()->where('user_id', $this->colleague->user_id)->where('event', 'workflow.task.delegated')->exists())->toBeTrue();

    $item = ($this->center)()->pending($this->colleague->user)->firstWhere('id', 'leave:'.$this->leave->id);
    expect($item)->not->toBeNull()->and($item->onBehalfOfUserId)->toBe($this->manager->user_id)->and($item->facts[0])->toContain('On behalf of '.$this->manager->user->name);

    $message = app(ApprovalDecisions::class)->decide($this->colleague->user, 'leave:'.$this->leave->id, 'approve', 'Enjoy');
    expect($message)->toContain('on behalf of')
        ->and($this->leave->fresh()->status)->toBe('approved')->and($this->leave->fresh()->reviewed_by)->toBe($this->manager->user_id);

    $audit = AuditEvent::query()->where('entity_type', LeaveRequest::class)->where('entity_id', (string) $this->leave->id)->where('action', 'APPROVED')->latest('occurred_at')->first()
        ?? AuditEvent::query()->where('entity_type', LeaveRequest::class)->where('entity_id', (string) $this->leave->id)->latest('occurred_at')->first();
    expect($audit->actor_id)->toBe($this->colleague->user_id)
        ->and($audit->metadata['on_behalf_of']['user_id'])->toBe($this->manager->user_id)
        ->and($audit->metadata['on_behalf_of']['delegation_id'])->toBe($delegation->id);
});

it('never hands a delegate their own request, nor request types not delegated, nor anything once ended', function () {
    ($this->set)('inbox.delegation.types', ['leave', 'regularisation']);
    forceLifecycle($this->colleague, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->colleague, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->colleague);
    // The colleague's own leave is in the manager's queue (tenant-wide approver) but never handed to the colleague.
    $own = app(Leaves::class)->request($this->colleague, $this->el, '2026-09-24', '2026-09-24', 'Own', 'full', 'full', null, $this->colleague->user);
    expect(($this->center)()->pending($this->manager->user)->pluck('id'))->toContain('leave:'.$own->id);
    $delegation = dalDelegate(['leave']);
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);

    $ids = ($this->center)()->pending($this->colleague->user)->pluck('id');
    expect($ids)->toContain('leave:'.$this->leave->id)->not->toContain('leave:'.$own->id)->not->toContain('regularisation:'.$reg->id)
        ->and(fn () => app(ApprovalDecisions::class)->decide($this->colleague->user, 'regularisation:'.$reg->id, 'approve'))->toThrow(AuthorizationException::class);

    app(TaskDelegations::class)->revoke($delegation, $this->manager->user, 'Back early');
    expect(($this->center)()->pending($this->colleague->user))->toBeEmpty()
        ->and(fn () => app(ApprovalDecisions::class)->decide($this->colleague->user, 'leave:'.$this->leave->id, 'approve'))->toThrow(AuthorizationException::class)
        ->and($this->leave->fresh()->status)->toBe('pending');
});

it('checks every out-of-office period: dates, people, overlaps, request types and who may set it up', function () {
    ($this->set)('inbox.delegation.types', ['leave']);
    $manager = $this->manager->user;
    $delegations = app(TaskDelegations::class);
    expect(fn () => $delegations->create($manager, $manager, $manager, '2026-09-21', '2026-09-25', [], 'x'))->toThrow(RuntimeException::class, 'someone else')
        ->and(fn () => $delegations->create($manager, $manager, $this->colleague->user, '2026-09-20', '2026-09-25', [], 'x'))->toThrow(RuntimeException::class, 'past')
        ->and(fn () => $delegations->create($manager, $manager, $this->colleague->user, '2026-09-25', '2026-09-22', [], 'x'))->toThrow(RuntimeException::class, 'on or after')
        ->and(fn () => $delegations->create($manager, $manager, $this->colleague->user, '2026-09-21', '2026-09-25', ['loan'], 'x'))->toThrow(RuntimeException::class, 'switched on')
        ->and(fn () => $delegations->create($manager, $manager, $this->colleague->user, '2026-09-21', '2026-09-25', [], ' '))->toThrow(RuntimeException::class, 'reason');

    dalDelegate([], '2026-09-21', '2026-09-25');
    expect(fn () => $delegations->create($manager, $manager, $this->colleague->user, '2026-09-24', '2026-09-28', [], 'x'))->toThrow(RuntimeException::class, 'already an out-of-office')
        ->and(fn () => $delegations->create($this->colleague->user, $this->colleague->user, $manager, '2026-09-22', '2026-09-23', [], 'x'))->toThrow(RuntimeException::class, 'out of office over these dates too');

    // Only one's own (task.delegate), unless task.delegate_any.
    $stranger = tenantUser($this->tenant, ['task.view']);
    expect(fn () => $delegations->create($stranger, $stranger, $manager, '2026-10-01', '2026-10-02', [], 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => $delegations->create($this->colleague->user, $manager, $this->colleague->user, '2026-10-01', '2026-10-02', [], 'x'))->toThrow(AuthorizationException::class);
    $hr = tenantUser($this->tenant, ['task.delegate_any']);
    expect($delegations->create($hr, $this->colleague->user, $stranger, '2026-10-01', '2026-10-02', [], 'Sick leave')->created_by)->toBe($hr->id);
});

it('decides a delegated workflow step in the assignee\'s stead from the task inbox and the Approval Center', function () {
    ($this->set)('inbox.delegation.types', ['workflow_task']);
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $instance = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $this->employee);
    $task = $instance->tasks()->sole();
    dalDelegate();

    expect(TaskInbox::waitingFor(WorkflowTask::query(), $this->colleague->user)->pluck('id')->all())->toBe([$task->id])
        ->and(TaskInbox::waitingFor(WorkflowTask::query(), $this->employee->user)->pluck('id')->all())->toBe([]);

    app(ApprovalDecisions::class)->decide($this->colleague->user, 'workflow_task:'.$task->id, 'approve', 'Fine');
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Approved)->and($task->completed_by)->toBe($this->manager->user_id)->and($task->delegate_id)->toBe($this->colleague->user_id);
    $log = WorkflowAction::query()->where('workflow_instance_id', $instance->id)->where('action', 'task.approved')->sole();
    expect($log->actor_id)->toBe($this->colleague->user_id)->and($log->payload['on_behalf_of']['user_id'])->toBe($this->manager->user_id);
});

it('sets up and ends out-of-office periods on its screen, closed to people without the permission', function () {
    ($this->set)('inbox.delegation.types', ['leave']);
    $this->actingAs($this->manager->user);
    Livewire::test(OutOfOffice::class)->assertOk()
        ->callAction('delegate', ['delegate_id' => $this->colleague->user_id, 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-28', 'types' => [], 'reason' => 'Holiday'])
        ->assertHasNoActionErrors();
    $delegation = TaskDelegation::query()->sole();
    expect($delegation->delegator_id)->toBe($this->manager->user_id)->and($delegation->delegate_id)->toBe($this->colleague->user_id);

    Livewire::test(OutOfOffice::class)->assertSee($this->colleague->user->name)
        ->callTableAction('end', $delegation, ['reason' => 'Back early']);
    expect($delegation->fresh()->status)->toBe('revoked');

    $this->actingAs(tenantUser($this->tenant, ['task.view']));
    $this->get(OutOfOffice::getUrl())->assertForbidden();
});

it('archives an item from the approver\'s queue without deciding it, and restores it', function () {
    ($this->set)('inbox.archive.types', ['leave']);
    $manager = $this->manager->user;
    $item = app(ApprovalCenter::class)->find($manager, 'leave:'.$this->leave->id);
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    expect(fn () => app(InboxArchives::class)->archive($manager, app(ApprovalCenter::class)->find($manager, 'regularisation:'.$reg->id)))->toThrow(AuthorizationException::class);

    Livewire::actingAs($manager)->test(Approvals::class)->assertSee('Archive')->call('archive', 'leave:'.$this->leave->id);
    expect(($this->center)()->pending($manager)->pluck('id'))->not->toContain('leave:'.$this->leave->id)
        ->and(($this->center)()->archived($manager)->pluck('id'))->toContain('leave:'.$this->leave->id)
        ->and(app(ApprovalCenter::class)->count($manager))->toBe(1)
        ->and($this->leave->fresh()->status)->toBe('pending')
        ->and(InboxArchive::query()->sole()->archived_by)->toBe($manager->id);

    Livewire::actingAs($manager)->test(Approvals::class)->assertSee('Archived')->call('restore', 'leave:'.$this->leave->id, 'leave');
    expect(($this->center)()->pending($manager)->pluck('id'))->toContain('leave:'.$this->leave->id)->and($item)->not->toBeNull();
});

it('archives items waiting too long every night (except the excluded types), and never one the person restored', function () {
    ($this->set)('inbox.archive.enabled', true);
    ($this->set)('inbox.archive.mode', 'auto');
    ($this->set)('inbox.archive.after_days', 3);
    ($this->set)('inbox.archive.excluded_types', ['regularisation']);
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $manager = $this->manager->user;

    $this->artisan('peopleos:inbox:archive', ['--tenant' => $this->tenant->id])->assertSuccessful();
    expect(InboxArchive::query()->count())->toBe(0);

    $this->travel(4)->days();
    actAsTenant($this->tenant);
    $this->artisan('peopleos:inbox:archive', ['--tenant' => $this->tenant->id])->assertSuccessful();
    actAsTenant($this->tenant);
    expect(InboxArchive::query()->where('user_id', $manager->id)->pluck('item_id')->all())->toBe(['leave:'.$this->leave->id])
        ->and(InboxArchive::query()->where('user_id', $manager->id)->sole()->archived_by)->toBeNull()
        ->and(($this->center)()->pending($manager)->pluck('id'))->toContain('regularisation:'.$reg->id)->not->toContain('leave:'.$this->leave->id);

    app(InboxArchives::class)->restore($manager, 'leave:'.$this->leave->id, 'leave');
    $this->artisan('peopleos:inbox:archive', ['--tenant' => $this->tenant->id])->assertSuccessful();
    actAsTenant($this->tenant);
    expect(($this->center)()->pending($manager)->pluck('id'))->toContain('leave:'.$this->leave->id);
});

it('lets the person archive what waits too long themselves in manual mode; the command leaves them alone', function () {
    ($this->set)('inbox.archive.enabled', true);
    ($this->set)('inbox.archive.after_days', 1);
    $this->travel(2)->days();
    $manager = $this->manager->user;

    $this->artisan('peopleos:inbox:archive', ['--tenant' => $this->tenant->id])->assertSuccessful();
    actAsTenant($this->tenant);
    expect(InboxArchive::query()->count())->toBe(0);

    $page = Livewire::actingAs($manager)->test(Approvals::class);
    expect($page->instance()->bulkArchiveCount())->toBe(1);
    $page->call('archiveOld');
    expect(InboxArchive::query()->sole()->archived_by)->toBe($manager->id)->and($this->leave->fresh()->status)->toBe('pending');
});

it('hides archived workflow steps from the task inbox and lists them under Archived', function () {
    ($this->set)('inbox.archive.types', ['workflow_task']);
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $task = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $this->employee)->tasks()->sole();
    $manager = $this->manager->user;
    app(InboxArchives::class)->archive($manager, app(ApprovalCenter::class)->find($manager, 'workflow_task:'.$task->id));

    expect(TaskInbox::waitingFor(WorkflowTask::query(), $manager)->count())->toBe(0)->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    $this->actingAs($manager);
    Livewire::test(TaskInbox::class)->call('setTab', 'archived')->assertCanSeeTableRecords([$task])->callTableAction('restore', $task);
    expect(TaskInbox::waitingFor(WorkflowTask::query(), $manager)->count())->toBe(1);
});

it('locks an employee\'s own requests while they have overdue tasks, naming them, and lifts the lock when they are done', function () {
    ($this->set)('inbox.ess_lock.enabled', true);
    $overdue = WorkflowTask::query()->create(['workflow_instance_id' => dalRun($this->employee)->id, 'node_id' => 'x', 'type' => 'task', 'title' => 'Upload your PAN card', 'assignee_id' => $this->employee->user_id, 'sequence' => 1, 'status' => TaskStatus::Pending, 'due_at' => now()->subDays(10)]);
    $leaves = app(Leaves::class);

    expect(fn () => $leaves->request($this->employee, $this->el, '2026-09-25', '2026-09-25', 'Errand', 'full', 'full', null, $this->employee->user))->toThrow(RuntimeException::class, 'Upload your PAN card')
        ->and(fn () => app(TimeOfficeRequests::class)->request($this->employee, 'on_duty', ['from_date' => '2026-09-22', 'session' => 'full'], 'Client', $this->employee->user))->toThrow(RuntimeException::class, 'locked')
        ->and(fn () => app(Loans::class)->request($this->employee, 'personal', 1000, 1, 'X', $this->employee->user))->toThrow(RuntimeException::class, 'locked')
        ->and(fn () => app(ServiceRequests::class)->openGeneric($this->employee, TicketCategory::query()->create(['code' => 'GEN_LOCK', 'name' => 'General', 'status' => 'active']), 'Help', 'Please', 'normal', $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'locked');

    // HR raising it on the employee's behalf is never locked; nor an exempted employee, nor a request kind left out.
    $hr = tenantUser($this->tenant, ['leave.*', 'employee.view']);
    expect($leaves->request($this->employee, $this->el, '2026-09-25', '2026-09-25', 'On behalf', 'full', 'full', null, $hr)->status)->toBe('pending');
    ($this->set)('inbox.ess_lock.request_kinds', ['loan']);
    expect($leaves->request($this->employee, $this->el, '2026-09-28', '2026-09-28', 'Kind not locked', 'full', 'full', null, $this->employee->user)->status)->toBe('pending');
    ($this->set)('inbox.ess_lock.request_kinds', []);
    ($this->set)('inbox.ess_lock.exempt_employee_ids', [(string) $this->employee->id]);
    expect(app(SelfServiceLock::class)->refusal($this->employee, 'leave', $this->employee->user))->toBeNull();
    ($this->set)('inbox.ess_lock.exempt_employee_ids', []);

    // Tasks of kinds that do not count, or overdue for fewer days, do not lock.
    ($this->set)('inbox.ess_lock.task_kinds', ['onboarding']);
    expect(app(SelfServiceLock::class)->refusal($this->employee, 'leave', $this->employee->user))->toBeNull();
    ($this->set)('inbox.ess_lock.task_kinds', []);
    ($this->set)('inbox.ess_lock.after_days', 15);
    expect(app(SelfServiceLock::class)->refusal($this->employee, 'leave', $this->employee->user))->toBeNull();
    ($this->set)('inbox.ess_lock.after_days', 7);

    $overdue->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
    expect(app(SelfServiceLock::class)->refusal($this->employee, 'leave', $this->employee->user))->toBeNull();
});

it('locks requests during a lock period, for the employees listed', function () {
    ($this->set)('inbox.ess_lock.enabled', true);
    ($this->set)('inbox.ess_lock.basis', 'period');
    ($this->set)('inbox.ess_lock.period_from', '2026-09-20');
    ($this->set)('inbox.ess_lock.period_to', '2026-09-25');
    $lock = app(SelfServiceLock::class);

    expect($lock->refusal($this->employee, 'regularisation', $this->employee->user))->toContain('locked from 20 Sep 2026 to 25 Sep 2026');
    ($this->set)('inbox.ess_lock.employee_ids', [(string) $this->colleague->id]);
    expect($lock->refusal($this->employee, 'regularisation', $this->employee->user))->toBeNull()
        ->and($lock->refusal($this->colleague, 'leave', $this->colleague->user))->not->toBeNull();

    $this->travelTo('2026-09-26 09:00:00');
    expect($lock->refusal($this->colleague, 'leave', $this->colleague->user))->toBeNull();
});

it('guards every self-service request path with the lock', function () {
    $paths = [
        'Leave/Services/Leaves.php' => "'leave'", 'Attendance/Services/TimeOfficeRequests.php' => "'time_office'", 'Attendance/Services/Regularisations.php' => "'regularisation'",
        'Loans/Services/Loans.php' => "'loan'", 'Claims/Services/ReimbursementClaims.php' => "'reimbursement_claim'", 'Payroll/Services/VariablePay.php' => "'variable_pay'",
        'Expense/Services/ExpenseReports.php' => "'expense'", 'Expense/Services/ExpenseAdvances.php' => "'expense'", 'Expense/Services/TravelRequests.php' => "'travel'",
        'Assets/Services/AssetRequests.php' => "'asset_request'", 'ServiceDesk/Services/ServiceRequests.php' => "'service_desk'",
        // HROne parity MS-3: self-service profile changes.
        'People/Services/ProfileChanges.php' => "'profile_change'",
    ];
    foreach ($paths as $file => $kind) {
        expect(file_get_contents(app_path('Domain/'.$file)))->toContain('app(SelfServiceLock::class)->assertOpen($employee, '.$kind);
    }
    expect(array_keys(SelfServiceLock::REQUEST_KINDS))->toEqualCanonicalizing(array_values(array_unique(array_map(fn ($k) => trim($k, "'"), array_values($paths)))));
});

it('offers employees, employee task kinds and request kinds as Setup options, validated in scope', function () {
    $catalogue = app(SetupCatalogue::class);
    expect($catalogue->optionsFor(['type' => 'multiselect', 'options_from' => 'employee_task_kinds']))->toBe(SelfServiceLock::TASK_KINDS)
        ->and($catalogue->optionsFor(['type' => 'multiselect', 'options_from' => 'ess_request_kinds']))->toBe(SelfServiceLock::REQUEST_KINDS)
        ->and(array_keys($catalogue->optionsFor(['type' => 'multiselect', 'options_from' => 'employees'])))->toContain($this->employee->id, $this->colleague->id);

    $admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($admin);
    $changed = $catalogue->save('automation', [SetupCatalogue::field('inbox.ess_lock.exempt_employee_ids') => [(string) $this->employee->id], SetupCatalogue::field('inbox.delegation.types') => ['leave']], 'Lock rules', $admin);
    expect($changed)->toEqualCanonicalizing(['inbox.ess_lock.exempt_employee_ids', 'inbox.delegation.types'])
        ->and(fn () => $catalogue->save('automation', [SetupCatalogue::field('inbox.ess_lock.exempt_employee_ids') => ['999999']], 'x', $admin))->toThrow(RuntimeException::class, 'does not exist');
});
