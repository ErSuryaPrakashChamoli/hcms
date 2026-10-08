<?php

use App\Domain\Ai\Assistants\EmployeeAssistant;
use App\Domain\Analytics\Datasets\ServiceDeskDataset;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\NeedsAttention;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketAccessGrant;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\ServiceDesk\Services\CaseAssignment;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Filament\Resources\Tickets\TicketResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/ServiceDeskTestHelpers.php';

function sdHireAt(Company $company, Location $location, string $first, ?Employee $manager = null, ?int $userId = null): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Person'], ['joining_date' => '2025-01-01'] + ($userId ? ['user_id' => $userId] : []), ['company_id' => $company->id, 'location_id' => $location->id], $manager?->id);
}

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00');
    Storage::fake('local');
    config(['peopleos.documents.disk' => 'local']);
    $this->tenant = provisionTenant('Tenant A');
    $this->tenantB = provisionTenant('Tenant B');
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->company = Company::factory()->create(['name' => 'Alpha']);
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $this->delhiEmp = sdHireAt($this->company, $this->delhi, 'Dee');
    $this->mumbaiEmp = sdHireAt($this->company, $this->mumbai, 'Moo');
    $this->hr = sdAgent();
    $this->scoped = sdAgent();
    app(AccessScopes::class)->assign($this->scoped, ['company' => [$this->company->id], 'location' => [$this->delhi->id]], 'Delhi desk');
    $this->requests = app(ServiceRequests::class);
    $this->service = sdApprovedService('GENERAL_Q', ['availability' => ['employee' => true, 'manager' => true, 'hr' => true], 'manager_visible' => true]);
});

it('restricts the HR queue in SQL by organisation scope and never assigns a case out of scope', function () {
    $delhi = $this->requests->submit($this->service, $this->delhiEmp, $this->hr, [], ['source' => 'hr']);
    $mumbai = $this->requests->submit($this->service, $this->mumbaiEmp, $this->hr, [], ['source' => 'hr']);

    $this->actingAs($this->scoped);
    expect(TicketResource::getEloquentQuery()->pluck('tickets.id')->all())->toBe([$delhi->id])
        ->and(app(CaseAccess::class)->canView($this->scoped, $mumbai))->toBeFalse()
        ->and(app(CaseAccess::class)->canWork($this->scoped, $mumbai))->toBeFalse()
        ->and(app(CaseAccess::class)->eligibleAgent($this->scoped, $mumbai))->toBeFalse();
    $this->get(TicketResource::getUrl('view', ['record' => $mumbai]))->assertNotFound();

    $this->actingAs($this->hr);
    expect(fn () => app(CaseAssignment::class)->assign($mumbai, $this->scoped, $this->hr))->toThrow(ServiceDeskRuleViolation::class, 'organisation scope');
    app(CaseAssignment::class)->assign($delhi, $this->scoped, $this->hr);
    expect($delhi->refresh()->assignee_id)->toBe($this->scoped->id);
});

it('keeps tenants apart in Filament and the API (404)', function () {
    actAsTenant($this->tenantB);
    $companyB = Company::factory()->create(['name' => 'Beta']);
    $empB = sdHireAt($companyB, Location::factory()->create(['company_id' => $companyB->id]), 'Bee');
    $hrB = sdAgent();
    $serviceB = sdApprovedService('GENERAL_B');
    $ticketB = app(ServiceRequests::class)->submit($serviceB, $empB, $hrB, [], ['source' => 'hr']);
    actAsTenant($this->tenant);

    $this->get(TicketResource::getUrl('view', ['record' => $ticketB->id]))->assertNotFound();
    $key = app(ApiKeys::class)->issue('Desk', ['servicedesk.read']);
    auth()->logout();
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/service-desk/requests/'.$ticketB->number)->assertNotFound();
    $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/service-desk/requests')->assertOk()->assertJsonPath('meta.total', 0);
});

it('shows managers status only, for manager-visible standard requests of the people they manage — never through mentor links', function () {
    $managerUser = tenantUser($this->tenant, ['servicedesk.request', 'servicedesk.team']);
    $manager = sdHireAt($this->company, $this->delhi, 'Mana', null, $managerUser->id);
    $mentorUser = tenantUser($this->tenant, ['servicedesk.request', 'servicedesk.team']);
    $mentor = sdHireAt($this->company, $this->delhi, 'Ment', null, $mentorUser->id);
    $report = sdHireAt($this->company, $this->delhi, 'Rep', $manager);
    app(ChangeManagerAction::class)->handle($report, $mentor, 'mentor', '2025-01-01');

    $hidden = sdApprovedService('NOT_FOR_MANAGERS', ['manager_visible' => false]);
    $sensitive = sdApprovedService('SENSITIVE_Q', ['manager_visible' => true, 'confidentiality' => 'sensitive']);
    $visible = $this->requests->submit($this->service, $report, $this->hr, ['note' => 'x'], ['source' => 'hr']);
    $this->requests->submit($hidden, $report, $this->hr, [], ['source' => 'hr']);
    $this->requests->submit($sensitive, $report, $this->hr, [], ['source' => 'hr']);

    $access = app(CaseAccess::class);
    expect($access->team(Ticket::query(), $managerUser)->pluck('tickets.id')->all())->toBe([$visible->id])
        ->and($access->team(Ticket::query(), $mentorUser)->count())->toBe(0)
        ->and($access->canView($mentorUser, $visible))->toBeFalse()
        ->and($access->commentVisibilities($managerUser, $visible))->toBe([])
        ->and($access->formDataFor($managerUser, $visible))->toBe([])
        ->and($access->canWork($managerUser, $visible))->toBeFalse();
});

it('opens restricted cases only to explicit access, hides them from the subject, and audits every read', function () {
    $investigator = sdAgent(['servicedesk.confidential']);
    $otherHr = tenantUser($this->tenant, ['*']); // every permission, but no explicit access
    $er = sdApprovedService('ER_CASE', ['confidentiality' => 'restricted', 'visible_to_employee' => false, 'availability' => ['employee' => false, 'manager' => false, 'hr' => true]]);
    $subjectUser = tenantUser($this->tenant, ['servicedesk.request']);
    $subject = sdHireAt($this->company, $this->delhi, 'Sub', null, $subjectUser->id);
    $case = $this->requests->submit($er, $subject, $investigator, [], ['source' => 'hr', 'description' => 'Allegation details']);

    $access = app(CaseAccess::class);
    expect($case->owner_id)->toBe($investigator->id)
        ->and($access->canView($investigator, $case))->toBeTrue()
        ->and($access->canView($otherHr, $case))->toBeFalse()
        ->and($access->canView($subjectUser, $case))->toBeFalse()
        ->and($access->canView($this->hr, $case))->toBeFalse()
        ->and($access->visible(Ticket::query(), $otherHr)->whereKey($case->id)->exists())->toBeFalse();

    $this->actingAs($otherHr);
    // Not even its existence is revealed: the query never returns it.
    $this->get(TicketResource::getUrl('view', ['record' => $case]))->assertNotFound();

    app(ServiceDesk::class)->grantAccess($case, $otherHr, 'Second investigator', $investigator);
    expect($access->canView($otherHr, $case))->toBeTrue();
    // The grant's reason can describe the case: masked in the audit field diff.
    $reasonChange = AuditEventChange::query()->whereIn('audit_event_id', AuditEvent::query()->where('entity_type', TicketAccessGrant::class)->select('id'))->where('field', 'reason')->firstOrFail();
    expect($reasonChange->is_sensitive)->toBeTrue()->and($reasonChange->after)->toBe('••••');
    $this->get(TicketResource::getUrl('view', ['record' => $case]))->assertOk();
    expect(AuditEvent::query()->where('action', 'CONFIDENTIAL_CASE_VIEWED')->where('entity_id', (string) $case->id)->where('actor_id', $otherHr->id)->exists())->toBeTrue();

    app(ServiceDesk::class)->revokeAccess($case, $otherHr, 'Done', $investigator);
    expect($access->canView($otherHr, $case))->toBeFalse()
        ->and(fn () => app(ServiceDesk::class)->grantAccess($case, $this->hr, 'x', $investigator))->toThrow(ServiceDeskRuleViolation::class, 'servicedesk.confidential');
});

it('never gives integrations internal notes, restricted cases, sensitive fields, free text or attachments', function () {
    $bank = sdApprovedService('BANK_API', ['domain_action' => 'profile.bank_account', 'confidentiality' => 'sensitive']);
    $userEmp = tenantUser($this->tenant, ['servicedesk.request']);
    $emp = sdHireAt($this->company, $this->delhi, 'Api', null, $userEmp->id);
    $sensitive = $this->requests->submit($bank, $emp, $userEmp, ['account_holder_name' => 'Api Person', 'bank_name' => 'HDFC', 'account_number' => '99887766554433']);
    $standard = $this->requests->submit($this->service, $emp, $userEmp, [], ['subject' => 'Private subject text', 'description' => 'Private description']);
    app(ServiceDesk::class)->comment($standard, $this->hr, 'Visible reply', 'employee');
    app(ServiceDesk::class)->comment($standard, $this->hr, 'Internal HR remark', 'internal');
    $restricted = $this->requests->submit(sdApprovedService('ER_API', ['confidentiality' => 'restricted', 'availability' => ['employee' => false, 'manager' => false, 'hr' => true]]), $emp, sdAgent(['servicedesk.confidential']), [], ['source' => 'hr']);

    $key = app(ApiKeys::class)->issue('Desk', ['servicedesk.read']);
    auth()->logout();
    actAsTenant(null);
    $api = fn (string $path) => $this->withHeader('X-Api-Key', $key['plaintext'])->getJson('/api/v1/service-desk/'.$path);

    $list = $api('requests')->assertOk();
    expect(collect($list->json('data'))->pluck('number')->all())->not->toContain($restricted->number)
        ->and($list->getContent())->not->toContain('Private subject text')->not->toContain('99887766554433');
    $api('requests/'.$restricted->number)->assertNotFound();
    $api('requests/'.$restricted->number.'/comments')->assertNotFound();
    $api('requests/'.$sensitive->number)->assertOk()->assertJsonPath('data.form', []);
    $api('requests/'.$sensitive->number.'/comments')->assertNotFound();
    $comments = $api('requests/'.$standard->number.'/comments')->assertOk();
    expect(collect($comments->json('data'))->pluck('body')->all())->toBe(['Visible reply'])
        ->and($comments->getContent())->not->toContain('Internal HR remark')->not->toContain('attachment_path');
    $api('services')->assertOk();
    $api('tasks')->assertOk();
    $api('knowledge')->assertOk();
    $this->flushHeaders()->getJson('/api/v1/service-desk/requests')->assertUnauthorized();
});

it('serves attachments only with the case and comment visibility (no IDOR)', function () {
    $userEmp = tenantUser($this->tenant, ['servicedesk.request']);
    $emp = sdHireAt($this->company, $this->delhi, 'Att', null, $userEmp->id);
    $mine = $this->requests->submit($this->service, $emp, $userEmp);
    $other = $this->requests->submit($this->service, $this->delhiEmp, $this->hr, [], ['source' => 'hr']);
    Storage::disk('local')->put('servicedesk/internal.pdf', '%PDF-1.4 internal');
    $internal = app(ServiceDesk::class)->comment($mine, $this->hr, 'Internal file', 'internal', 'servicedesk/internal.pdf', 'internal.pdf');
    $url = app(ServiceDesk::class)->attachmentUrl($internal);

    $this->actingAs($this->hr)->get($url)->assertOk();
    $this->actingAs($userEmp)->get($url)->assertForbidden();
    // The same comment through another ticket's id: not found.
    $forged = URL::temporarySignedRoute('tickets.attachment', now()->addMinutes(5), ['ticket' => $other->id, 'comment' => $internal->id]);
    $this->actingAs($this->hr)->get($forged)->assertNotFound();
});

it('lets auditors read the queue but never work it', function () {
    $auditor = tenantUser($this->tenant, ['servicedesk.view', 'employee.view']);
    $ticket = $this->requests->submit($this->service, $this->delhiEmp, $this->hr, [], ['source' => 'hr']);
    expect(app(CaseAccess::class)->canView($auditor, $ticket))->toBeTrue()
        ->and(app(CaseAccess::class)->canWork($auditor, $ticket))->toBeFalse()
        ->and(fn () => app(ServiceDesk::class)->resolve($ticket, 'x', $auditor))->toThrow(ServiceDeskRuleViolation::class, 'cannot work');
});

it('puts only references in events and notifications — never subjects, descriptions, form data or resolutions', function () {
    $events = [];
    Event::listen(ServiceDeskEvent::class, function (ServiceDeskEvent $e) use (&$events) {
        $events[] = $e;
    });
    $userEmp = tenantUser($this->tenant, ['servicedesk.request']);
    $emp = sdHireAt($this->company, $this->delhi, 'Evt', null, $userEmp->id);
    $ticket = $this->requests->submit($this->service, $emp, $userEmp, [], ['subject' => 'Harassment by my lead', 'description' => 'Long account']);
    app(ServiceDesk::class)->resolve($ticket, 'Spoke to the lead privately', $this->hr);

    $payload = json_encode(array_map(fn ($e) => $e->context, $events));
    expect($payload)->not->toContain('Harassment')->not->toContain('Spoke to the lead')->not->toContain('Long account')
        ->and(collect($events)->pluck('name')->all())->toContain('servicedesk.ticket.created', 'servicedesk.ticket.resolved');
    $notice = (string) data_get($userEmp->notifications()->latest()->first()?->data, 'title');
    expect($notice)->toContain($ticket->number)->not->toContain('Spoke to the lead');
});

it('keeps confidential cases about an employee out of their Needs Attention, the AI assistant and reports', function () {
    $userEmp = tenantUser($this->tenant, ['servicedesk.request', 'ai.use']);
    $emp = sdHireAt($this->company, $this->delhi, 'Hid', null, $userEmp->id);
    $er = sdApprovedService('ER_HIDDEN', ['confidentiality' => 'restricted', 'visible_to_employee' => false, 'availability' => ['employee' => false, 'manager' => false, 'hr' => true]]);
    $case = $this->requests->submit($er, $emp, sdAgent(['servicedesk.confidential']), [], ['source' => 'hr']);
    app(ServiceDesk::class)->waitOnEmployee($case, User::query()->find($case->owner_id));

    $attention = app(NeedsAttention::class)->forEmployee($emp, $userEmp)->pluck('key')->all();
    expect($attention)->not->toContain('tickets');
    $answer = app(EmployeeAssistant::class)->answer($userEmp, $emp, 'what are my open hr requests?');
    expect(json_encode($answer))->not->toContain($case->number);
    $rows = (new ServiceDeskDataset)->query()->pluck('number')->all();
    expect($rows)->not->toContain($case->number);
});
