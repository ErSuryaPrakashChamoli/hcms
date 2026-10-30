<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\RequestReturn;
use App\Domain\Workflow\Models\TaskComment;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Services\RequestReturns;
use App\Domain\Workflow\Services\TaskComments;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\RequestDiscussion;
use App\Filament\Pages\TaskInbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne setup parity P3 (Inbox → Inbox actions): per request type, bulk approval, a discussion between requester and
| approvers, and sending back to the requester; Reassign for workflow tasks. Every switch is off by default.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual']]);
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'attendance.view', 'attendance.approve', 'task.view', 'task.act', 'employee.view']);
    assignSchedule($this->manager, weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'attendance.regularise', 'task.view', 'task.act']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->actingAs($this->employee->user);
    $this->first = app(Leaves::class)->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Trip');
    $this->second = app(Leaves::class)->request($this->employee, $this->el, '2026-09-28', '2026-09-28', 'Wedding');
    $this->center = fn () => new ApprovalCenter(app(TenantContext::class));
});

/** Switch an inbox action on for request types (Setup → Automation → Inbox actions). */
function acxSwitch(string $action, array $kinds): void
{
    app(SettingsRepository::class)->set("inbox.{$action}.types", $kinds, 'Inbox actions');
}

it('keeps today\'s inbox by default: one decision at a time, approve or reject, no discussion', function () {
    expect(config('peopleos.settings')['inbox.bulk_approval.types'])->toBe([])
        ->and(config('peopleos.settings')['inbox.discussion.types'])->toBe([])
        ->and(config('peopleos.settings')['inbox.send_back.types'])->toBe([]);

    $item = app(ApprovalCenter::class)->pending($this->manager->user)->firstWhere('id', 'leave:'.$this->first->id);
    expect($item->decisions)->toBe(['approve', 'reject']);

    $result = app(ApprovalDecisions::class)->approveMany($this->manager->user, ['leave:'.$this->first->id]);
    expect($result['approved'])->toBe([])->and($result['failed'])->toHaveKey('leave:'.$this->first->id)
        ->and($this->first->fresh()->status)->toBe('pending');

    expect(fn () => app(TaskComments::class)->postOnItem($this->manager->user, $item, 'Why?'))->toThrow(AuthorizationException::class, 'not switched on')
        ->and(fn () => app(ApprovalDecisions::class)->decide($this->manager->user, 'leave:'.$this->first->id, 'send_back', 'More detail'))->toThrow(AuthorizationException::class);
});

it('lists every request type of the center and its sources by name for the Setup centre', function () {
    $kinds = ApprovalCenter::kinds();
    expect($kinds)->toMatchArray(['workflow_task' => 'Workflow step', 'leave' => 'Leave', 'regularisation' => 'Attendance regularisation', 'loan' => 'Loan / salary advance',
        'transfer' => 'Transfer request', 'expense_report' => 'Expense report', 'leave_encashment' => 'Leave encashment'])
        // HROne parity MS-3: self-service profile changes may be sent back too.
        ->and(ApprovalCenter::sendBackKinds())->toEqualCanonicalizing(['workflow_task', 'leave', 'time_office', 'loan', 'reimbursement_claim', 'profile_change'])
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'approval_kinds']))->toBe($kinds);

    $admin = tenantUser($this->tenant, ['*']);
    $changed = app(SetupCatalogue::class)->save('automation', [SetupCatalogue::field('inbox.bulk_approval.types') => ['leave', 'loan']], 'Bulk for leave', $admin);
    expect($changed)->toBe(['inbox.bulk_approval.types'])
        ->and(fn () => app(SetupCatalogue::class)->save('automation', [SetupCatalogue::field('inbox.send_back.types') => ['nothing']], 'x', $admin))->toThrow(RuntimeException::class, 'does not exist');
});

it('approves the ticked requests of the types switched on, each on its own, and lists the ones refused', function () {
    acxSwitch('bulk_approval', ['leave']);
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $before = AuditEvent::query()->withoutGlobalScopes()->count();

    $result = app(ApprovalDecisions::class)->approveMany($this->manager->user, ['leave:'.$this->first->id, 'leave:'.$this->second->id, 'regularisation:'.$reg->id, 'leave:999999'], 'Enjoy');

    expect(array_keys($result['approved']))->toBe(['leave:'.$this->first->id, 'leave:'.$this->second->id])
        ->and($result['failed'])->toHaveKeys(['regularisation:'.$reg->id, 'leave:999999'])
        ->and($result['failed']['regularisation:'.$reg->id])->toContain('bulk approval is not switched on')
        ->and($this->first->fresh()->status)->toBe('approved')->and($this->second->fresh()->review_note)->toBe('Enjoy')
        ->and($reg->fresh()->status)->toBe('pending')
        ->and(AuditEvent::query()->withoutGlobalScopes()->count())->toBeGreaterThan($before);

    // Already decided: listed as refused, nothing else changes.
    $again = app(ApprovalDecisions::class)->approveMany($this->manager->user, ['leave:'.$this->first->id]);
    expect($again['approved'])->toBe([])->and($again['failed'])->toHaveKey('leave:'.$this->first->id);
});

it('offers bulk approval on the Approvals page only for the types switched on', function () {
    $page = Livewire::actingAs($this->manager->user)->test(Approvals::class);
    expect($page->instance()->bulkIds())->toBe([]);

    acxSwitch('bulk_approval', ['leave']);
    $page = Livewire::actingAs($this->manager->user)->test(Approvals::class)->assertSee('Approve selected');
    expect($page->instance()->bulkIds())->toEqualCanonicalizing(['leave:'.$this->first->id, 'leave:'.$this->second->id]);

    $page->call('approveSelected', ['leave:'.$this->first->id, 'leave:'.$this->second->id], null)->assertDispatched('pos-approval-bulk-done');
    expect($this->first->fresh()->status)->toBe('approved')->and($this->second->fresh()->status)->toBe('approved');
});

it('keeps a discussion between the requester and the approvers, immutable, audited without its text and notified', function () {
    acxSwitch('discussion', ['leave']);
    $item = app(ApprovalCenter::class)->find($this->manager->user, 'leave:'.$this->first->id);
    $comments = app(TaskComments::class);

    $message = $comments->postOnItem($this->manager->user, $item, 'Who covers your client call?');
    expect($message->kind)->toBe('leave')->and($message->commentable_id)->toBe($this->first->id);
    expect(NotificationDelivery::query()->where('user_id', $this->employee->user_id)->where('event', 'workflow.task.commented')->exists())->toBeTrue();

    // The requester reads and answers; someone outside the request does not.
    expect($comments->mayView($this->employee->user, $this->first, 'leave'))->toBeTrue();
    $comments->reply($this->employee->user, $this->first->fresh(), 'leave', 'Ravi covers it.');
    expect(NotificationDelivery::query()->where('user_id', $this->manager->user_id)->where('event', 'workflow.task.commented')->exists())->toBeTrue();
    $outsider = tenantUser($this->tenant, ['leave.view']);
    expect($comments->mayView($outsider, $this->first, 'leave'))->toBeFalse()
        ->and(fn () => $comments->reply($outsider, $this->first, 'leave', 'Hi'))->toThrow(AuthorizationException::class)
        ->and(fn () => $comments->reply($this->employee->user, $this->first, 'leave', str_repeat('x', 2001)))->toThrow(InvalidArgumentException::class);

    expect(app(TaskComments::class)->thread($this->first->fresh())->pluck('body')->all())->toBe(['Who covers your client call?', 'Ravi covers it.'])
        ->and(fn () => $message->update(['body' => 'changed']))->toThrow(RuntimeException::class, 'cannot be changed');

    $audit = AuditEvent::query()->withoutGlobalScopes()->where('entity_type', TaskComment::class)->where('entity_id', $message->id)->first();
    expect($audit)->not->toBeNull()->and(json_encode($audit->fieldChanges()->get()->toArray()))->not->toContain('client call');

    // The requester's page, from the notification; closed to outsiders.
    Livewire::actingAs($this->employee->user)->test(RequestDiscussion::class, ['comment' => $message->id])->assertOk()->assertSee('Ravi covers it.')
        ->set('body', 'Also back on Monday.')->call('post')->assertHasNoErrors();
    expect(TaskComment::query()->count())->toBe(3);
    $this->actingAs($outsider);
    $this->get(RequestDiscussion::getUrl(['comment' => $message->id]))->assertForbidden();

    // The approver posts from the Approval Center.
    Livewire::actingAs($this->manager->user)->test(Approvals::class)->assertSee('Discussion')->call('comment', 'leave:'.$this->first->id, 'Thanks')->assertDispatched('pos-approval-commented');
    expect(TaskComment::query()->count())->toBe(4);
});

it('sends a leave request back to its requester and brings it back on resubmission', function () {
    $returns = app(RequestReturns::class);
    acxSwitch('send_back', ['leave']);
    $item = app(ApprovalCenter::class)->pending($this->manager->user)->firstWhere('id', 'leave:'.$this->first->id);
    expect($item->decisions)->toBe(['approve', 'reject', 'send_back'])->and($item->needsNote('send_back'))->toBeTrue();

    // Never offered to the requester on their own request (it is not in their queue either).
    expect(app(ApprovalCenter::class)->pending($this->employee->user)->pluck('id'))->not->toContain('leave:'.$this->first->id);

    expect(fn () => app(ApprovalDecisions::class)->decide($this->manager->user, 'leave:'.$this->first->id, 'send_back', ' '))->toThrow(InvalidArgumentException::class);
    $message = app(ApprovalDecisions::class)->decide($this->manager->user, 'leave:'.$this->first->id, 'send_back', 'Add who covers your work');
    expect($message)->toBe('Sent back to the requester');

    $return = RequestReturn::query()->sole();
    expect($return->status)->toBe('open')->and($return->requester_id)->toBe($this->employee->user_id)->and($return->kind)->toBe('leave')
        ->and($this->first->fresh()->status)->toBe('pending') // the balance stays reserved, nothing else changes
        ->and(($this->center)()->pending($this->manager->user)->pluck('id'))->not->toContain('leave:'.$this->first->id)->toContain('leave:'.$this->second->id)
        ->and($returns->waitingFor($this->employee->user)->pluck('id')->all())->toBe([$return->id])
        ->and(NotificationDelivery::query()->where('user_id', $this->employee->user_id)->where('event', 'workflow.task.sent_back')->exists())->toBeTrue()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('entity_type', RequestReturn::class)->where('entity_id', $return->id)->where('reason', 'Add who covers your work')->exists())->toBeTrue();

    // Only the requester resubmits.
    expect(fn () => $returns->resubmit($return, $this->manager->user, 'x'))->toThrow(AuthorizationException::class);
    Livewire::actingAs($this->employee->user)->test(TaskInbox::class)->call('setTab', 'returned')->assertSee('Add who covers your work')
        ->callTableAction('resubmit', $return, ['response' => 'Ravi covers it'])->assertHasNoTableActionErrors();

    expect($return->fresh()->status)->toBe('resubmitted')->and($return->fresh()->response)->toBe('Ravi covers it')
        ->and(NotificationDelivery::query()->where('user_id', $this->manager->user_id)->where('event', 'workflow.task.resubmitted')->exists())->toBeTrue()
        ->and(($this->center)()->pending($this->manager->user)->pluck('id'))->toContain('leave:'.$this->first->id);

    // A request decided while sent back drops out of the requester's list and cannot be resubmitted.
    app(ApprovalDecisions::class)->decide($this->manager->user, 'leave:'.$this->second->id, 'send_back', 'Which wedding?');
    $open = RequestReturn::query()->where('status', 'open')->sole();
    app(Leaves::class)->approve($this->second->fresh(), 'Fine', $this->manager->user);
    expect(app(RequestReturns::class)->waitingFor($this->employee->user))->toBeEmpty()
        ->and(fn () => app(RequestReturns::class)->resubmit($open, $this->employee->user, 'x'))->toThrow(RuntimeException::class, 'decided or withdrawn');
    expect($open->fresh()->status)->toBe('closed');
});

it('sends back a time-office request through its source, and offers nothing a source does not support', function () {
    acxSwitch('send_back', ['time_office', 'leave_encashment']);
    $request = app(TimeOfficeRequests::class)->request($this->employee, 'on_duty', ['from_date' => '2026-09-22', 'session' => 'full'], 'Client visit');
    $item = app(ApprovalCenter::class)->pending($this->manager->user)->firstWhere('id', 'time_office:'.$request->id);
    expect($item)->not->toBeNull()->and($item->can('send_back'))->toBeTrue();

    app(ApprovalDecisions::class)->decide($this->manager->user, 'time_office:'.$request->id, 'send_back', 'Which client?');
    expect($request->fresh()->status)->toBe('pending')
        ->and(($this->center)()->pending($this->manager->user)->pluck('id'))->not->toContain('time_office:'.$request->id)
        ->and(app(RequestReturns::class)->openFor($request)?->requester_id)->toBe($this->employee->user_id);

    // A type no source can send back (leave encashment) is not offered even when listed.
    expect(ApprovalCenter::sendBackKinds())->not->toContain('leave_encashment');
});

it('sends a workflow step back to the run\'s initiator, who resubmits it to a fresh round of the same step', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approval', 'config' => ['mode' => 'sequential', 'approvers' => [['type' => 'manager'], ['type' => 'user', 'user_id' => null]]]]]);
    $hr = tenantUser($this->tenant, ['task.view', 'task.act', 'leave.view']);
    $nodes[1]['config']['approvers'][1]['user_id'] = $hr->id;
    publishWorkflow($nodes, $edges, ['trigger_event' => 'leave.requested', 'subject_type' => LeaveRequest::class]);
    acxSwitch('send_back', ['workflow_task']);
    acxSwitch('discussion', ['workflow_task']);

    $this->actingAs($this->employee->user);
    $request = app(Leaves::class)->request($this->employee, $this->el, '2026-09-30', '2026-09-30', 'Via workflow');
    $instance = WorkflowInstance::query()->where('subject_id', $request->id)->where('subject_type', $request->getMorphClass())->sole();
    expect($instance->started_by)->toBe($this->employee->user_id);
    $engine = app(WorkflowEngine::class);
    $engine->completeTask($instance->tasks()->first(), 'approved', 'Fine', $this->manager->user);

    $item = app(ApprovalCenter::class)->pending($hr)->first(fn ($i) => $i->type === 'workflow_task');
    expect($item->decisions)->toBe(['approve', 'reject', 'send_back']);
    app(TaskComments::class)->postOnItem($hr, $item, 'Is this a half day?');
    app(ApprovalDecisions::class)->decide($hr, $item->id, 'send_back', 'Say if it is a half day');

    $instance->refresh();
    expect($instance->status)->toBe(InstanceStatus::Returned)
        ->and($instance->tasks()->where('status', TaskStatus::SentBack)->count())->toBe(1)
        ->and($request->fresh()->status)->toBe('pending')
        // The leave stays with its workflow: it is not listed as a plain leave decision meanwhile.
        ->and(($this->center)()->pending($this->manager->user)->pluck('id'))->not->toContain('leave:'.$request->id)
        ->and(app(TaskComments::class)->mayView($this->employee->user, $instance, 'workflow_task'))->toBeTrue();
    expect(fn () => $engine->resubmit($instance, 'x', $hr))->toThrow(WorkflowException::class, 'Only the person who started');

    app(RequestReturns::class)->resubmit(RequestReturn::query()->sole(), $this->employee->user, 'Full day');
    $instance->refresh();
    $open = $instance->tasks()->where('status', TaskStatus::Pending)->get();
    // A fresh round: the step starts again with its first approver; the earlier approval no longer counts.
    expect($instance->status)->toBe(InstanceStatus::Running)->and($open)->toHaveCount(1)->and($open->first()->assignee_id)->toBe($this->manager->user_id);

    $engine->completeTask($open->first(), 'approved', 'Ok', $this->manager->user);
    expect($instance->refresh()->status)->toBe(InstanceStatus::Running);
    $engine->completeTask($instance->tasks()->where('status', TaskStatus::Pending)->sole(), 'approved', 'Ok', $hr);
    expect($instance->refresh()->status)->toBe(InstanceStatus::Completed)->and($instance->outcome)->toBe('approved')
        ->and($request->fresh()->status)->toBe('approved');
});

it('refuses to send back a run nobody started, and lets the run viewer cancel a run that was sent back', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $workflow = publishWorkflow($nodes, $edges);
    auth()->logout();
    $instance = app(WorkflowEngine::class)->start($workflow, $this->employee, [], null);
    acxSwitch('send_back', ['workflow_task']);
    $item = app(ApprovalCenter::class)->pending($this->manager->user)->first(fn ($i) => $i->type === 'workflow_task');
    expect($item->can('send_back'))->toBeFalse()
        ->and(fn () => app(WorkflowEngine::class)->sendBack($instance->tasks()->first(), 'x', $this->manager->user))->toThrow(WorkflowException::class, 'nobody to send it back to');

    $started = app(WorkflowEngine::class)->start($workflow, $this->employee, [], $this->employee->user);
    $returned = app(WorkflowEngine::class)->sendBack($started->tasks()->first(), 'Fix it', $this->manager->user);
    expect(app(WorkflowEngine::class)->cancel($returned, 'Withdrawn')->status)->toBe(InstanceStatus::Cancelled);
});

it('reassigns a pending workflow task for holders of task.reassign, audited and notified', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'manager']]]]);
    $instance = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $this->employee, [], $this->employee->user);
    $task = $instance->tasks()->first();
    $lead = tenantUser($this->tenant, ['task.view', 'task.act', 'task.reassign']);
    $other = tenantUser($this->tenant, ['task.view', 'task.act']);
    $engine = app(WorkflowEngine::class);

    expect(fn () => $engine->reassign($task, $other, null, 'Leave', $this->manager->user))->toThrow(WorkflowException::class, 'task.reassign')
        ->and(fn () => $engine->reassign($task, $this->employee->user, null, 'Self', $lead))->toThrow(WorkflowException::class, 'Nobody decides')
        ->and(fn () => $engine->reassign($task, null, null, 'Nobody', $lead))->toThrow(WorkflowException::class, 'one person or one role');

    $engine->reassign($task, $other, null, 'Manager on leave', $lead);
    expect($task->fresh()->assignee_id)->toBe($other->id)
        ->and($instance->actions()->where('action', 'task.reassigned')->exists())->toBeTrue()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Assign->value)->where('reason', 'Manager on leave')->exists())->toBeTrue()
        ->and(NotificationDelivery::query()->where('user_id', $other->id)->where('event', 'workflow.task.reassigned')->exists())->toBeTrue();

    // From the task inbox ("All open tasks") for the lead.
    $view = tenantUser($this->tenant, ['task.view', 'task.view_all', 'task.reassign']);
    Livewire::actingAs($view)->test(TaskInbox::class)->call('setTab', 'all')
        ->callTableAction('reassign', $task->fresh(), ['to' => 'user', 'user_id' => $this->manager->user_id, 'reason' => 'Back'])->assertHasNoTableActionErrors();
    expect($task->fresh()->assignee_id)->toBe($this->manager->user_id);
});
