<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\ChangeStatutoryApplicabilityAction;
use App\Domain\Employment\Actions\ChangeStatutoryIdentityAction;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\ArticleRead;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\People\Models\PersonAddress;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\ServiceDeskReminderLog;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketAccessGrant;
use App\Domain\ServiceDesk\Models\TicketTransition;
use App\Domain\ServiceDesk\Services\CaseAssignment;
use App\Domain\ServiceDesk\Services\DomainActionExecutor;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceDeskProcessor;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/ServiceDesk/ServiceDeskTestHelpers.php';
require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | Phase 12 §47 + the People Domain Change Actions decision: real concurrency on MySQL for the HR
 | service desk. Same harness and opt-in as the Phase 8–11 suites (PEOPLEOS_MYSQL_CONCURRENCY_DB,
 | name containing "concurrency"); SQLite runs skip these and claim nothing about MySQL locking.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency', 'queue.default' => 'sync']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->travelTo('2026-09-21 09:00:00');
    $this->tenant = provisionTenant('Desk race '.uniqid());
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee(null, ['servicedesk.request', 'kb.view']);
    $perms = ['employee.sensitive.update', 'employee.sensitive.view', 'employee.update', 'employee.create'];
    [$this->a1, $this->a2, $this->a3] = [sdAgent($perms), sdAgent($perms), sdAgent($perms)];
    $this->requests = app(ServiceRequests::class);
    $this->service = sdApprovedService('RACE_Q');
    $this->fresh = fn (int $id) => Ticket::query()->withoutGlobalScope(AccessScope::class)->findOrFail($id);
    $this->audits = fn (Ticket $t, string $action) => AuditEvent::query()->where('entity_type', Ticket::class)->where('entity_id', (string) $t->id)->where('action', $action)->count();
    $this->bank = ['account_holder_name' => 'Race Person', 'bank_name' => 'HDFC', 'ifsc' => 'HDFC0001234', 'account_number' => '50100999888777', 'account_type' => 'salary'];
});

/** Service desk writes slowed down, so a missing lock would let both writers through. */
function deskSlowEvents(): array
{
    return ['eloquent.updating: '.Ticket::class, 'eloquent.creating: '.Ticket::class, 'eloquent.creating: '.TicketTransition::class, 'eloquent.updating: '.ArticleRead::class,
        'eloquent.creating: '.EmployeeBankAccount::class, 'eloquent.creating: '.PersonAddress::class, 'eloquent.creating: '.EmployeeStatutoryDetail::class,
        'eloquent.updating: '.ServiceDefinitionVersion::class, 'eloquent.updating: '.TicketAccessGrant::class];
}

it('1. creates one request for two submissions with the same idempotency key', function () {
    $submit = fn () => app(ServiceRequests::class)->submit($this->service, $this->employee, $this->employee->user, [], ['idempotency_key' => 'race-key']);
    $results = race([$submit, $submit], slow: deskSlowEvents());

    $tickets = Ticket::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->employee->id)->where('idempotency_key', 'race-key')->get();
    expect($results)->toBe(['ok', 'ok'])->and($tickets)->toHaveCount(1)
        ->and(($this->audits)($tickets->first(), 'REQUEST_CREATED'))->toBe(1);
});

it('2. lets exactly one of two agents claim the same request', function () {
    $ticket = $this->requests->submit($this->service, $this->employee, $this->employee->user);
    $results = race([fn () => app(CaseAssignment::class)->claim(($this->fresh)($ticket->id), $this->a1), fn () => app(CaseAssignment::class)->claim(($this->fresh)($ticket->id), $this->a2)], slow: deskSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already taken')
        ->and(($this->fresh)($ticket->id)->assignee_id)->toBeIn([$this->a1->id, $this->a2->id])
        ->and(($this->audits)($ticket, 'REQUEST_ASSIGNED'))->toBe(1);
});

it('3. resolves a request once when two agents resolve it together', function () {
    $ticket = $this->requests->submit($this->service, $this->employee, $this->employee->user);
    $desk = app(ServiceDesk::class);
    $results = race([fn () => $desk->resolve(($this->fresh)($ticket->id), 'First answer', $this->a1), fn () => $desk->resolve(($this->fresh)($ticket->id), 'Second answer', $this->a2)], slow: deskSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already resolved')
        ->and(($this->audits)($ticket, 'REQUEST_RESOLVED'))->toBe(1);
});

it('4. never reassigns a request after it was resolved, whichever wins the race', function () {
    $ticket = $this->requests->submit($this->service, $this->employee, $this->employee->user);
    app(CaseAssignment::class)->claim($ticket, $this->a1);
    $start = ($this->fresh)($ticket->id)->lock_version;
    $results = race([fn () => app(CaseAssignment::class)->assign(($this->fresh)($ticket->id), $this->a2, $this->a1), fn () => app(ServiceDesk::class)->resolve(($this->fresh)($ticket->id), 'Done', $this->a1)], slow: deskSlowEvents());

    $final = ($this->fresh)($ticket->id);
    $order = AuditEvent::query()->where('entity_type', Ticket::class)->where('entity_id', (string) $ticket->id)->whereIn('action', ['REQUEST_REASSIGNED', 'REQUEST_RESOLVED'])->orderBy('id')->pluck('action')->map(fn ($a) => $a->value)->all();
    expect($final->status)->toBe('resolved')->and(end($order))->toBe('REQUEST_RESOLVED')
        ->and($final->lock_version)->toBe($start + collect($results)->filter(fn ($r) => $r === 'ok')->count());
    if ($results[0] !== 'ok') {
        expect($results[0])->toContain('Only an open request');
    }
});

it('5. never escalates a request after it was paused, when the SLA run races a status change', function () {
    $policy = ServiceSlaPolicy::query()->create(['code' => 'FAST', 'name' => 'Fast', 'calendar' => 'calendar', 'effective_from' => '2026-01-01', 'max_escalation_level' => 1, 'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 1]]]);
    $service = sdApprovedService('RACE_SLA', ['sla_policy_id' => $policy->id]);
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user);
    $this->travelTo('2026-09-21 11:00:00');
    $results = race([fn () => app(ServiceDeskProcessor::class)->run(), fn () => app(ServiceDesk::class)->waitOnEmployee(($this->fresh)($ticket->id), $this->a1)], slow: deskSlowEvents());

    $escalated = AuditEvent::query()->where('entity_type', Ticket::class)->where('entity_id', (string) $ticket->id)->where('action', 'REQUEST_ESCALATED')->value('id');
    $paused = AuditEvent::query()->where('entity_type', Ticket::class)->where('entity_id', (string) $ticket->id)->where('action', 'REQUEST_STATUS_CHANGED')->value('id');
    expect($results)->toBe(['ok', 'ok'])->and(($this->fresh)($ticket->id)->status)->toBe('waiting_employee');
    if ($escalated !== null) {
        expect($escalated < $paused)->toBeTrue(); // escalated while still running, then paused — never the reverse
    }
});

it('6. sends one escalation when two SLA runs overlap', function () {
    $policy = ServiceSlaPolicy::query()->create(['code' => 'FAST2', 'name' => 'Fast', 'calendar' => 'calendar', 'effective_from' => '2026-01-01', 'max_escalation_level' => 3, 'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 1]]]);
    $ticket = $this->requests->submit(sdApprovedService('RACE_SLA2', ['sla_policy_id' => $policy->id]), $this->employee, $this->employee->user);
    $this->travelTo('2026-09-21 11:00:00');
    $run = fn () => app(ServiceDeskProcessor::class)->run();
    $results = race([$run, $run], slow: deskSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(($this->audits)($ticket, 'REQUEST_ESCALATED'))->toBe(1)
        ->and(ServiceDeskReminderLog::query()->where('reminder', 'escalation')->where('subject_id', $ticket->id)->count())->toBe(1)
        ->and(($this->fresh)($ticket->id)->escalation_level)->toBe(1);
});

it('7. applies a requested profile change once when two executors run it together', function () {
    $service = sdApprovedService('RACE_ADDR', ['domain_action' => 'profile.address', 'confidentiality' => 'sensitive']);
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, ['type' => array_key_first(config('peopleos.people.address_types')), 'address_line_1' => '1 Race Road', 'country_code' => 'IN']);
    $results = race([fn () => app(DomainActionExecutor::class)->execute(($this->fresh)($ticket->id), $this->a1), fn () => app(DomainActionExecutor::class)->execute(($this->fresh)($ticket->id), $this->a2)], slow: deskSlowEvents());

    expect($results)->toBe(['ok', 'ok']) // the second finds it executed and changes nothing
        ->and(PersonAddress::query()->where('person_id', $this->employee->person_id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('metadata->event', 'DOMAIN_ACTION_EXECUTED')->where('entity_id', (string) $ticket->id)->count())->toBe(1);
});

it('8. records one acknowledgement when the same policy is acknowledged twice at once', function () {
    $article = Article::create(['title' => 'Race policy', 'category' => 'conduct', 'body' => 'Rules.', 'requires_acknowledgement' => true]);
    kbPublishForTests($article);
    app(KnowledgeBase::class)->recordRead($article->refresh(), $this->employee);
    $ack = fn () => app(KnowledgeBase::class)->acknowledge(Article::query()->findOrFail($article->id), $this->employee, 'web', '10.0.0.1');
    $results = race([$ack, $ack], slow: deskSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(AuditEvent::query()->where('action', 'POLICY_ACKNOWLEDGED')->where('entity_id', (string) $article->id)->count())->toBe(1)
        ->and(ArticleRead::query()->where('article_id', $article->id)->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('9. serialises two simultaneous assignments of one case (no lost update)', function () {
    $ticket = $this->requests->submit($this->service, $this->employee, $this->employee->user);
    app(CaseAssignment::class)->claim($ticket, $this->a1);
    $start = ($this->fresh)($ticket->id)->lock_version;
    [$lead1, $lead2] = [sdAgent(), sdAgent()];
    $results = race([fn () => app(CaseAssignment::class)->assign(($this->fresh)($ticket->id), $this->a2, $lead1), fn () => app(CaseAssignment::class)->assign(($this->fresh)($ticket->id), $this->a3, $lead2)], slow: deskSlowEvents());

    $final = ($this->fresh)($ticket->id);
    $last = AuditEvent::query()->where('entity_type', Ticket::class)->where('entity_id', (string) $ticket->id)->where('action', 'REQUEST_REASSIGNED')->orderByDesc('id')->firstOrFail();
    expect($results)->toBe(['ok', 'ok'])->and($final->lock_version)->toBe($start + 2)
        ->and((string) $last->fieldChanges()->where('field', 'assignee_id')->value('after'))->toBe((string) $final->assignee_id);
});

it('10. never lets a revoked confidential grant act on the case, even mid-flight', function () {
    $investigator = sdAgent(['servicedesk.confidential']);
    $colleague = sdAgent(['servicedesk.confidential']);
    $er = sdApprovedService('RACE_ER', ['confidentiality' => 'restricted', 'availability' => ['employee' => false, 'manager' => false, 'hr' => true]]);
    $case = $this->requests->submit($er, $this->employee, $investigator, [], ['source' => 'hr']);
    app(ServiceDesk::class)->grantAccess($case, $colleague, 'Second pair of eyes', $investigator);
    $results = race([fn () => app(ServiceDesk::class)->resolve(($this->fresh)($case->id), 'Closed out', $colleague), fn () => app(ServiceDesk::class)->revokeAccess(($this->fresh)($case->id), $colleague, 'Conflict', $investigator)], slow: deskSlowEvents());

    $final = ($this->fresh)($case->id);
    $grant = TicketAccessGrant::query()->where('ticket_id', $case->id)->where('user_id', $colleague->id)->firstOrFail();
    expect($results[1])->toBe('ok')->and($grant->revoked_at)->not->toBeNull();
    if ($final->status === 'resolved') {
        expect($results[0])->toBe('ok')->and($final->resolved_at->lessThanOrEqualTo($grant->revoked_at))->toBeTrue();
    } else {
        expect($results[0])->toContain('cannot work');
    }
});

it('P1. keeps one bank account when the same account is added by two requests at once', function () {
    $service = sdApprovedService('RACE_BANK', ['domain_action' => 'profile.bank_account', 'confidentiality' => 'sensitive']);
    $t1 = $this->requests->submit($service, $this->employee, $this->employee->user, $this->bank);
    $t2 = $this->requests->submit($service, $this->employee, $this->employee->user, $this->bank);
    $results = race([fn () => app(DomainActionExecutor::class)->execute(($this->fresh)($t1->id), $this->a1), fn () => app(DomainActionExecutor::class)->execute(($this->fresh)($t2->id), $this->a2)], slow: deskSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already on file')
        ->and(EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('P2. serialises simultaneous statutory changes to one employee (one record, both changes)', function () {
    $identity = fn () => app(ChangeStatutoryIdentityAction::class)->handle($this->employee, ['pan' => 'ABCDE1234F'], $this->a1);
    $applicability = fn () => app(ChangeStatutoryApplicabilityAction::class)->handle($this->employee, ['tax_regime' => 'old'], $this->a2);
    $results = race([$identity, $applicability], slow: deskSlowEvents());

    $rows = EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->employee->id)->get();
    expect($results)->toBe(['ok', 'ok'])->and($rows)->toHaveCount(1)
        ->and($rows->first()->pan)->toBe('ABCDE1234F')->and($rows->first()->tax_regime)->toBe('old');
});

it('P3. approves a service version once when two approvers approve it together', function () {
    $catalogue = app(ServiceCatalogue::class);
    $preparer = tenantUser($this->tenant, ['servicedesk.manage']);
    $service = $catalogue->create(['code' => 'RACE_CAT', 'name' => 'Race catalogue', 'effective_from' => '2026-09-21'], $preparer);
    $version = $service->versions()->first();
    $catalogue->submit($version, $preparer);
    [$ap1, $ap2] = [tenantUser($this->tenant, ['servicedesk.catalogue_approve']), tenantUser($this->tenant, ['servicedesk.catalogue_approve'])];
    $fresh = fn () => ServiceDefinitionVersion::query()->findOrFail($version->id);
    $results = race([fn () => $catalogue->approve($fresh(), $ap1), fn () => $catalogue->approve($fresh(), $ap2)], slow: deskSlowEvents());

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('cannot be approved')
        ->and(AuditEvent::query()->where('entity_type', ServiceDefinitionVersion::class)->where('entity_id', (string) $version->id)->where('action', 'APPROVED')->count())->toBe(1);
});

it('P4. changes the data once when a service-request execution is retried by the same executor', function () {
    $service = sdApprovedService('RACE_BANK2', ['domain_action' => 'profile.bank_account', 'confidentiality' => 'sensitive']);
    $ticket = $this->requests->submit($service, $this->employee, $this->employee->user, $this->bank);
    $execute = fn () => app(DomainActionExecutor::class)->execute(($this->fresh)($ticket->id), $this->a1);
    $results = race([$execute, $execute], slow: deskSlowEvents());

    expect($results)->toBe(['ok', 'ok'])
        ->and(EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('entity_type', EmployeeBankAccount::class)->where('operation_id', ($this->fresh)($ticket->id)->operation_id)->count())->toBe(1);
});
