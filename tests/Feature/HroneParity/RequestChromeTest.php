<?php

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\RequestApprovers;
use App\Domain\Experience\Services\RequestFollowUps;
use App\Domain\Experience\Services\RequestPreview;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Identity\Models\Role;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\TaskComment;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowAction;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Support\RequestWorkflows;
use App\Filament\Pages\Approvals;
use App\Filament\Pages\MyRequests;
use App\Filament\Pages\RequestTracker;
use App\Filament\Support\LeaveActions;
use App\Filament\Support\RequestChrome;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity RQ-2 (matrix 20 §20.1.1 #1–3, #5, #6, #8, #9, #12; §20.2.1 #20, #26, #30, #40 and the activity-log rows): the
| request chrome — header, the read-only "Who approves this" preview, "Read the policy", the confirmation follow-ups, the
| requester's timeline, "Send reminder", "Notify others" and the discussion from the timeline. Nothing here changes who
| approves anything: requests are raised and decided through the modules as before.
*/

beforeEach(function () {
    $this->travelTo('2026-09-25 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    Filament::getPanel('admin')->boot();
    Company::factory()->create();
    $this->mira = rq2Employee('Mira', config('peopleos.roles.manager.permissions'));
    $this->asha = rq2Employee('Asha', config('peopleos.roles.employee.permissions'), $this->mira);
    $this->manager = $this->mira->user;
    $this->user = $this->asha->user;
    $this->actingAs($this->user);
    $this->leaveType = rq2LeaveType();
});

/** An employee with a login holding exactly these permissions (and optionally a manager). */
function rq2Employee(string $first, array $permissions, ?Employee $manager = null): Employee
{
    $user = tenantUser(app(TenantContext::class)->current(), $permissions, ['name' => $first.' Test']);

    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], ['joining_date' => '2025-01-01', 'user_id' => $user->id],
        ['company_id' => Company::query()->orderBy('id')->value('id')], $manager?->id)->load('user');
}

function rq2LeaveType(): LeaveType
{
    return LeaveType::query()->where('category', '!=', 'unpaid')->orderBy('id')->firstOrFail();
}

/** A leave row raised by its employee (the chrome only reads requests; the leave module's own tests cover raising them). */
function rq2Leave(Employee $employee, string $status = 'pending', array $extra = []): LeaveRequest
{
    return LeaveRequest::create($extra + ['employee_id' => $employee->id, 'leave_type_id' => rq2LeaveType()->id, 'from_date' => '2026-09-28', 'to_date' => '2026-09-28',
        'days' => 1, 'reason' => 'Family function', 'status' => $status, 'requested_by' => $employee->user_id]);
}

/** A published leave workflow: one approval step per approver spec. */
function rq2LeaveWorkflow(array $approvers, array $attributes = []): Workflow
{
    $steps = [];
    foreach (array_values($approvers) as $i => $spec) {
        $steps[] = ['id' => 'level'.($i + 1), 'type' => 'approval', 'name' => 'Level '.($i + 1), 'config' => ['mode' => 'single', 'approver' => $spec, 'title' => 'Approve leave (level '.($i + 1).')']];
    }
    [$nodes, $edges] = linear($steps);

    return publishWorkflow($nodes, $edges, ['trigger_event' => 'leave.requested', 'name' => $attributes['name'] ?? 'Leave approval'] + $attributes);
}

function rq2Type(string $key)
{
    return app(RequestRegistry::class)->find($key);
}

function rq2Preview(string $key, ?Employee $employee = null): array
{
    return app(RequestPreview::class)->for(rq2Type($key), auth()->user(), $employee ?? test()->asha);
}

// ---- defaults and wiring -------------------------------------------------------------------------------------------

it('keeps today\'s behaviour by default and declares its settings, events and audience', function () {
    $settings = config('peopleos.settings');
    expect($settings['requests.reminder.min_hours'])->toBe(24)
        ->and($settings['requests.notify_others.types'])->toBe([])
        ->and(config('peopleos.notifications.events'))->toContain('request.reminder', 'request.intimated')
        ->and(config('peopleos.notifications.audience_types'))->toHaveKey('named');
    $keys = collect(config('peopleos_setup.modules.automation.settings'))->pluck('key')->all();
    expect($keys)->toContain('requests.reminder.min_hours', 'requests.notify_others.types')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'request_types']))->toHaveKey('leave')
        ->and(app(SetupCatalogue::class)->optionsFor(['type' => 'multiselect', 'options_from' => 'request_types'])['leave'])->toBe('Leave · Leave');
    // Every type knows its record; time-office kinds share one model and are told apart by their kind.
    $registry = app(RequestRegistry::class);
    expect($registry->forRecord(rq2Leave($this->asha))?->key())->toBe('leave')
        ->and($registry->forRecord(new TimeOfficeRequest(['type' => 'on_duty']))?->key())->toBe('time_office.on_duty')
        ->and($registry->forRecord(new TimeOfficeRequest(['type' => 'comp_off']))?->key())->toBe('time_office.comp_off');
    // "Notify others" is off for every type until listed.
    expect(app(RequestFollowUps::class)->notifyOthersOffered(rq2Type('leave')))->toBeFalse();
});

// ---- "Who approves this" (a dry run) -------------------------------------------------------------------------------

it('previews the module\'s own approvers when no workflow decides the request, naming the manager only when they approve it', function () {
    $preview = rq2Preview('leave');
    expect($preview['routes'])->toBe([])
        ->and($preview['built_in'])->toContain('Leave approvers in the Approval Center')
        ->and($preview['manager'])->toBe('Mira Test');

    $plain = rq2Employee('Neel', ['employee.self']);
    $ravi = rq2Employee('Ravi', config('peopleos.roles.employee.permissions'), $plain);
    expect(rq2Preview('leave', $ravi)['manager'])->toBeNull();
});

it('previews the workflow that would start and its approvers without starting anything', function () {
    $hrRole = Role::query()->create(['name' => 'Leave desk', 'slug' => 'leave-desk-'.uniqid()]);
    rq2LeaveWorkflow([['type' => 'manager'], ['type' => 'role', 'role_id' => $hrRole->id]], ['name' => 'Two-level leave']);
    $before = [WorkflowInstance::count(), WorkflowTask::count(), WorkflowAction::count()];

    $preview = rq2Preview('leave');

    expect($preview['definite'])->toBeTrue()
        ->and($preview['built_in'])->toBeNull()
        ->and($preview['routes'])->toHaveCount(1)
        ->and($preview['routes'][0]['workflow'])->toBe('Two-level leave')
        ->and($preview['routes'][0]['conditional'])->toBeFalse()
        ->and(array_column($preview['routes'][0]['steps'], 'approvers'))->toBe([['Mira Test'], ['Role: Leave desk']])
        ->and([WorkflowInstance::count(), WorkflowTask::count(), WorkflowAction::count()])->toBe($before);
    // The same route rendered in the form (§20.1.18 Leave #5: the employee is told the leave goes to a workflow).
    expect(RequestChrome::renderRoute('leave', $this->asha)->toHtml())->toContain('Two-level leave')->toContain('Level 1:')->toContain('Mira Test')->toContain('Role: Leave desk');
});

it('lists a workflow whose start conditions depend on the request as one that may apply, and leaves out one that cannot', function () {
    rq2LeaveWorkflow([['type' => 'manager']], ['name' => 'Long leave', 'start_conditions' => [['field' => 'days', 'operator' => 'greater_than', 'value' => 3]], 'priority' => 1]);
    rq2LeaveWorkflow([['type' => 'manager']], ['name' => 'Other department', 'start_conditions' => [['field' => 'department_id', 'operator' => 'equals', 'value' => 999999]], 'priority' => 2]);

    $preview = rq2Preview('leave');
    expect(array_column($preview['routes'], 'workflow'))->toBe(['Long leave'])
        ->and($preview['routes'][0]['conditional'])->toBeTrue()
        ->and($preview['definite'])->toBeFalse()
        ->and($preview['built_in'])->toContain('Leave approvers');

    // First match only: tried by priority, the first that surely applies ends the search.
    rq2LeaveWorkflow([['type' => 'manager']], ['name' => 'Everyone', 'priority' => 5]);
    rq2LeaveWorkflow([['type' => 'manager']], ['name' => 'Later', 'priority' => 9]);
    app(SettingsRepository::class)->set('workflow.first_match_only', true, 'RQ-2 test');
    expect(array_column(rq2Preview('leave')['routes'], 'workflow'))->toBe(['Long leave', 'Everyone']);
    // Off (the default): every matching workflow starts.
    app(SettingsRepository::class)->set('workflow.first_match_only', false, 'RQ-2 test');
    expect(array_column(rq2Preview('leave')['routes'], 'workflow'))->toBe(['Long leave', 'Everyone', 'Later']);
});

it('follows P5: a workflow on the regularisation trigger that runs on any record decides nothing, so it is not previewed', function () {
    $hr = tenantUser($this->tenant, ['attendance.approve'], ['name' => 'Hari Test']);
    [$nodes, $edges] = linear([['id' => 'a', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $hr->id]]]]);
    publishWorkflow($nodes, $edges, ['trigger_event' => 'attendance.regularisation_requested', 'subject_type' => null, 'name' => 'Any record']);
    expect(rq2Preview('attendance_regularisation')['routes'])->toBe([]);

    publishWorkflow($nodes, $edges, ['trigger_event' => 'attendance.regularisation_requested', 'subject_type' => AttendanceRegularisation::class, 'name' => 'Runs on regularisations']);
    $routes = rq2Preview('attendance_regularisation')['routes'];
    expect(array_column($routes, 'workflow'))->toBe(['Runs on regularisations'])
        ->and($routes[0]['steps'][0]['approvers'])->toBe(['Hari Test'])
        ->and(RequestWorkflows::isStrict(AttendanceRegularisation::class))->toBeTrue()
        ->and(RequestWorkflows::isStrict(LeaveRequest::class))->toBeFalse();
});

it('says who is informed from the notification rules as kinds of people, never names', function () {
    $template = NotificationTemplate::create(['key' => 'leave_fyi', 'name' => 'Leave FYI', 'subject' => 'Leave', 'body' => 'Leave raised', 'status' => 'active']);
    $role = Role::query()->create(['name' => 'Payroll desk', 'slug' => 'payroll-desk-'.uniqid()]);
    $named = tenantUser($this->tenant, [], ['name' => 'Secret Person']);
    NotificationRule::create(['name' => 'Tell them', 'event' => 'leave.requested', 'audience' => [['type' => 'manager'], ['type' => 'role', 'role_id' => $role->id], ['type' => 'user', 'user_id' => $named->id]],
        'channels' => ['in_app'], 'notification_template_id' => $template->id, 'conditions' => [], 'status' => 'active']);
    NotificationRule::create(['name' => 'Off', 'event' => 'leave.requested', 'audience' => [['type' => 'subject']], 'channels' => ['in_app'], 'notification_template_id' => $template->id, 'conditions' => [], 'status' => 'inactive']);

    $informed = rq2Preview('leave')['informed'];
    expect($informed)->toBe(['Your manager', 'Everyone with the role Payroll desk', 'A person chosen by HR'])
        ->and(implode(' ', $informed))->not->toContain('Secret Person');
});

it('links the policy articles of the request type the person may read', function () {
    $leavePolicy = Article::create(['title' => 'Leave policy 2026', 'slug' => 'leave-2026', 'category' => 'leave', 'body' => 'Text', 'status' => 'published', 'version' => 1, 'published_version' => 1]);
    Article::create(['title' => 'Travel policy', 'slug' => 'travel', 'category' => 'travel', 'body' => 'Text', 'status' => 'published', 'version' => 1, 'published_version' => 1]);
    Article::create(['title' => 'Draft leave rules', 'slug' => 'draft-leave', 'category' => 'leave', 'body' => 'Text', 'status' => 'draft', 'version' => 1]);

    $policies = rq2Preview('leave')['policies'];
    expect(array_column($policies, 'title'))->toBe(['Leave policy 2026'])
        ->and($policies[0]['url'])->toContain((string) $leavePolicy->id)
        ->and(rq2Preview('travel')['policies'])->toHaveCount(1)
        ->and(rq2Preview('loan')['policies'])->toBe([]);

    $noKb = rq2Employee('Kiran', array_values(array_diff(config('peopleos.roles.employee.permissions'), ['kb.view'])));
    $this->actingAs($noKb->user);
    expect(app(RequestPreview::class)->policies(rq2Type('leave'), $noKb->user))->toBe([]);
});

// ---- header and forms -----------------------------------------------------------------------------------------------

it('shows whose request it is in the form, and that someone else is raising it on an on-behalf form', function () {
    $own = RequestChrome::renderHeader($this->asha)->toHtml();
    expect($own)->toContain('Asha Test')->toContain($this->asha->employee_code)->not->toContain('You are raising this request for');

    $this->actingAs($this->manager);
    expect(RequestChrome::renderHeader($this->asha)->toHtml())->toContain('You are raising this request for Asha Test')
        ->and(RequestChrome::renderHeader(new Employee)->toHtml())->toBe('');
});

it('puts the header and "Who approves this" into the module request forms; the forms submit as before', function () {
    $form = LeaveActions::applyForm(fn () => $this->asha);
    expect(collect($form)->first(fn ($c) => $c instanceof Section && $c->getHeading() === 'Who approves this'))->not->toBeNull()
        ->and(collect($form)->first())->toBeInstanceOf(Text::class)
        ->and(RequestChrome::renderRoute('leave', $this->asha)->toHtml())->toContain('Leave approvers in the Approval Center');
    // The time-office form names the kind chosen.
    expect(RequestChrome::renderRoute('time_office.on_duty', $this->asha)->toHtml())->toContain('Time-office approvers')
        ->and(RequestChrome::renderRoute(null, $this->asha)->toHtml())->toBe('');
});

// ---- the requester's timeline ----------------------------------------------------------------------------------------

it('shows the requester the trail of their own request without the approver\'s note', function () {
    $leave = rq2Leave($this->asha);
    $this->actingAs($this->manager);
    $leave->update(['status' => 'approved', 'reviewed_by' => $this->manager->id, 'reviewed_at' => now(), 'review_note' => 'Private approver note']);
    app(AuditRecorder::class)->record(AuditAction::LeaveApproved, 'leave', $leave, reason: 'Private approver note', actor: $this->manager);
    $this->actingAs($this->user);

    $trail = app(RequestTimeline::class)->forOwn($this->user, rq2Type('leave'), $leave->fresh());
    $kinds = array_column($trail['entries'], 'kind');
    expect($kinds)->toBe(['submitted', 'approved'])
        ->and($trail['entries'][1]['by'])->toBe('Mira Test')
        ->and($trail['pending'])->toBeFalse()
        ->and(collect($trail['entries'])->pluck('note')->filter()->all())->toBe([]);

    $this->get(RequestTracker::getUrl(['request' => 'leave:'.$leave->id]))->assertOk()
        ->assertSee('Approved')->assertSee('Mira Test')->assertDontSee('Private approver note')->assertDontSee('Send reminder');
});

it('never shows anyone else\'s request: not to colleagues, not to the manager as a requester page', function () {
    $ravi = rq2Employee('Ravi', config('peopleos.roles.employee.permissions'), $this->mira);
    $theirs = rq2Leave($ravi);
    $mine = rq2Leave($this->asha);
    $timeline = app(RequestTimeline::class);

    expect($timeline->resolveOwn($this->user, 'leave:'.$theirs->id))->toBeNull()
        ->and($timeline->forOwn($this->user, rq2Type('leave'), $theirs))->toBeNull()
        ->and($timeline->resolveOwn($this->user, 'leave:'.$mine->id))->not->toBeNull()
        ->and($timeline->resolveOwn($this->user, 'nonsense'))->toBeNull()
        ->and($timeline->resolveOwn($this->user, 'loan:'.$mine->id))->toBeNull();
    $this->get(RequestTracker::getUrl(['request' => 'leave:'.$theirs->id]))->assertNotFound();

    $this->actingAs($this->manager);
    $this->get(RequestTracker::getUrl(['request' => 'leave:'.$mine->id]))->assertNotFound();
});

it('follows a workflow deciding the request: started, whom it waits with and by when, each level\'s decision without its note', function () {
    $desk = tenantUser($this->tenant, ['task.view', 'task.act'], ['name' => 'Dev Desk']);
    $workflow = rq2LeaveWorkflow([['type' => 'manager'], ['type' => 'user', 'user_id' => $desk->id]], ['name' => 'Two-level leave']);
    $leave = rq2Leave($this->asha);
    app(WorkflowEngine::class)->start($workflow, $leave, ['trigger' => 'leave.requested', 'employee_id' => $this->asha->id], $this->user);

    $trail = app(RequestTimeline::class)->forOwn($this->user, rq2Type('leave'), $leave);
    expect(array_column($trail['entries'], 'title'))->toContain('Approval workflow started: Two-level leave', 'Waiting with Mira Test · Approve leave (level 1)')
        ->and($trail['waiting'])->toBe(['Mira Test'])
        ->and($trail['via'])->toBe('workflow')
        ->and($trail['due']?->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString());

    $task = WorkflowTask::query()->where('status', TaskStatus::Pending->value)->firstOrFail();
    app(WorkflowEngine::class)->completeTask($task, 'approved', 'Note not for the requester', $this->manager);
    app()->forgetInstance(RequestApprovers::class); // a new request: whom it waits with is read again

    $trail = app(RequestTimeline::class)->forOwn($this->user, rq2Type('leave'), $leave->fresh());
    $approved = collect($trail['entries'])->firstWhere('kind', 'approved');
    expect($approved['title'])->toBe('Approved · Approve leave (level 1)')
        ->and($approved['by'])->toBe('Mira Test')
        ->and($trail['waiting'])->toBe(['Dev Desk'])
        ->and(collect($trail['entries'])->pluck('note')->filter()->all())->toBe([]);
    $this->get(RequestTracker::getUrl(['request' => 'leave:'.$leave->id]))->assertOk()->assertSee('Dev Desk')->assertDontSee('Note not for the requester');
});

// ---- Send reminder ---------------------------------------------------------------------------------------------------

it('reminds the people the request waits with, at most once per period, audited, only on the requester\'s own pending request', function () {
    $leave = rq2Leave($this->asha);
    $followUps = app(RequestFollowUps::class);

    expect($followUps->remind($this->user, rq2Type('leave'), $leave))->toBe(1);
    $delivery = NotificationDelivery::query()->where('event', 'request.reminder')->sole();
    expect($delivery->user_id)->toBe($this->manager->id)
        ->and($delivery->subject)->toContain('Asha Test sent a reminder about Leave')
        ->and($delivery->body)->not->toContain('Family function');
    $audit = AuditEvent::query()->where('action', AuditAction::RequestReminderSent->value)->sole();
    expect($audit->entity_type)->toBe(LeaveRequest::class)->and($audit->entity_id)->toBe((string) $leave->id)
        ->and($audit->actor_id)->toBe($this->user->id)->and($audit->metadata['recipients'])->toBe([$this->manager->id]);

    // Once per period (default 24 hours), then again.
    expect(fn () => $followUps->remind($this->user, rq2Type('leave'), $leave))->toThrow(RuntimeException::class, 'You reminded the approvers recently');
    $this->travel(25)->hours();
    expect($followUps->remind($this->user, rq2Type('leave'), $leave))->toBe(1);
    app(SettingsRepository::class)->set('requests.reminder.min_hours', 48, 'RQ-2 test');
    $this->travel(25)->hours();
    expect($followUps->reminderRefusal($this->user, rq2Type('leave'), $leave))->toContain('recently');

    // The timeline records it; a decided request and someone else's request are refused.
    expect(array_column(app(RequestTimeline::class)->forOwn($this->user, rq2Type('leave'), $leave)['entries'], 'kind'))->toContain('reminded');
    $approved = rq2Leave($this->asha, 'approved');
    expect($followUps->reminderRefusal($this->user, rq2Type('leave'), $approved))->toBe('Reminders are for requests still waiting for a decision.');
    $this->actingAs($this->manager);
    expect($followUps->reminderRefusal($this->manager, rq2Type('leave'), $leave))->toBe('You can only send reminders about your own requests.');
});

it('reminds the current assignee of the workflow deciding the request, through a notification rule when one exists', function () {
    $desk = tenantUser($this->tenant, ['task.view', 'task.act'], ['name' => 'Dev Desk']);
    $workflow = rq2LeaveWorkflow([['type' => 'user', 'user_id' => $desk->id]]);
    $leave = rq2Leave($this->asha);
    app(WorkflowEngine::class)->start($workflow, $leave, ['trigger' => 'leave.requested', 'employee_id' => $this->asha->id], $this->user);
    $template = NotificationTemplate::create(['key' => 'nudge', 'name' => 'Nudge', 'subject' => 'Nudge from {{ request.by }}', 'body' => 'About {{ request.title }}', 'status' => 'active']);
    NotificationRule::create(['name' => 'Reminders', 'event' => 'request.reminder', 'audience' => [['type' => 'named']], 'channels' => ['in_app'],
        'notification_template_id' => $template->id, 'conditions' => [], 'status' => 'active']);

    expect(app(RequestFollowUps::class)->remind($this->user, rq2Type('leave'), $leave))->toBe(1);
    $deliveries = NotificationDelivery::query()->where('event', 'request.reminder')->get();
    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->user_id)->toBe($desk->id)
        ->and($deliveries->first()->subject)->toBe('Nudge from Asha Test');
});

it('offers "Send reminder" on the tracker and sends it from there', function () {
    $leave = rq2Leave($this->asha);
    Livewire::withQueryParams(['request' => 'leave:'.$leave->id])->test(RequestTracker::class)
        ->assertActionVisible('remind')->assertActionEnabled('remind')->assertActionHidden('notifyOthers')
        ->callAction('remind')->assertNotified('Reminder sent');
    expect(NotificationDelivery::query()->where('event', 'request.reminder')->where('user_id', $this->manager->id)->exists())->toBeTrue();
    Livewire::withQueryParams(['request' => 'leave:'.$leave->id])->test(RequestTracker::class)->assertActionDisabled('remind');
});

// ---- Notify others ---------------------------------------------------------------------------------------------------

it('keeps "Notify others" off by default; switched on for a type, informs colleagues the requester can find, audited without the text', function () {
    $leave = rq2Leave($this->asha);
    $followUps = app(RequestFollowUps::class);
    expect($followUps->notifyRefusal($this->user, rq2Type('leave'), $leave))->toBe('Informing colleagues is not switched on for this kind of request.');

    app(SettingsRepository::class)->set('requests.notify_others.types', ['leave'], 'RQ-2 test');
    $stranger = rq2Employee('Zoe', config('peopleos.roles.employee.permissions'));
    expect($followUps->notifyRefusal($this->user, rq2Type('leave'), $leave))->toBeNull()
        ->and(array_keys($followUps->recipientOptions($this->user)))->toBe([$this->mira->id]);

    expect($followUps->notifyOthers($this->user, rq2Type('leave'), $leave, [$this->mira->id], 'Away on Monday, Ravi covers.'))->toBe(1);
    $delivery = NotificationDelivery::query()->where('event', 'request.intimated')->sole();
    expect($delivery->user_id)->toBe($this->manager->id)
        ->and($delivery->subject)->toContain('Asha Test wants you to know about Leave')
        ->and($delivery->body)->toBe('Away on Monday, Ravi covers.');
    $audit = AuditEvent::query()->where('action', AuditAction::RequestIntimated->value)->sole();
    expect($audit->metadata['recipients'])->toBe([$this->manager->id])->and($audit->reason)->toBeNull()
        ->and(json_encode($audit->metadata))->not->toContain('Ravi covers');

    expect(fn () => $followUps->notifyOthers($this->user, rq2Type('leave'), $leave, [$stranger->id], 'Hi'))->toThrow(RuntimeException::class, 'You can only inform colleagues you can find in search.')
        ->and(fn () => $followUps->notifyOthers($this->user, rq2Type('leave'), $leave, [$this->mira->id], str_repeat('x', 501)))->toThrow(RuntimeException::class, 'at most 500 characters')
        ->and(fn () => $followUps->notifyOthers($this->user, rq2Type('leave'), $leave, [], 'Hi'))->toThrow(RuntimeException::class, 'Choose at least one colleague.')
        ->and(fn () => $followUps->notifyOthers($this->user, rq2Type('leave'), rq2Leave($this->asha, 'rejected'), [$this->mira->id]))->toThrow(RuntimeException::class, 'Only requests waiting');
    expect(fn () => $followUps->notifyOthers($this->user, rq2Type('loan'), $leave, [$this->mira->id]))->toThrow(RuntimeException::class, 'not switched on');
});

// ---- confirmation ----------------------------------------------------------------------------------------------------

it('adds the follow-ups to the success message of one\'s own request only', function () {
    $leave = rq2Leave($this->asha);
    RequestChrome::confirmed('leave', $leave, 'Leave requested: 1.0 day(s)');
    $sent = collect(session('filament.notifications'))->last();
    expect($sent['title'])->toBe('Leave requested: 1.0 day(s)')
        ->and(collect($sent['actions'])->pluck('name')->all())->toBe(['followRequest', 'raiseAnother', 'myRequests']);

    app(SettingsRepository::class)->set('requests.notify_others.types', ['leave'], 'RQ-2 test');
    expect(collect(RequestChrome::followUps('leave', $leave))->map(fn ($a) => $a->getName())->all())->toBe(['followRequest', 'notifyOthers', 'raiseAnother', 'myRequests']);

    // HR raising it for someone: the plain message, as before.
    $this->actingAs($this->manager);
    expect(RequestChrome::followUps('leave', $leave))->toBe([]);
});

// ---- discussion, approver's card, My requests -------------------------------------------------------------------------

it('lets the requester discuss the request from its tracker while discussion is switched on for its kind', function () {
    $leave = rq2Leave($this->asha);
    Livewire::withQueryParams(['request' => 'leave:'.$leave->id])->test(RequestTracker::class)->assertDontSee('pos-request-discussion');

    app(SettingsRepository::class)->set('inbox.discussion.types', ['leave'], 'RQ-2 test');
    Livewire::withQueryParams(['request' => 'leave:'.$leave->id])->test(RequestTracker::class)->assertSee('pos-request-discussion')
        ->set('body', 'Any news?')->call('post')->assertNotified('Message posted');
    $comment = TaskComment::query()->sole();
    expect($comment->commentable_id)->toBe($leave->id)->and($comment->kind)->toBe('leave')->and($comment->user_id)->toBe($this->user->id);
});

it('shows the approver the request trail, whom it waits with and its policy on demand in the approval card', function () {
    Article::create(['title' => 'Leave policy 2026', 'slug' => 'leave-2026', 'category' => 'leave', 'body' => 'Text', 'status' => 'published', 'version' => 1, 'published_version' => 1]);
    $leave = rq2Leave($this->asha);
    $this->actingAs($this->manager);

    $page = Livewire::test(Approvals::class)->assertSee('Workflow and policy')->assertDontSee('data-request-timeline');
    $page->call('showTrail', 'leave:'.$leave->id)->assertSee('data-request-timeline', false)->assertSee('Waiting with:')->assertSee('Mira Test')->assertSee('Leave policy 2026');
});

it('links each of one\'s own requests in My requests to its tracker', function () {
    $leave = rq2Leave($this->asha);
    $this->get(MyRequests::getUrl())->assertOk()->assertSee(RequestTracker::getUrl(['request' => 'leave:'.$leave->id]), false)->assertSee('Follow');
});
