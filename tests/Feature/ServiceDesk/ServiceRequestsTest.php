<?php

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Services\Forms;
use App\Domain\Lifecycle\Models\EmployeeTimelineEntry;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketComment;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ServiceDeskTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 16:00:00'); // a Monday, 16:00 UTC
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->manager = activeEmployee(null, ['servicedesk.request', 'servicedesk.team', 'performance.team', 'task.view']);
    $this->employee = activeEmployee($this->manager, ['servicedesk.request', 'task.view', 'leave.apply']);
    $this->other = activeEmployee(null, ['servicedesk.request', 'task.view']);
    $this->agent = sdAgent();
    $this->team = sdTeam($this->agent);
    $this->sla = ServiceSlaPolicy::query()->create(['code' => 'BH', 'name' => 'Business hours', 'calendar' => 'business', 'effective_from' => '2026-01-01',
        'targets' => ['normal' => ['first_response_hours' => 2, 'resolution_hours' => 4], 'high' => ['first_response_hours' => 1, 'resolution_hours' => 2]]]);
    $calendar = HolidayCalendar::create(['name' => 'National', 'code' => 'NAT']);
    Holiday::create(['holiday_calendar_id' => $calendar->id, 'date' => '2026-09-22', 'name' => 'Regional holiday', 'type' => 'public']);
    HolidayCalendarRule::create(['holiday_calendar_id' => $calendar->id, 'name' => 'All', 'conditions' => []]);
    $this->requests = app(ServiceRequests::class);
    $this->desk = app(ServiceDesk::class);
});

it('raises a request pinned to its service version, numbered, assigned in scope, on a business-hours SLA', function () {
    $service = sdApprovedService('LEAVE_QUERY_X', ['sla_policy_id' => $this->sla->id, 'assignment' => ['role_id' => $this->team->id]], 'ATTENDANCE');
    $v1 = app(ServiceCatalogue::class)->versionOn($service);

    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, [], ['subject' => 'My leave balance looks wrong', 'description' => 'Details']);

    // Monday 16:00 → 18:00 (2 h), Tuesday is a holiday, Wednesday 09:00 → 11:00 (2 h): 4 business hours.
    expect($ticket->number)->toBe('TKT-2026-00001')
        ->and($ticket->service_definition_version_id)->toBe($v1->id)
        ->and($ticket->status)->toBe('assigned')
        ->and($ticket->assignee_id)->toBe($this->agent->id)
        ->and($ticket->assigned_role_id)->toBe($this->team->id)
        ->and($ticket->sla_mode)->toBe('business')
        ->and($ticket->first_response_due_at->toDateTimeString())->toBe('2026-09-21 18:00:00')
        ->and($ticket->due_at->toDateTimeString())->toBe('2026-09-23 11:00:00')
        ->and($ticket->correlation_id)->not->toBeNull()
        ->and($ticket->transitions()->pluck('to_status')->all())->toBe(['submitted', 'assigned']);

    $actions = AuditEvent::query()->where('module', 'servicedesk')->where('entity_id', (string) $ticket->id)->pluck('action')->map(fn ($a) => $a->value)->all();
    expect($actions)->toContain('REQUEST_CREATED', 'REQUEST_SUBMITTED', 'REQUEST_ASSIGNED')
        ->and(EmployeeTimelineEntry::query()->where('employee_id', $this->employee->id)->where('category', 'service_request')->value('title'))->toContain('TKT-2026-00001')
        ->and(EmployeeTimelineEntry::query()->where('category', 'service_request')->value('title'))->not->toContain('leave balance looks wrong');

    // The assigned agent is told the number and service, never the free-text subject.
    $title = (string) data_get($this->agent->notifications()->latest()->first()?->data, 'title');
    expect($title)->toContain('TKT-2026-00001')->not->toContain('looks wrong');

    // A later version does not rewrite the open request.
    $catalogue = app(ServiceCatalogue::class);
    $preparer = tenantUser($this->tenant, ['servicedesk.manage', 'servicedesk.catalogue_approve']);
    $v2 = $catalogue->newVersion($service, $preparer, '2026-09-21');
    $catalogue->updateDraft($v2, ['default_priority' => 'high'], $preparer);
    $catalogue->submit($v2->refresh(), $preparer);
    expect(fn () => $catalogue->approve($v2->refresh(), $preparer))->toThrow(ServiceDeskRuleViolation::class, 'prepared');
    expect(fn () => $catalogue->approve($v2->refresh(), tenantUser($this->tenant, ['servicedesk.catalogue_approve'])))->toThrow(ServiceDeskRuleViolation::class, 'start after');
    expect($ticket->refresh()->service_definition_version_id)->toBe($v1->id);
});

it('creates a request once per idempotency key, and refuses a key reused by someone else', function () {
    $service = sdApprovedService('HR_QUERY_X');
    $first = $this->requests->submit($service, $this->employee, $this->employee->user, [], ['idempotency_key' => 'abc-1']);
    $again = $this->requests->submit($service, $this->employee, $this->employee->user, [], ['idempotency_key' => 'abc-1']);
    expect($again->id)->toBe($first->id)->and(Ticket::query()->count())->toBe(1);

    $hr = sdAgent();
    expect(fn () => $this->requests->submit($service, $this->employee, $hr, [], ['idempotency_key' => 'abc-1', 'source' => 'hr']))->toThrow(ServiceDeskRuleViolation::class, 'idempotency key');
});

it('validates the form with the Configuration Form and the domain rules, and drops unknown fields', function () {
    $form = Form::create(['name' => 'Policy query', 'key' => 'policy_query']);
    $draft = app(Forms::class)->draft($form);
    $draft->update(['fields' => [['key' => 'topic', 'label' => 'Topic', 'type' => 'dropdown', 'required' => true, 'options' => ['leave', 'travel']], ['key' => 'detail', 'label' => 'Detail', 'type' => 'text']]]);
    $version = app(Forms::class)->publish($form);
    $service = sdApprovedService('POLICY_Q', ['form_version_id' => $version->id, 'field_security' => ['detail' => ['class' => 'standard', 'employee_visible' => true]]]);

    expect(fn () => $this->requests->submit($service, $this->employee, $this->employee->user, ['detail' => 'x']))->toThrow(ServiceDeskRuleViolation::class);
    expect(fn () => $this->requests->submit($service, $this->employee, $this->employee->user, ['topic' => 'payroll']))->toThrow(ServiceDeskRuleViolation::class);

    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, ['topic' => 'leave', 'detail' => 'Carry forward', 'role' => 'admin', 'is_admin' => true]);
    expect($ticket->form_data)->toBe(['topic' => 'leave', 'detail' => 'Carry forward'])
        // Encrypted at rest: the stored column never holds the plain values.
        ->and((string) DB::table('tickets')->where('id', $ticket->id)->value('form_data'))->not->toContain('Carry forward');
});

it('lets employees raise for themselves only, managers for people they manage, and HR within scope', function () {
    $service = sdApprovedService('LETTER_Q', ['availability' => ['employee' => true, 'manager' => true, 'hr' => true]]);
    expect(fn () => $this->requests->submit($service, $this->other, $this->employee->user, [], ['source' => 'web']))->toThrow(ServiceDeskRuleViolation::class, 'own record');
    expect(fn () => $this->requests->submit($service, $this->other, $this->manager->user, [], ['source' => 'manager']))->toThrow(ServiceDeskRuleViolation::class, 'people you manage');
    expect($this->requests->submit($service, $this->employee, $this->manager->user, [], ['source' => 'manager'])->source)->toBe('manager');
    expect($this->requests->submit($service, $this->other, $this->agent, [], ['source' => 'hr'])->raised_by)->toBe($this->agent->id);

    $employeesOnly = sdApprovedService('SELF_ONLY', ['availability' => ['employee' => true, 'manager' => false, 'hr' => false]]);
    expect(fn () => $this->requests->submit($employeesOnly, $this->other, $this->agent, [], ['source' => 'hr']))->toThrow(ServiceDeskRuleViolation::class, 'on an employee');

    // Eligibility: lifecycle states.
    $probationOnly = sdApprovedService('PROBATION_ONLY', ['lifecycle_states' => ['probation']]);
    expect(fn () => $this->requests->submit($probationOnly, $this->employee, $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'not available for this employee');
});

it('moves only along the lifecycle map, with reasons, optimistic locking and SLA pause', function () {
    $service = sdApprovedService('LIFECYCLE_X', ['sla_policy_id' => $this->sla->id, 'assignment' => ['role_id' => $this->team->id]]);
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user);
    $stale = $ticket->lock_version;

    $this->desk->acknowledge($ticket, $this->agent);
    expect(fn () => $this->desk->start($ticket->refresh(), $this->agent, $stale))->toThrow(ServiceDeskRuleViolation::class, 'changed since');
    expect(fn () => $this->desk->close($ticket->refresh(), $this->agent))->toThrow(ServiceDeskRuleViolation::class, 'cannot move');

    $due = $ticket->refresh()->due_at;
    $this->desk->waitOnEmployee($ticket, $this->agent);
    expect($ticket->refresh()->sla_paused_at)->not->toBeNull();
    $this->travelTo('2026-09-23 10:00:00'); // Wednesday 10:00: 1 business hour paused (Monday 16:00–18:00 is 2 h)
    $this->desk->comment($ticket, $this->employee->user, 'Here are the dates');
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress')->and($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->sla_paused_minutes)->toBe(180)
        ->and($ticket->due_at->greaterThan($due))->toBeTrue();

    expect(fn () => $this->desk->cancel($ticket, $this->employee->user, ''))->toThrow(ServiceDeskRuleViolation::class, 'reason');
    expect(fn () => $this->desk->resolve($ticket, '', $this->agent))->toThrow(ServiceDeskRuleViolation::class, 'resolution');
    $this->desk->resolve($ticket, 'Corrected the balance', $this->agent);
    expect(fn () => $this->desk->reopen($ticket->refresh(), '', $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'reason');
    $this->desk->reopen($ticket->refresh(), 'Still wrong', $this->employee->user);
    expect($ticket->refresh()->status)->toBe('in_progress')->and($ticket->resolved_at)->toBeNull();

    // HR never works their own case.
    $hrEmployee = activeEmployee(null, ['servicedesk.request', 'servicedesk.view', 'servicedesk.agent']);
    $own = $this->requests->submit($service, $hrEmployee, $hrEmployee->user);
    expect(app(CaseAccess::class)->canWork($hrEmployee->user, $own))->toBeFalse()
        ->and(fn () => $this->desk->resolve($own, 'done', $hrEmployee->user))->toThrow(ServiceDeskRuleViolation::class, 'cannot work');
});

it('keeps drafts private to whoever raised them until submitted', function () {
    $service = sdApprovedService('DRAFTABLE');
    $draft = $this->requests->submit($service, $this->employee, $this->employee->user, [], ['draft' => true]);
    expect($draft->status)->toBe('draft')->and($draft->due_at)->toBeNull()
        ->and(app(CaseAccess::class)->canView($this->agent, $draft))->toBeFalse()
        ->and(app(CaseAccess::class)->visible(Ticket::query(), $this->agent)->count())->toBe(0);

    expect(fn () => $this->requests->submitDraft($draft, $this->agent))->toThrow(ServiceDeskRuleViolation::class);
    $submitted = $this->requests->submitDraft($draft, $this->employee->user);
    expect($submitted->status)->toBe('submitted')->and($submitted->due_at)->not->toBeNull()
        ->and(app(CaseAccess::class)->canView($this->agent, $submitted->refresh()))->toBeTrue();
});

it('stores attachments privately with a fingerprint and applies the service attachment rule', function () {
    $required = sdApprovedService('NEEDS_FILE', ['attachment_rule' => 'required']);
    expect(fn () => $this->requests->submit($required, $this->employee, $this->employee->user))->toThrow(ServiceDeskRuleViolation::class, 'attachment');
    expect(fn () => $this->requests->submit($required, $this->employee, $this->employee->user, [], ['attachment' => UploadedFile::fake()->create('evil.php', 1)]))->toThrow(ServiceDeskRuleViolation::class, 'file type');

    $ticket = $this->requests->submit($required, $this->employee, $this->employee->user, [], ['attachment' => UploadedFile::fake()->create('proof.pdf', 12, 'application/pdf')]);
    $comment = TicketComment::query()->where('ticket_id', $ticket->id)->firstOrFail();
    expect($comment->attachment_path)->toStartWith("tenants/{$this->tenant->id}/servicedesk/{$ticket->id}/")
        ->and($comment->attachment_sha256)->toHaveLength(64)
        ->and(Storage::disk('local')->exists($comment->attachment_path))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'ATTACHMENT_UPLOADED')->where('entity_id', (string) $ticket->id)->exists())->toBeTrue();

    $none = sdApprovedService('NO_FILES', ['attachment_rule' => 'none']);
    expect(fn () => $this->requests->submit($none, $this->employee, $this->employee->user, [], ['attachment' => UploadedFile::fake()->create('a.pdf', 1)]))->toThrow(ServiceDeskRuleViolation::class, 'does not take');
});

it('separates employee-visible, internal and restricted communication', function () {
    $service = sdApprovedService('COMMS_X', ['assignment' => ['role_id' => $this->team->id]]);
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user);
    $this->desk->comment($ticket, $this->agent, 'We are checking', 'employee');
    $this->desk->comment($ticket, $this->agent, 'Payroll says the ledger is off', 'internal');
    expect(fn () => $this->desk->comment($ticket, $this->employee->user, 'sneaky', 'internal'))->toThrow(ServiceDeskRuleViolation::class)
        ->and(fn () => $this->desk->comment($ticket, $this->agent, 'x', 'restricted'))->toThrow(ServiceDeskRuleViolation::class);

    $access = app(CaseAccess::class);
    expect($access->commentVisibilities($this->employee->user, $ticket))->toBe(['employee'])
        ->and($access->commentVisibilities($this->agent, $ticket))->toBe(['employee', 'internal'])
        ->and($access->commentVisibilities($this->manager->user, $ticket))->toBe([])
        ->and(AuditEvent::query()->where('action', 'COMMENT_CREATED')->where('entity_id', (string) $ticket->id)->count())->toBe(2);

    $first = TicketComment::query()->where('ticket_id', $ticket->id)->first();
    expect(fn () => $first->update(['body' => 'edited']))->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $first->delete())->toThrow(RuntimeException::class, 'append-only');
});
