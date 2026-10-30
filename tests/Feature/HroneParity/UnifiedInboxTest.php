<?php

use App\Domain\Attendance\Models\WorkSchedule;
use App\Domain\Attendance\Services\Regularisations;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\FormSubmission;
use App\Domain\Configuration\Services\Forms;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\InboxDecision;
use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ExperienceNavigation;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\InboxInsight;
use App\Domain\Experience\Services\UnifiedInbox;
use App\Domain\Experience\Support\InboxEntry;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAccrual;
use App\Domain\Leave\Services\Leaves;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\InstanceStatus;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Exceptions\WorkflowException;
use App\Domain\Workflow\Models\InboxArchive;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\SystemTasks;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\TaskInbox;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

require_once __DIR__.'/../Leave/LeaveTestHelpers.php';
require_once __DIR__.'/../Attendance/AttendanceTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity RQ-8 (matrix 20 §20.2.1, R.3, R.5): the unified inbox over the Approval Center and the person's own tasks —
| request-type facets, views and quick filters, the filter panel and search, due labels, list + detail with previous /
| next, tick-and-archive, "Not relevant" for system tasks (task.ignore), "More", the profile peek and the approver's
| Insight. No new visibility; decisions go through the owning services; Approvals and the Task inbox are unchanged.
*/

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    leavePolicy(['EL' => ['days' => 24, 'accrual_frequency' => 'annual'], 'CL' => ['days' => 12, 'accrual_frequency' => 'annual']]);
    $this->manager = employeeWithUser(null, ['leave.view', 'leave.approve', 'attendance.view', 'attendance.approve', 'task.view', 'task.act', 'employee.view']);
    assignSchedule($this->manager, weeklySchedule(generalShift()));
    $this->employee = employeeWithUser($this->manager, ['leave.apply', 'attendance.regularise', 'task.view', 'task.act']);
    forceLifecycle($this->employee, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($this->employee, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($this->employee);
    $this->el = LeaveType::query()->where('code', 'EL')->first();
    $this->cl = LeaveType::query()->where('code', 'CL')->first();
    $this->actingAs($this->employee->user);
    $this->leave = app(Leaves::class)->request($this->employee, $this->el, '2026-09-23', '2026-09-23', 'Trip to Kochi');
    $this->set = fn (string $key, mixed $value) => app(SettingsRepository::class)->set($key, $value, 'test');
});

/** The rows of a view, read afresh (the inbox and the Approval Center memoise per request). */
function rq8Rows(User $user, string $show = 'all')
{
    app()->forgetInstance(UnifiedInbox::class);
    app()->forgetInstance(ApprovalCenter::class);
    test()->actingAs($user);

    return app(UnifiedInbox::class)->rows($user, $show);
}

/** A running workflow with a to-do for the user, started by an event trigger (a system task) or by hand. */
function rq8Todo(User $assignee, Employee $about, string $trigger = 'employee.joined', string $title = 'Set up the laptop'): WorkflowTask
{
    [$nodes, $edges] = linear([['id' => 'todo', 'type' => 'task', 'name' => $title, 'config' => ['assignee' => ['type' => 'user', 'user_id' => $assignee->id]]]]);
    $run = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges, ['name' => 'Joiner set-up']), $about, ['trigger' => $trigger]);

    return WorkflowTask::query()->where('workflow_instance_id', $run->id)->sole();
}

// ---- what is listed ------------------------------------------------------------------------------------------------

it('lists exactly the Approval Center items and the person\'s own tasks, nothing more', function () {
    $todo = rq8Todo($this->employee->user, $this->employee);
    $rows = rq8Rows($this->manager->user);
    $decisions = $rows->where('source', 'decision')->pluck('id')->sort()->values()->all();
    expect($decisions)->toBe(app(ApprovalCenter::class)->pending($this->manager->user)->pluck('id')->sort()->values()->all())
        ->and($decisions)->toContain('leave:'.$this->leave->id)->not->toContain('workflow_task:'.$todo->id);

    // The employee: their to-do, never the manager's decisions.
    $mine = rq8Rows($this->employee->user);
    expect($mine->pluck('id')->all())->toBe(['workflow_task:'.$todo->id])
        ->and($mine->first()->system)->toBeTrue()->and($mine->first()->from)->not->toBeNull();

    // A colleague without approvals or tasks has no inbox; the page refuses them.
    $outsider = tenantUser($this->tenant, ['leave.apply']);
    $this->actingAs($outsider);
    expect(Inbox::canAccess())->toBeFalse();
    $this->get(Inbox::getUrl())->assertForbidden();
    $this->actingAs($this->manager->user)->get(Inbox::getUrl())->assertOk()->assertSee('Trip to Kochi', false);
});

it('groups rows by request type with counts named by the request registry, forms and workflow names', function () {
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $hr = tenantUser($this->tenant, ['task.view', 'task.act', 'form.view', 'form.submit']);
    $form = Form::create(['name' => 'Travel', 'key' => 'travel', 'requires_approval' => true]);
    app(Forms::class)->draft($form)->update(['fields' => [['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true]]]);
    app(Forms::class)->publish($form);
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Approve the form', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $hr->id]]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'form.submitted', 'subject_type' => FormSubmission::class, 'name' => 'Form approval']);
    app(Forms::class)->submit($form, ['amount' => 5000]);
    rq8Todo($hr, $this->employee);

    $facets = app(UnifiedInbox::class)->facets(rq8Rows($this->manager->user));
    expect($facets)->toHaveKeys(['leave', 'attendance_regularisation'])
        ->and($facets['leave']['count'])->toBe(1)->and($facets['attendance_regularisation']['count'])->toBe(1);

    $rows = rq8Rows($hr);
    $forms = $rows->firstWhere('facet', 'forms');
    expect($forms)->not->toBeNull()->and($forms->facetLabel)->toBe('Forms')
        ->and($forms->overallStatus)->toContain('Workflow running')->toContain('form submitted');
    expect($rows->first(fn (InboxEntry $e) => str_starts_with($e->facet, 'workflow:'))->facetLabel)->toBe('Joiner set-up');

    // The page: the facets with their counts, the side search over their names, and choosing one.
    $this->actingAs($this->manager->user);
    $page = Livewire::test(Inbox::class)->assertSee('Search by name')->assertSee('data-facet="leave"', false)->assertSee('data-facet="attendance_regularisation"', false);
    $page->call('setFacet', 'leave');
    expect($page->instance()->listed->pluck('id')->all())->toBe(['leave:'.$this->leave->id]);
    expect($reg->id)->toBeInt();
});

it('offers the views and quick filters: unread, overdue, due today and tomorrow, relieved employees', function () {
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $manager = $this->manager->user;
    // A regularisation is due two days after it was raised (the Approval Center's rule): due on the 23rd.
    expect(rq8Rows($manager, 'due_today')->pluck('id')->all())->toBe([])
        ->and(rq8Rows($manager, 'unread')->pluck('id')->all())->toContain('leave:'.$this->leave->id, 'regularisation:'.$reg->id);
    $this->travelTo('2026-09-22 10:00:00');
    expect(rq8Rows($manager, 'due_tomorrow')->pluck('id')->all())->toBe(['regularisation:'.$reg->id]);
    $this->travelTo('2026-09-23 08:00:00');
    expect(rq8Rows($manager, 'due_today')->pluck('id')->all())->toBe(['regularisation:'.$reg->id]);
    $this->travelTo('2026-09-24 10:00:00');
    expect(rq8Rows($manager, 'overdue')->pluck('id')->all())->toBe(['regularisation:'.$reg->id]);

    // Opening an item marks it read (presentation only; nothing changes on the request).
    app(UnifiedInbox::class)->markRead($manager, 'leave:'.$this->leave->id);
    expect(rq8Rows($manager, 'unread')->pluck('id')->all())->toBe(['regularisation:'.$reg->id])
        ->and($this->leave->fresh()->status)->toBe('pending');

    // Relieved employees: items about people who have left.
    expect(rq8Rows($manager, 'relieved'))->toBeEmpty();
    forceLifecycle($this->employee, LifecycleState::Exited);
    expect(rq8Rows($manager, 'relieved')->pluck('id')->all())->toContain('leave:'.$this->leave->id);
});

it('shows relative due labels', function () {
    $entry = fn (?string $due) => new InboxEntry('x:1', 'decision', 'x', 'X', 'T', dueAt: $due ? Carbon::parse($due) : null);
    expect($entry(null)->dueLabel())->toBeNull()
        ->and($entry('2026-09-20 18:00')->dueLabel()['label'])->toBe('Overdue')
        ->and($entry('2026-09-21 18:00')->dueLabel()['label'])->toBe('Due today')
        ->and($entry('2026-09-22 18:00')->dueLabel()['label'])->toBe('Due tomorrow')
        ->and($entry('2026-09-25 18:00')->dueLabel()['label'])->toBe('Due in 4 days')
        ->and((new InboxEntry('x:2', 'missed', 'x', 'X', 'T'))->dueLabel()['label'])->toBe('Missed to respond');
});

it('filters by employee, requested-at range, leave type, organisation unit and keyword, with the request type\'s own filters', function () {
    $other = employeeWithUser($this->manager, ['leave.apply', 'task.view']);
    forceLifecycle($other, LifecycleState::Active, ['joining_date' => '2025-01-01']);
    assignSchedule($other, WorkSchedule::query()->first());
    app(LeaveAccrual::class)->accrue($other);
    $this->travelTo('2026-09-21 15:00:00');
    $casual = app(Leaves::class)->request($other, $this->cl, '2026-09-25', '2026-09-25', 'Family function', 'full', 'full', null, $other->user);
    $inbox = app(UnifiedInbox::class);
    $rows = rq8Rows($this->manager->user);
    $ids = fn ($filtered) => $filtered->pluck('id')->sort()->values()->all();

    expect($ids($inbox->filter($rows, null, null, ['employee' => $other->employee_code])))->toBe(['leave:'.$casual->id])
        ->and($ids($inbox->filter($rows, null, null, ['employee' => strtolower((string) $this->employee->refresh()->display_name)])))->toBe(['leave:'.$this->leave->id])
        ->and($ids($inbox->filter($rows, null, null, ['leave_type' => (string) $this->cl->id])))->toBe(['leave:'.$casual->id])
        ->and($ids($inbox->filter($rows, null, null, ['from' => '2026-09-21', 'until' => '2026-09-21'])))->toBe($ids($rows))
        ->and($ids($inbox->filter($rows, null, null, ['from' => '2026-09-22'])))->toBe([])
        ->and($ids($inbox->filter($rows, null, 'kochi')))->toBe(['leave:'.$this->leave->id])
        ->and($ids($inbox->filter($rows, null, 'nothing like this')))->toBe([]);

    $options = $inbox->filterOptions($rows);
    expect($options['leave_type'])->toHaveKeys([$this->el->id, $this->cl->id])->and($options['ticket_category'])->toBe([])->and($options['travel'])->toBeFalse()
        ->and($options['unit'])->not->toBeEmpty();
    $unit = array_key_first($options['unit']);
    expect($inbox->filter($rows, null, null, ['unit' => $unit])->count())->toBe($rows->count());

    // The family filters follow the request type (HROne loads the filter set per category): none for a type without them.
    $tickets = collect([new InboxEntry('task:1', 'task', 'helpdesk', 'Helpdesk', 'Work case', meta: ['ticket_category_id' => 7, 'haystack' => 'work case'])]);
    expect($inbox->filter($tickets, null, null, ['ticket_category' => '7'])->count())->toBe(1)
        ->and($inbox->filter($tickets, null, null, ['ticket_category' => '8'])->count())->toBe(0);

    $this->actingAs($this->manager->user);
    $page = Livewire::test(Inbox::class)->assertSee('data-filter="leave_type"', false);
    $page->set('search', 'family')->assertSee('Family function')->assertDontSee('Trip to Kochi');
    $page->set('search', 'zzz-none')->assertSee('We couldn’t find anything.', false);
});

it('opens items beside the list, steps through the current filter with previous and next, and has a focus mode', function () {
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $this->actingAs($this->manager->user);
    $page = Livewire::test(Inbox::class);
    $ids = $page->instance()->listed->pluck('id')->all();
    expect($ids)->toHaveCount(2);

    $page->call('open', $ids[0], true)->assertSet('selected', $ids[0])->assertSet('focus', true)->assertSee('1 of 2');
    $page->call('next')->assertSet('selected', $ids[1])->assertSee('2 of 2');
    $page->call('next')->assertSet('selected', $ids[1]);
    $page->call('previous')->assertSet('selected', $ids[0]);
    $page->call('setFocus', false)->assertSet('focus', false);
    expect(app(ExperiencePreferences::class)->for($this->manager->user)['inbox_read'])->toContain($ids[0], $ids[1]);

    // An id that is not in the person's inbox opens nothing.
    $page->call('open', 'leave:999999')->assertSet('selected', null);
    expect($reg->status)->toBe('pending');
});

it('decides through the owning service as Approvals does, and remembers the due time for the approver\'s insight', function () {
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $this->actingAs($this->manager->user);
    $page = Livewire::test(Inbox::class)->call('open', 'leave:'.$this->leave->id);
    $page->call('decide', 'leave:'.$this->leave->id, 'approve', null)->assertNotified('Leave approved');
    expect($this->leave->fresh()->status)->toBe('approved')->and($this->leave->fresh()->reviewed_by)->toBe($this->manager->user_id);
    // The next item opens.
    expect($page->get('selected'))->toBe('regularisation:'.$reg->id);

    $page->call('decide', 'regularisation:'.$reg->id, 'reject', '')->assertHasErrors('note.regularisation:'.$reg->id);
    $page->call('decide', 'regularisation:'.$reg->id, 'approve', null);
    expect($reg->fresh()->status)->toBe('approved');

    $log = InboxDecision::query()->orderBy('id')->get();
    expect($log)->toHaveCount(2)->and($log[0]->item_id)->toBe('leave:'.$this->leave->id)->and($log[0]->due_at)->toBeNull()
        ->and($log[1]->due_at?->toDateString())->toBe('2026-09-23')->and($log[1]->user_id)->toBe($this->manager->user_id);

    // Approvals still decides exactly as before.
    $this->actingAs($this->employee->user);
    $second = app(Leaves::class)->request($this->employee, $this->el, '2026-09-28', '2026-09-28', 'Second');
    $this->actingAs($this->manager->user);
    Livewire::test(Approvals::class)->call('decide', 'leave:'.$second->id, 'approve', null)->assertNotified('Leave approved');
    expect($second->fresh()->status)->toBe('approved');
});

it('archives ticked items of the types that offer it and lists them under Archive until restored', function () {
    $reg = app(Regularisations::class)->request($this->employee, '2026-09-18', 'missed_punch', 'Forgot', '2026-09-18 09:30', '2026-09-18 18:30', $this->employee->user);
    $this->actingAs($this->manager->user);
    // Archiving off (the default): nothing to tick.
    expect(Livewire::test(Inbox::class)->instance()->archivableIds())->toBe([]);

    ($this->set)('inbox.archive.types', ['leave']);
    $page = Livewire::test(Inbox::class);
    expect($page->instance()->archivableIds())->toBe(['leave:'.$this->leave->id]);
    $page->call('archiveSelected', ['leave:'.$this->leave->id, 'regularisation:'.$reg->id])->assertNotified('1 archived');
    expect(InboxArchive::query()->where('user_id', $this->manager->user_id)->pluck('item_id')->all())->toBe(['leave:'.$this->leave->id])
        ->and($this->leave->fresh()->status)->toBe('pending')
        ->and(rq8Rows($this->manager->user)->pluck('id')->all())->toBe(['regularisation:'.$reg->id])
        ->and(rq8Rows($this->manager->user, 'archived')->pluck('id')->all())->toBe(['leave:'.$this->leave->id]);

    $this->actingAs($this->manager->user);
    Livewire::test(Inbox::class)->call('setShow', 'archived')->assertSee('data-restore-archived', false)
        ->call('restoreArchived', 'leave:'.$this->leave->id, 'leave')->assertNotified('Restored');
    expect(rq8Rows($this->manager->user)->pluck('id')->all())->toContain('leave:'.$this->leave->id);
});

// ---- Not relevant (task.ignore) ------------------------------------------------------------------------------------

it('lets holders of task.ignore close a system task as not relevant with a reason, audited, and bring it back', function () {
    $hr = tenantUser($this->tenant, ['task.view', 'task.act', 'task.ignore', 'workflow.view']);
    $todo = rq8Todo($hr, $this->employee);
    $entry = rq8Rows($hr)->firstWhere('id', 'workflow_task:'.$todo->id);
    expect($entry->system)->toBeTrue()->and(app(UnifiedInbox::class)->mayIgnore($hr, $entry))->toBeTrue();

    $this->actingAs($hr);
    $page = Livewire::test(Inbox::class)->call('open', 'workflow_task:'.$todo->id)->assertSee('data-inbox-ignore', false);
    $page->call('ignore', 'workflow_task:'.$todo->id, '  ')->assertHasErrors('ignore.workflow_task:'.$todo->id);
    expect($todo->fresh()->status)->toBe(TaskStatus::Pending);
    $page->call('ignore', 'workflow_task:'.$todo->id, 'Laptop already issued')->assertNotified('Marked not relevant');

    $task = $todo->fresh();
    expect($task->status)->toBe(TaskStatus::Skipped)->and($task->decision)->toBe('not_relevant')->and($task->note)->toBe('Laptop already issued')
        ->and($task->completed_by)->toBe($hr->id)
        // The run moved on as when the to-do is done.
        ->and($task->instance->status)->toBe(InstanceStatus::Completed)
        ->and($task->instance->contextValue('last_decision'))->toBe('completed');
    $audit = AuditEvent::query()->where('action', AuditAction::TaskNotRelevant->value)->sole();
    expect($audit->actor_id)->toBe($hr->id)->and($audit->reason)->toBe('Laptop already issued');

    // Listed under "Not relevant", with the reason; brought back, it waits in the inbox again.
    $listed = rq8Rows($hr, 'not_relevant');
    expect($listed->pluck('id')->all())->toBe(['workflow_task:'.$todo->id])->and($listed->first()->note)->toBe('Laptop already issued')
        ->and(rq8Rows($hr)->pluck('id')->all())->not->toContain('workflow_task:'.$todo->id);
    $this->actingAs($hr);
    Livewire::test(Inbox::class)->call('setShow', 'not_relevant')->call('open', 'workflow_task:'.$todo->id)->assertSee('Laptop already issued')
        ->call('restoreTask', $todo->id, 'It matters after all')->assertNotified('Back in the inbox');
    expect($todo->fresh()->status)->toBe(TaskStatus::Pending)->and($todo->fresh()->completed_by)->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::TaskRestored->value)->sole()->reason)->toBe('It matters after all')
        ->and(rq8Rows($hr)->pluck('id')->all())->toContain('workflow_task:'.$todo->id);

    // Done after all: recorded as done; the completed run does not move again.
    app(WorkflowEngine::class)->completeTask($todo->fresh(), 'completed', null, $hr);
    expect($todo->fresh()->status)->toBe(TaskStatus::Completed)->and($todo->fresh()->instance->status)->toBe(InstanceStatus::Completed);
});

it('never offers Not relevant without task.ignore, on approvals, on runs started by hand, or on someone else\'s task', function () {
    $plain = tenantUser($this->tenant, ['task.view', 'task.act']);
    $todo = rq8Todo($plain, $this->employee);
    $entry = rq8Rows($plain)->firstWhere('id', 'workflow_task:'.$todo->id);
    expect(app(UnifiedInbox::class)->mayIgnore($plain, $entry))->toBeFalse()
        ->and(fn () => app(SystemTasks::class)->ignore($plain, $todo, 'No'))->toThrow(AuthorizationException::class);

    $hr = tenantUser($this->tenant, ['task.view', 'task.act', 'task.ignore']);
    $manual = rq8Todo($hr, $this->employee, 'manual', 'Hand-started step');
    expect(SystemTasks::isSystem($manual))->toBeFalse()
        ->and(fn () => app(SystemTasks::class)->ignore($hr, $manual, 'No'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SystemTasks::class)->ignore($hr, $todo, 'Not mine'))->toThrow(AuthorizationException::class);

    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $hr->id]]]]);
    $run = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $this->employee, ['trigger' => 'employee.joined']);
    $approval = WorkflowTask::query()->where('workflow_instance_id', $run->id)->sole();
    expect(SystemTasks::isSystem($approval))->toBeFalse()
        ->and(fn () => app(WorkflowEngine::class)->markNotRelevant($approval, 'No', $hr))->toThrow(WorkflowException::class);
    expect(fn () => app(SystemTasks::class)->ignore($hr, $todo, str_repeat('x', 256)))->toThrow(InvalidArgumentException::class);

    $this->actingAs($hr);
    Livewire::test(Inbox::class)->call('open', 'workflow_task:'.$approval->id)->assertDontSee('data-inbox-ignore', false);
});

it('adds task.ignore only to the HR admin templates that already hold every task right', function () {
    $keys = fn (string $slug) => Role::query()->where('slug', $slug)->firstOrFail()->permissions()->pluck('key')->all();
    expect(config('peopleos.permissions.task'))->toHaveKey('task.ignore')
        ->and($keys('tenant-hr-admin'))->toContain('task.ignore')
        ->and($keys('hr-manager'))->toContain('task.ignore')
        ->and($keys('manager'))->not->toContain('task.ignore')
        ->and($keys('employee'))->not->toContain('task.ignore')
        ->and($keys('hr-executive'))->not->toContain('task.ignore');
});

// ---- missed, insight, more, peek, leave cancellation ---------------------------------------------------------------

it('lists the workflow steps decided without the person under "Missed by me"', function () {
    $a = tenantUser($this->tenant, ['task.view', 'task.act']);
    $b = tenantUser($this->tenant, ['task.view', 'task.act']);
    [$nodes, $edges] = linear([['id' => 'appr', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'parallel', 'approvers' => [['type' => 'user', 'user_id' => $a->id], ['type' => 'user', 'user_id' => $b->id]]]]]);
    $run = app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $this->employee);
    $theirs = WorkflowTask::query()->where('workflow_instance_id', $run->id)->where('assignee_id', $a->id)->sole();
    $mine = WorkflowTask::query()->where('workflow_instance_id', $run->id)->where('assignee_id', $b->id)->sole();
    app(WorkflowEngine::class)->completeTask($theirs, 'rejected', 'No', $a);

    $missed = rq8Rows($b, 'missed');
    expect($missed->pluck('id')->all())->toBe(['workflow_task:'.$mine->id])->and($missed->first()->dueLabel()['label'])->toBe('Missed to respond')
        ->and(rq8Rows($a, 'missed'))->toBeEmpty();
});

it('counts the approver\'s own decisions in Insight: decided, on time and delayed, others only with an HR or audit right', function () {
    $run = app(WorkflowEngine::class)->start(publishWorkflow(...linear([['id' => 'wait', 'type' => 'wait', 'name' => 'Wait', 'config' => ['hours' => 100]]])), $this->employee);
    $manager = $this->manager->user;
    $step = fn (string $due, string $done) => WorkflowTask::query()->create(['workflow_instance_id' => $run->id, 'node_id' => 'x', 'type' => 'approval', 'title' => 'Step', 'assignee_id' => $manager->id,
        'sequence' => 1, 'status' => TaskStatus::Approved, 'decision' => 'approved', 'completed_by' => $manager->id, 'completed_at' => $done, 'due_at' => $due]);
    $step('2026-09-20 18:00', '2026-09-19 10:00');
    $step('2026-09-08 18:00', '2026-09-10 10:00');
    InboxDecision::query()->create(['user_id' => $manager->id, 'item_id' => 'leave:1', 'item_type' => 'leave', 'decision' => 'approve', 'due_at' => null, 'decided_at' => '2026-09-20 10:00']);
    InboxDecision::query()->create(['user_id' => $manager->id, 'item_id' => 'regularisation:1', 'item_type' => 'regularisation', 'decision' => 'approve', 'due_at' => '2026-09-21 18:00', 'decided_at' => '2026-09-20 10:00']);
    // Long ago: outside the period.
    InboxDecision::query()->create(['user_id' => $manager->id, 'item_id' => 'leave:2', 'item_type' => 'leave', 'decision' => 'approve', 'due_at' => null, 'decided_at' => '2026-05-01 10:00']);

    $insight = app(InboxInsight::class)->for($manager, null, 30);
    expect($insight['decided'])->toBe(4)->and($insight['timed'])->toBe(3)->and($insight['on_time'])->toBe(2)->and($insight['delayed'])->toBe(1)
        ->and($insight['on_time_pct'])->toBe(67)->and($insight['delayed_pct'])->toBe(33)
        ->and(app(InboxInsight::class)->for($manager, null, 7)['decided'])->toBe(3);

    // Someone else's: only with an HR or audit right, and only for people in their scope.
    $peer = tenantUser($this->tenant, ['task.view', 'task.act']);
    expect(fn () => app(InboxInsight::class)->for($peer, $manager))->toThrow(AuthorizationException::class)
        ->and(app(InboxInsight::class)->people($peer))->toBe([$peer->id => $peer->name.' (you)']);
    $auditor = tenantUser($this->tenant, ['task.view', 'audit.view', 'employee.view']);
    $this->actingAs($auditor);
    expect(app(InboxInsight::class)->people($auditor))->toHaveKey($manager->id)
        ->and(app(InboxInsight::class)->for($auditor, $manager)['decided'])->toBe(4);

    $this->actingAs($manager);
    Livewire::test(Inbox::class)->assertSee('data-insight-decided', false)->assertSee('67%');
});

it('offers "More" (badge, reward points, one-on-one, initiative) only when the viewer may open those forms, never about themselves', function () {
    $about = new InboxEntry('leave:'.$this->leave->id, 'decision', 'leave', 'Leave', 'Leave', subjectEmployeeId: $this->employee->id);
    $this->actingAs($this->manager->user);
    expect(app(UnifiedInbox::class)->more($this->manager->user, $about))->toBe([]);

    $giver = employeeWithUser(null, ['task.view', 'employee.view', 'recognition.view', 'recognition.give', 'performance.team']);
    $this->actingAs($giver->user);
    $links = collect(app(UnifiedInbox::class)->more($giver->user, $about))->keyBy('key');
    expect($links->keys()->all())->toContain('badge', 'one_on_one')->not->toContain('points')
        ->and($links['badge']['url'])->toContain('action=giveBadge')->toContain('employee%5D='.$this->employee->id);

    ($this->set)('engage.points.enabled', true);
    expect(collect(app(UnifiedInbox::class)->more($giver->user, $about))->pluck('key')->all())->toContain('points');

    // Never about oneself, nor about someone the viewer may not find.
    $self = new InboxEntry('x:1', 'decision', 'x', 'X', 'T', subjectEmployeeId: $giver->id);
    $stranger = tenantUser($this->tenant, ['task.view', 'recognition.view', 'recognition.give']);
    $this->actingAs($giver->user);
    expect(app(UnifiedInbox::class)->more($giver->user, $self))->toBe([]);
    $this->actingAs($stranger);
    expect(app(UnifiedInbox::class)->more($stranger, $about))->toBe([]);
});

it('peeks at the employee as the directory shows them to the viewer, with the CA-6 sections, and not at all outside their visibility', function () {
    $this->actingAs($this->manager->user);
    $entry = rq8Rows($this->manager->user)->firstWhere('id', 'leave:'.$this->leave->id);
    $peek = app(UnifiedInbox::class)->peek($this->manager->user, $entry);
    expect($peek['name'])->toBe($this->employee->refresh()->display_name)->and($peek['sections'])->toBe([]);

    // A to-do about someone the assignee may not find in the directory: listed, but no peek.
    $stranger = tenantUser($this->tenant, ['task.view', 'task.act']);
    $todo = rq8Todo($stranger, $this->employee);
    $theirs = rq8Rows($stranger)->firstWhere('id', 'workflow_task:'.$todo->id);
    expect($theirs)->not->toBeNull()->and(app(UnifiedInbox::class)->peek($stranger, $theirs))->toBeNull()
        ->and(app(UnifiedInbox::class)->more($stranger, $theirs))->toBe([]);

    $this->actingAs($this->manager->user);
    Livewire::test(Inbox::class)->call('open', 'leave:'.$this->leave->id)->assertSee('data-inbox-peek', false);
});

it('shows a leave cancellation with its reason and the balance after cancelling', function () {
    $this->actingAs($this->manager->user);
    app(Leaves::class)->approve($this->leave, null, $this->manager->user);
    $this->actingAs($this->employee->user);
    app(Leaves::class)->requestCancellation($this->leave->fresh(), 'Trip postponed', $this->employee->user);

    $item = (new ApprovalCenter(app(TenantContext::class)))->pending($this->manager->user)->firstWhere('id', 'leave:'.$this->leave->id);
    expect($item->type)->toBe('leave_cancellation')->and($item->facts)->toContain('Why cancel: Trip postponed')
        ->and($item->changes()[0]['label'])->toBe('Earned leave balance after cancelling')
        ->and($item->changes()[0]['before'])->toBe('23 days')->and($item->changes()[0]['after'])->toBe('24 days');
    expect(rq8Rows($this->manager->user)->firstWhere('id', 'leave:'.$this->leave->id)->facet)->toBe('leave_cancellation');
});

it('sits in the My work rail and leaves Approvals and the Task inbox as they were', function () {
    $this->actingAs($this->manager->user);
    expect(array_column(app(ExperienceNavigation::class)->children($this->manager->user, 'work'), 'label'))->toContain('Unified inbox', 'Approvals');
    $this->get(Approvals::getUrl())->assertOk();
    $this->get(TaskInbox::getUrl())->assertOk();
    expect(LeaveRequest::query()->whereKey($this->leave->id)->value('status'))->toBe('pending');
});
