<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Experience\Contracts\SurveyTaskProvider;
use App\Domain\Experience\Services\ExperienceTasks;
use App\Domain\Knowledge\Models\Article;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\ServiceDeskAnalytics;
use App\Domain\ServiceDesk\Services\ServiceDeskBulk;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\ServiceDeskAnalyticsPage;
use App\Filament\Pages\TeamRequests;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ServiceDefinitions\ServiceDefinitionResource;
use App\Filament\Resources\ServiceSlaPolicies\ServiceSlaPolicyResource;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use Livewire\Livewire;

require_once __DIR__.'/ServiceDeskTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->manager = activeEmployee(null, ['servicedesk.request', 'servicedesk.team', 'performance.team', 'kb.view', 'task.view', 'document.own']);
    $this->employee = activeEmployee($this->manager, ['servicedesk.request', 'kb.view', 'task.view', 'document.own']);
    $this->agent = sdAgent(['servicedesk.bulk']);
    $this->service = sdApprovedService('MY_HR_Q', ['description' => 'Ask about anything', 'manager_visible' => true, 'availability' => ['employee' => true, 'manager' => true, 'hr' => true], 'assignment' => ['role_id' => sdTeam($this->agent)->id]]);
    $this->article = Article::create(['title' => 'Leave policy', 'category' => 'leave', 'body' => '# Leave', 'requires_acknowledgement' => true]);
    kbPublishForTests($this->article);
});

it('renders My HR for an employee: services, requests, tasks, policies, documents, approvals and notifications', function () {
    $ticket = app(ServiceRequests::class)->submit($this->service, $this->employee, $this->employee->user);
    $this->actingAs($this->employee->user);

    $this->get(MyHr::getUrl())->assertOk()->assertSee('My requests')->assertSee($ticket->number);
    foreach (['tasks' => 'Acknowledge: Leave policy', 'policies' => 'Leave policy', 'services' => 'Ask about anything', 'documents' => 'No documents yet', 'approvals' => 'No approvals', 'notifications' => 'Notifications'] as $tab => $text) {
        $this->get(MyHr::getUrl(['tab' => $tab]))->assertOk()->assertSee($text);
    }

    Livewire::test(MyHr::class)->set('tab', 'services')
        ->callAction('requestService', ['data' => [], 'subject' => 'Payslip question'], ['service' => $this->service->id, 'for' => 'self'])
        ->assertNotified('Request TKT-2026-00002 submitted');
    Livewire::test(MyHr::class)->call('acknowledge', $this->article->id)->assertNotified('Acknowledged');
    expect(Ticket::query()->where('employee_id', $this->employee->id)->count())->toBe(2);

    // The survey hook contributes nothing until Phase 13 binds a provider.
    expect(app(SurveyTaskProvider::class)->tasksFor($this->employee->user, $this->employee))->toHaveCount(0)
        ->and(app(ExperienceTasks::class)->for($this->employee->user, $this->employee)->pluck('domain')->unique()->all())->not->toContain('survey');
});

it('gives managers a status-only team view and lets them raise manager services for a report', function () {
    app(ServiceRequests::class)->submit($this->service, $this->employee, $this->employee->user, [], ['subject' => 'Private wording']);
    $this->actingAs($this->manager->user);
    $this->get(TeamRequests::getUrl())->assertOk()->assertSee('TKT-2026-00001')->assertDontSee('Private wording');
    Livewire::test(TeamRequests::class)->callAction('requestService', ['employee_id' => $this->employee->id, 'data' => []], ['service' => $this->service->id, 'for' => 'report'])
        ->assertNotified('Request TKT-2026-00002 submitted');
    expect(Ticket::query()->where('source', 'manager')->count())->toBe(1);

    $this->actingAs($this->employee->user);
    $this->get(TeamRequests::getUrl())->assertForbidden();
});

it('renders the HR-side pages: catalogue, SLA policies, HR queue, case detail, analytics, Employee 360 requests', function () {
    $ticket = app(ServiceRequests::class)->submit($this->service, $this->employee, $this->employee->user);
    $this->get(ServiceDefinitionResource::getUrl('index'))->assertOk()->assertSee('My hr q');
    $this->get(ServiceDefinitionResource::getUrl('edit', ['record' => $this->service]))->assertOk()->assertSee('Versions');
    $this->get(ServiceDefinitionResource::getUrl('create'))->assertOk();
    $this->get(ServiceSlaPolicyResource::getUrl('index'))->assertOk()->assertSee('Standard HR service');
    $this->get(ServiceSlaPolicyResource::getUrl('create'))->assertOk();
    $this->get(TicketResource::getUrl('index'))->assertOk()->assertSee($ticket->number);
    $this->get(TicketResource::getUrl('view', ['record' => $ticket]))->assertOk()->assertSee('Status history')->assertSee('Assignment and SLA');
    $this->get(ServiceDeskAnalyticsPage::getUrl())->assertOk();
    $this->get(EmployeeResource::getUrl('view', ['record' => $this->employee]))->assertOk();

    $this->actingAs($this->agent);
    Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('acknowledge')->assertNotified('Acknowledged');
    Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('reply', data: ['body' => 'Internal check', 'visibility' => 'internal'])->assertNotified('Reply posted');
    Livewire::test(ViewTicket::class, ['record' => $ticket->id])->callAction('resolve', data: ['resolution' => 'Answered'])->assertNotified('Resolved');
    expect($ticket->refresh()->status)->toBe('resolved')->and($ticket->comments()->where('visibility', 'internal')->count())->toBe(1);
});

it('runs bulk operations with one operation id, per-case authorisation, idempotent skips and a full report', function () {
    $a = app(ServiceRequests::class)->submit($this->service, $this->employee, $this->employee->user);
    $b = app(ServiceRequests::class)->submit($this->service, $this->manager, $this->manager->user);
    $other = sdAgent();
    $bulk = app(ServiceDeskBulk::class);

    expect(fn () => $bulk->assign([$a->id], $other, sdAgent()))->toThrow(ServiceDeskRuleViolation::class, 'servicedesk.bulk');
    $result = $bulk->assign([$a->id, $b->id, 999999], $other, $this->agent, 'Rebalance');
    expect($result['results'])->toBe([$a->id => 'done', $b->id => 'done', 999999 => 'refused: not found'])
        ->and(AuditEvent::query()->where('operation_id', $result['operation_id'])->where('action', 'REQUEST_REASSIGNED')->count())->toBe(2)
        ->and(AuditEvent::query()->where('operation_id', $result['operation_id'])->where('action', 'BULK_OPERATION')->exists())->toBeTrue();
    expect($bulk->assign([$a->id], $other, $this->agent)['results'][$a->id])->toBe('skipped: already assigned');
    expect(fn () => $bulk->move([$a->id], 'closed', $this->agent))->toThrow(ServiceDeskRuleViolation::class, 'individually');
    $moved = $bulk->move([$a->id, $b->id], 'waiting_hr', $this->agent, 'Payroll input');
    expect(collect($moved['results'])->unique()->values()->all())->toBe(['done']);
});

it('reports service analytics with small groups suppressed and confidential cases hidden', function () {
    config(['peopleos.servicedesk.analytics_min_group' => 3]);
    $analyst = tenantUser($this->tenant, ['servicedesk.analytics']);
    expect(app(ServiceDeskAnalytics::class)->summary($analyst)['suppressed'])->toBeTrue();
    foreach (range(1, 4) as $i) {
        $e = activeEmployee(null, ['servicedesk.request']);
        app(ServiceRequests::class)->submit($this->service, $e, $e->user);
    }
    $lonely = sdApprovedService('RARE_Q');
    $e = activeEmployee(null, ['servicedesk.request']);
    app(ServiceRequests::class)->submit($lonely, $e, $e->user);
    $er = sdApprovedService('ER_X', ['confidentiality' => 'restricted', 'availability' => ['employee' => false, 'manager' => false, 'hr' => true]]);
    app(ServiceRequests::class)->submit($er, $this->employee, sdAgent(['servicedesk.confidential']), [], ['source' => 'hr']);

    $summary = app(ServiceDeskAnalytics::class)->summary($analyst);
    $byService = collect($summary['by_service'])->keyBy('group');
    expect($summary['suppressed'])->toBeFalse()->and($summary['received'])->toBe(5)
        ->and($summary['confidential_cases'])->toBe('fewer than 3')
        ->and($byService['Rare q']['suppressed'])->toBeTrue()
        ->and($byService['My hr q']['suppressed'])->toBeTrue() // complementary suppression: otherwise total − visible = the hidden group
        ->and(json_encode($summary))->not->toContain($this->employee->person->full_name);
    expect(fn () => app(ServiceDeskAnalytics::class)->summary($this->employee->user))->toThrow(ServiceDeskRuleViolation::class);
});
