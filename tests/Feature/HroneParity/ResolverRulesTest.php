<?php

use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Models\ServiceResolverRule;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Services\CaseAssignment;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use App\Domain\ServiceDesk\Services\ServiceRequests;
use App\Domain\ServiceDesk\Services\SlaCalendars;
use App\Filament\Resources\ServiceResolverRules\Pages\CreateServiceResolverRule;
use App\Filament\Resources\ServiceResolverRules\ServiceResolverRuleResource;
use App\Filament\Resources\ServiceSlaPolicies\Pages\CreateServiceSlaPolicy;
use App\Filament\Resources\ServiceSlaPolicies\ServiceSlaPolicyResource;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../ServiceDesk/ServiceDeskTestHelpers.php';

/* HROne setup parity HD-B — Helpdesk resolver rules: routing by category, service, company and location with a
 * resolving role, a default resolver, an intimation role and the SLA policy (service days, working hours) of the rule. */

beforeEach(function () {
    $this->travelTo('2026-09-21 10:00:00'); // a Monday
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->employee = activeEmployee(null, ['servicedesk.request', 'task.view']);
    $this->payroll = TicketCategory::query()->where('code', 'PAYROLL')->firstOrFail();
    $this->other = TicketCategory::query()->where('code', 'OTHER')->firstOrFail();
    $this->defaultAgent = sdAgent();
    $this->defaultTeam = sdTeam($this->defaultAgent);
    $this->payroll->update(['assignee_role_id' => $this->defaultTeam->id]);
    $this->other->update(['assignee_role_id' => $this->defaultTeam->id]);
    $this->payrollAgent = sdAgent();
    $this->payrollTeam = sdTeam($this->payrollAgent);
    $this->generalAgent = sdAgent();
    $this->generalTeam = sdTeam($this->generalAgent);
});

function svcAskHr(TicketCategory $category, $employee)
{
    return app(ServiceDesk::class)->open($employee, $category->refresh(), 'A question', 'Details', 'normal', $employee->user);
}

function svcRule(array $attributes): ServiceResolverRule
{
    return ServiceResolverRule::query()->create($attributes + ['name' => 'Rule '.uniqid(), 'effective_from' => '2026-01-01']);
}

it('routes as before when no resolver rule matches', function () {
    svcRule(['assignee_role_id' => $this->payrollTeam->id, 'location_ids' => [Location::factory()->create()->id]]);
    svcRule(['assignee_role_id' => $this->payrollTeam->id, 'status' => 'inactive']);
    svcRule(['assignee_role_id' => $this->payrollTeam->id, 'effective_from' => '2026-10-01']);

    $ticket = svcAskHr($this->payroll, $this->employee);
    expect($ticket->resolver_rule_id)->toBeNull()->and($ticket->assigned_role_id)->toBe($this->defaultTeam->id)->and($ticket->assignee_id)->toBe($this->defaultAgent->id)
        ->and($ticket->sla_policy_id)->toBeNull()->and($ticket->sla_mode)->toBe('calendar');
});

it('picks the lowest priority number, then the most specific rule, and routes to its role or default resolver', function () {
    $company = Company::query()->orderBy('id')->first();
    $any = svcRule(['name' => 'Everything else', 'assignee_role_id' => $this->generalTeam->id]);
    $payroll = svcRule(['name' => 'Payroll desk', 'category_ids' => [$this->payroll->id], 'company_ids' => [$company->id], 'assignee_role_id' => $this->payrollTeam->id]);

    $first = svcAskHr($this->payroll, $this->employee);
    expect($first->resolver_rule_id)->toBe($payroll->id)->and($first->assigned_role_id)->toBe($this->payrollTeam->id)->and($first->assignee_id)->toBe($this->payrollAgent->id);
    $second = svcAskHr($this->other, $this->employee);
    expect($second->resolver_rule_id)->toBe($any->id)->and($second->assignee_id)->toBe($this->generalAgent->id);

    // A named default resolver in the role takes it; a lower priority number beats specificity.
    $lead = sdAgent();
    $this->payrollTeam->users()->attach($lead);
    $payroll->update(['assignee_user_id' => $lead->id]);
    expect(svcAskHr($this->payroll, $this->employee)->assignee_id)->toBe($lead->id);
    $urgent = svcRule(['name' => 'Everything first', 'priority' => 10, 'assignee_role_id' => $this->generalTeam->id]);
    expect(svcAskHr($this->payroll, $this->employee)->resolver_rule_id)->toBe($urgent->id);

    // Catalogue services (sub-categories) match on the service; an ineligible default resolver is skipped.
    $service = sdApprovedService('PAYSLIP_COPY', ['assignment' => ['role_id' => $this->defaultTeam->id]], 'PAYROLL');
    $byService = svcRule(['name' => 'Payslip copies', 'priority' => 10, 'service_ids' => [$service->id], 'assignee_role_id' => $this->payrollTeam->id, 'assignee_user_id' => tenantUser($this->tenant, ['task.view'])->id]);
    $request = app(ServiceRequests::class)->submit($service, $this->employee, $this->employee->user);
    expect($request->resolver_rule_id)->toBe($byService->id)->and($request->assigned_role_id)->toBe($this->payrollTeam->id)
        ->and($request->assignee_id)->toBeIn([$this->payrollAgent->id, $lead->id]);
});

it('applies the rule\'s SLA policy with its own service days and working hours', function () {
    $policy = ServiceSlaPolicy::query()->create(['code' => 'DESK', 'name' => 'Desk hours', 'calendar' => 'business', 'effective_from' => '2026-01-01',
        'business_hours' => ['days' => ['3', '1', '2'], 'start' => '10:00:00', 'end' => '14:00'],
        'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 6]]]);
    expect($policy->business_hours)->toBe(['days' => [1, 2, 3], 'start' => '10:00', 'end' => '14:00']);

    // Monday 10:00 + 6 service hours of 10:00–14:00 = Tuesday 12:00 (no employee: no holidays, the app timezone).
    expect(app(SlaCalendars::class)->for(null, $policy)->addMinutes(Carbon::parse('2026-09-21 10:00:00'), 360)->toDateTimeString())->toBe('2026-09-22 12:00:00')
        // Thursday is not a service day: Wednesday 12:00 + 4 h runs into the next Monday.
        ->and(app(SlaCalendars::class)->for(null, $policy)->addMinutes(Carbon::parse('2026-09-23 12:00:00'), 240)->toDateTimeString())->toBe('2026-09-28 12:00:00');

    $rule = svcRule(['category_ids' => [$this->payroll->id], 'assignee_role_id' => $this->payrollTeam->id, 'sla_policy_id' => $policy->id]);
    $ticket = svcAskHr($this->payroll, $this->employee);
    $expected = app(SlaCalendars::class)->for($this->employee, $policy)->addMinutes(Carbon::parse('2026-09-21 10:00:00'), 360);
    expect($ticket->resolver_rule_id)->toBe($rule->id)->and($ticket->sla_policy_id)->toBe($policy->id)->and($ticket->sla_mode)->toBe('business')
        ->and($ticket->due_at->toDateTimeString())->toBe($expected->toDateTimeString());

    expect(fn () => ServiceSlaPolicy::query()->create(['code' => 'BAD', 'name' => 'Bad', 'calendar' => 'business', 'effective_from' => '2026-01-01', 'business_hours' => ['days' => [], 'start' => '09:00', 'end' => '18:00'], 'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 6]]]))
        ->toThrow(RuntimeException::class, 'at least one working day');
    expect(fn () => ServiceSlaPolicy::query()->create(['code' => 'BAD2', 'name' => 'Bad', 'calendar' => 'business', 'effective_from' => '2026-01-01', 'business_hours' => ['days' => [1], 'start' => '18:00', 'end' => '09:00'], 'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 6]]]))
        ->toThrow(RuntimeException::class, 'end after the start');
    expect(ServiceSlaPolicy::query()->create(['code' => 'DEF', 'name' => 'Default hours', 'calendar' => 'business', 'effective_from' => '2026-01-01', 'business_hours' => ['days' => [], 'start' => null, 'end' => null], 'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 6]]])->business_hours)->toBeNull();
});

it('intimates the rule\'s role when a request is assigned, only members who may see it', function () {
    $watcher = sdAgent();
    $outsider = tenantUser($this->tenant, ['task.view']);
    $role = Role::factory()->create(['name' => 'Payroll leads']);
    $role->users()->attach([$watcher->id, $outsider->id]);
    svcRule(['category_ids' => [$this->payroll->id], 'assignee_role_id' => $this->payrollTeam->id, 'notify_role_id' => $role->id]);

    $ticket = svcAskHr($this->payroll, $this->employee);
    $title = (string) data_get($watcher->notifications()->latest()->first()?->data, 'title');
    expect($title)->toContain('HR request assigned')->toContain($ticket->number)
        ->and($outsider->notifications()->count())->toBe(0);

    // A later reassignment tells them again.
    $before = $watcher->notifications()->count();
    app(CaseAssignment::class)->assign($ticket->refresh(), $this->defaultAgent, $this->payrollAgent);
    expect($watcher->notifications()->count())->toBe($before + 1)->and($outsider->notifications()->count())->toBe(0);
});

it('validates rules and offers the rule screens to service desk managers, read-only to viewers', function () {
    expect(fn () => svcRule(['assignee_role_id' => null]))->toThrow(RuntimeException::class, 'Choose the role')
        ->and(fn () => svcRule(['assignee_role_id' => $this->payrollTeam->id, 'effective_from' => '2026-09-01', 'effective_to' => '2026-08-01']))->toThrow(RuntimeException::class, 'cannot end before');

    Livewire::test(CreateServiceResolverRule::class)
        ->fillForm(['name' => 'Payroll desk', 'priority' => 20, 'category_ids' => [$this->payroll->id], 'assignee_role_id' => $this->payrollTeam->id, 'status' => 'active', 'effective_from' => '2026-09-01'])
        ->call('create')->assertHasNoFormErrors();
    $rule = ServiceResolverRule::query()->where('name', 'Payroll desk')->firstOrFail();
    expect($rule->category_ids)->toBe([$this->payroll->id])->and($rule->priority)->toBe(20);

    $policy = ServiceSlaPolicy::query()->create(['code' => 'SAT', 'name' => 'Six days', 'calendar' => 'business', 'effective_from' => '2026-01-01',
        'business_hours' => ['days' => [1, 2, 3, 4, 5, 6], 'start' => '09:30', 'end' => '17:30'], 'targets' => ['normal' => ['first_response_hours' => 1, 'resolution_hours' => 8]]]);
    $rule->update(['sla_policy_id' => $policy->id]);
    $this->get(ServiceResolverRuleResource::getUrl('view', ['record' => $rule]))->assertOk()
        ->assertSee('Resolver will not resolve tickets on')->assertSee('Sunday, and holidays')->assertSee('09:30 – 17:30');
    $this->get(ServiceResolverRuleResource::getUrl('index'))->assertOk()->assertSee('Payroll desk');

    Livewire::test(CreateServiceSlaPolicy::class)
        ->fillForm(['name' => 'Weekend desk', 'code' => 'WKND', 'calendar' => 'business', 'effective_from' => '2026-09-01', 'warn_percent' => 75, 'escalation_repeat_hours' => 24, 'max_escalation_level' => 3, 'status' => 'active',
            'business_hours' => ['days' => ['6', '7'], 'start' => '10:00', 'end' => '16:00'],
            'targets' => collect(config('peopleos.servicedesk.priorities'))->map(fn () => ['first_response_hours' => 1, 'resolution_hours' => 6])->all()])
        ->call('create')->assertHasNoFormErrors();
    expect(ServiceSlaPolicy::query()->where('code', 'WKND')->value('business_hours'))->toBe(['days' => [6, 7], 'start' => '10:00', 'end' => '16:00']);
    expect(ServiceSlaPolicyResource::serviceDayHours('business', '10:00', '16:00'))->toBe(6.0)->and(ServiceSlaPolicyResource::serviceDayHours('calendar', null, null))->toBe(24.0);

    $viewer = tenantUser($this->tenant, ['servicedesk.view']);
    $this->actingAs($viewer);
    $this->get(ServiceResolverRuleResource::getUrl('index'))->assertOk();
    $this->get(ServiceResolverRuleResource::getUrl('create'))->assertForbidden();
    $this->actingAs($this->employee->user);
    $this->get(ServiceResolverRuleResource::getUrl('index'))->assertForbidden();
});
