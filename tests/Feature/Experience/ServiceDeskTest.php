<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Services\Grievances;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\Workflow\Models\WorkflowInstance;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->agent = tenantUser($this->tenant, ['servicedesk.view', 'servicedesk.agent', 'employee.view']);
    $this->actingAs($this->hr);
    $this->employee = activeEmployee(null, ['servicedesk.request', 'grievance.raise', 'task.view']);
    $this->desk = app(ServiceDesk::class);
});

it('runs a ticket through open, reply, wait, resolve, rate, close, reopen with SLA and escalation', function () {
    $letters = TicketCategory::query()->where('code', 'LETTER')->first();
    $role = Role::factory()->create();
    $role->permissions()->sync(app(PermissionRegistry::class)->idsMatching(['servicedesk.view', 'servicedesk.agent']));
    $this->agent->roles()->attach($role);
    $letters->update(['assignee_role_id' => $role->id, 'escalation_role_id' => $role->id]);

    $ticket = $this->desk->open($this->employee, $letters, 'Experience letter', 'For a visa application', 'high', $this->employee->user);
    expect($ticket->number)->toBe('TKT-2026-00001')
        ->and($ticket->status)->toBe('assigned') // Phase 12 lifecycle: auto-assigned on submission
        ->and($ticket->assignee_id)->toBe($this->agent->id)
        ->and($ticket->due_at->toDateTimeString())->toBe('2026-09-22 21:00:00') // high: 72h / 2
        ->and($this->agent->notifications()->count())->toBe(1);
    expect($this->desk->open($this->employee, $letters, 'Second', 'x')->number)->toBe('TKT-2026-00002');

    $this->desk->comment($ticket, $this->agent, 'Which dates should it cover?');
    expect($ticket->refresh()->first_responded_at)->not->toBeNull()->and($ticket->status)->toBe('in_progress');
    $this->desk->waitOnEmployee($ticket, $this->agent);
    expect($ticket->refresh()->status)->toBe('waiting_employee');
    $this->desk->comment($ticket, $this->employee->user, '2024 to date');
    expect($ticket->refresh()->status)->toBe('in_progress');
    $internal = $this->desk->comment($ticket, $this->agent, 'Check with finance', true);
    expect($internal->is_internal)->toBeTrue();

    $this->desk->resolve($ticket, 'Letter emailed', $this->agent);
    expect($ticket->refresh()->status)->toBe('resolved')->and($this->employee->user->notifications()->count())->toBeGreaterThan(0);
    expect(fn () => $this->desk->close($ticket, $this->employee->user, 9))->toThrow(RuntimeException::class, '1 to 5');
    $this->desk->close($ticket, $this->employee->user, 5, 'Fast');
    expect($ticket->refresh()->status)->toBe('closed')->and($ticket->satisfaction)->toBe(5);
    expect(fn () => $this->desk->comment($ticket, $this->agent, 'late'))->toThrow(RuntimeException::class, 'closed');
    $this->desk->reopen($ticket, 'Wrong dates', $this->employee->user);
    expect($ticket->refresh()->status)->toBe('in_progress')->and($ticket->closed_at)->toBeNull();

    // Reopening restarted the SLA (72 h → 24 Sep 09:00); the second ticket is due then too. Breaches escalate once.
    $this->travelTo('2026-09-25 09:00:00');
    $result = $this->desk->tick();
    expect($result['escalated'])->toBe(2)
        ->and(AuditEvent::query()->where('module', 'servicedesk')->where('action', 'REQUEST_ESCALATED')->count())->toBe(2)
        ->and($this->desk->tick()['escalated'])->toBe(0);
    $this->desk->resolve($ticket->refresh(), 'Fixed dates', $this->agent);
    $this->travelTo('2026-10-01 09:00:00');
    expect($this->desk->tick()['auto_closed'])->toBe(1)->and($ticket->refresh()->status)->toBe('closed');
});

it('starts a workflow when the category asks for one', function () {
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'HR approval', 'config' => ['mode' => 'single', 'approver' => ['type' => 'user', 'user_id' => $this->hr->id]]]]);
    $workflow = publishWorkflow($nodes, $edges, ['key' => 'letter_approval', 'name' => 'Letter approval']);
    $category = TicketCategory::query()->where('code', 'LETTER')->first();
    $category->update(['workflow_key' => 'letter_approval']);

    $ticket = $this->desk->open($this->employee, $category, 'Salary certificate', 'Bank loan');
    expect($ticket->workflow_instance_id)->not->toBeNull()
        ->and(WorkflowInstance::query()->find($ticket->workflow_instance_id)->subject_id)->toBe($ticket->id);
});

it('keeps grievances confidential to handlers, granted users and the employee, and audits every access', function () {
    $grievances = app(Grievances::class);
    $icc = Role::factory()->create(['name' => 'Internal Committee']);
    $member = tenantUser($this->tenant, ['grievance.view']);
    $member->roles()->attach($icc);
    $manager = tenantUser($this->tenant, ['grievance.manage']);
    $outsider = tenantUser($this->tenant, ['grievance.view']);

    $posh = GrievanceCategory::query()->where('code', 'POSH')->first();
    $posh->update(['handler_role_ids' => [$icc->id]]);
    $safety = GrievanceCategory::query()->where('code', 'SAFETY')->first();

    expect(fn () => $grievances->raise($posh, $this->employee, 'x', 'y', 'high', true))->toThrow(RuntimeException::class, 'anonymous');

    $case = $grievances->raise($posh, $this->employee, 'Complaint', 'Details of the incident', 'high', false, $this->employee->user);
    expect($case->number)->toBe('GRV-2026-00001')->and($case->assignee_id)->toBe($member->id)->and($case->status)->toBe('under_review')
        ->and($case->due_on->toDateString())->toBe('2026-12-20')
        ->and($grievances->canAccess($member, $case))->toBeTrue()
        ->and($grievances->canAccess($this->employee->user, $case))->toBeTrue()
        ->and($grievances->canAccess($manager, $case))->toBeFalse()   // confidential: grievance.manage is not enough
        ->and($grievances->canAccess($outsider, $case))->toBeFalse()
        ->and($this->employee->user->can('view', $case))->toBeTrue()
        ->and($manager->can('view', $case))->toBeFalse();

    $grievances->grantAccess($case, $manager, 'Legal review', $member);
    expect($grievances->canAccess($manager, $case->refresh()))->toBeTrue()
        ->and(AuditEvent::query()->where('module', 'grievance')->where('action', 'PERMISSION_CHANGED')->exists())->toBeTrue();

    $grievances->recordAccess($case, $member);
    expect(AuditEvent::query()->where('module', 'grievance')->where('action', 'VIEW')->where('actor_id', $member->id)->exists())->toBeTrue();

    $grievances->addNote($case, $member, 'evidence', 'Statement collected');
    $grievances->addNote($case, $member, 'action', 'Counselling arranged', true);
    expect($case->refresh()->status)->toBe('action_taken')
        ->and($case->notes()->where('visible_to_employee', true)->count())->toBe(1);
    expect(fn () => $grievances->close($case, $member))->toThrow(RuntimeException::class, 'Resolve');
    $grievances->resolve($case, 'Closed with a written warning', $member);
    $grievances->close($case, $member);
    expect($case->refresh()->status)->toBe('closed');

    // Anonymous safety case: no employee on record, open to grievance managers because the category is not confidential.
    $anon = $grievances->raise($safety, null, 'Loose wiring', 'Third floor', 'high', true);
    expect($anon->employee_id)->toBeNull()->and($anon->is_anonymous)->toBeTrue()
        ->and($grievances->canAccess($manager, $anon))->toBeTrue()
        ->and($grievances->canAccess($outsider, $anon))->toBeFalse();
    expect(fn () => $grievances->withdraw($anon, $this->employee->user, 'x'))->toThrow(RuntimeException::class, 'raised the case');
});
