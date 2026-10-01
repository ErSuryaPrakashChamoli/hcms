<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Workforce\Models\Position;
use App\Domain\Workforce\Models\WorkforceBudget;
use App\Domain\Workforce\Models\WorkforcePlan;
use App\Domain\Workforce\Services\Positions;
use App\Domain\Workforce\Services\WorkforceAccess;
use App\Domain\Workforce\Services\WorkforceBudgets;
use App\Domain\Workforce\Services\WorkforcePlans;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/WorkforceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->approver = tenantUser($this->tenant, ['workforce.view', 'workforce.approve', 'workforce.manage', 'workforce.costs']);
    $this->org = workforceOrg();
    $this->north = Location::factory()->create(['company_id' => $this->org['company']->id, 'name' => 'North']);
    $this->south = Location::factory()->create(['company_id' => $this->org['company']->id, 'name' => 'South']);
    $this->manager = activeEmployee(null, ['workforce.team']);
    $this->report = activeEmployee($this->manager, ['career.self']);
    $this->lead = openPosition(['code' => 'LEAD', 'title' => 'Lead', 'organisation_node_id' => $this->org['department']->id, 'location_id' => $this->north->id], $this->admin, $this->approver, '2026-01-01');
    $this->child = openPosition(['code' => 'CHILD', 'title' => 'Engineer', 'organisation_node_id' => $this->org['department']->id, 'location_id' => $this->north->id, 'parent_position_id' => $this->lead->id], $this->admin, $this->approver, '2026-01-01');
    $this->elsewhere = openPosition(['code' => 'SOUTH', 'title' => 'Analyst', 'organisation_node_id' => $this->org['department']->id, 'location_id' => $this->south->id], $this->admin, $this->approver, '2026-01-01');
    app(AssignPositionAction::class)->handle($this->manager, ['position_id' => $this->lead->id], 'transfer', '2026-02-01', 'Seat');
    app(AssignPositionAction::class)->handle($this->report, ['position_id' => $this->elsewhere->id], 'transfer', '2026-02-01', 'Seat');
});

it('keeps workforce planning from employees by default', function () {
    $user = $this->report->user;
    expect($user->can('viewAny', Position::class))->toBeFalse()
        ->and($user->can('view', $this->elsewhere))->toBeFalse()   // not even the seat they occupy
        ->and($user->can('viewAny', WorkforcePlan::class))->toBeFalse()
        ->and($user->can('viewAny', WorkforceBudget::class))->toBeFalse();
});

it('limits managers to their position subtree and managed employees, never through mentor, buddy or project links', function () {
    $access = app(WorkforceAccess::class);
    expect($access->teamPositionIds($this->manager->user)->sort()->values()->all())->toBe(collect([$this->child->id, $this->elsewhere->id])->sort()->values()->all())
        ->and($this->manager->user->can('view', $this->child))->toBeTrue()
        ->and($this->manager->user->can('view', $this->elsewhere))->toBeTrue()   // held by a managed employee
        ->and($this->manager->user->can('update', $this->child))->toBeFalse()
        ->and($this->manager->user->can('viewAny', WorkforcePlan::class))->toBeFalse();

    foreach (['mentor', 'buddy', 'project'] as $type) {
        $other = activeEmployee(null, ['workforce.team']);
        ReportingRelationship::query()->create(['employee_id' => $this->report->id, 'manager_id' => $other->id, 'type' => $type, 'is_primary' => false, 'effective_from' => '2026-01-01']);
        expect($other->user->can('view', $this->elsewhere))->toBeFalse("{$type} must not see the position");
    }
});

it('applies organisation scope fail-closed at policy and query level, and tenant isolation', function () {
    $scoped = tenantUser($this->tenant, ['workforce.view', 'workforce.manage', 'workforce.plan']);
    app(AccessScopes::class)->assign($scoped, ['location' => [$this->north->id]]);
    $scoped = $scoped->fresh();
    $companyWide = app(Positions::class)->create(['code' => 'CEO', 'title' => 'CEO', 'company_id' => $this->org['company']->id], $this->admin);

    expect($scoped->can('view', $this->lead))->toBeTrue()
        ->and($scoped->can('view', $this->elsewhere))->toBeFalse()
        ->and($scoped->can('view', $companyWide))->toBeFalse();   // no location = not inside a location scope
    $this->actingAs($scoped);
    expect(Position::query()->whereIn('code', ['CHILD', 'LEAD', 'SOUTH', 'CEO'])->pluck('code')->sort()->values()->all())->toBe(['CHILD', 'LEAD'])
        // The services re-check scope too (defence in depth behind the screens).
        ->and(fn () => app(Positions::class)->transition(Position::query()->withoutGlobalScopes([AccessScope::class])->find($this->elsewhere->id), 'frozen', 'x', $scoped))->toThrow(RuntimeException::class, 'outside your organisation scope')
        ->and(fn () => app(Positions::class)->create(['code' => 'S-2', 'title' => 'South analyst', 'company_id' => $this->org['company']->id, 'location_id' => $this->south->id], $scoped))->toThrow(RuntimeException::class, 'outside your organisation scope')
        ->and(app(Positions::class)->create(['code' => 'N-2', 'title' => 'North analyst', 'company_id' => $this->org['company']->id, 'location_id' => $this->north->id], $scoped)->code)->toBe('N-2')
        ->and(fn () => app(WorkforcePlans::class)->create(['code' => 'CO', 'name' => 'Company-wide', 'company_id' => $this->org['company']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $scoped))->toThrow(RuntimeException::class, 'outside your organisation scope');

    $other = provisionTenant();
    actAsTenant($other);
    $foreign = tenantUser($other, ['*']);
    $this->actingAs($foreign);
    expect(Position::query()->count())->toBe(0)->and($foreign->can('view', $this->lead))->toBeFalse();
});

it('keeps costs behind workforce.costs, masks them in the audit trail and leaves no cost in the API without the scope', function () {
    $plans = app(WorkforcePlans::class);
    $planner = tenantUser($this->tenant, ['workforce.view', 'workforce.plan']);
    $plan = $plans->create(['code' => 'P1', 'name' => 'Plan', 'company_id' => $this->org['company']->id, 'period_type' => 'annual', 'period_start' => '2027-01-01', 'period_end' => '2027-12-31'], $planner);
    expect(fn () => $plans->addLine($plan->versions()->first(), ['movement_type' => 'new_position', 'headcount' => 1, 'planned_cost' => 999999, 'cost_basis' => 'annual_salary', 'effective_date' => '2027-02-01'], $planner))->toThrow(RuntimeException::class, 'workforce.costs');
    $plans->addLine($plan->versions()->first(), ['movement_type' => 'new_position', 'headcount' => 1, 'planned_cost' => 777777, 'cost_basis' => 'annual_salary', 'effective_date' => '2027-02-01'], $this->admin);
    $budget = app(WorkforceBudgets::class)->create(['name' => 'B', 'company_id' => $this->org['company']->id, 'period_start' => '2027-01-01', 'period_end' => '2027-12-31', 'cost_basis' => 'employer_cost', 'amount' => 654321], $this->admin);

    expect($planner->can('view', $budget))->toBeFalse()
        ->and(AuditEventChange::query()->whereIn('field', ['amount', 'planned_cost'])->pluck('after')->implode(' '))->not->toContain('654321')->not->toContain('777777');

    $read = app(ApiKeys::class)->issue('BI', ['workforce.read']);
    $costs = app(ApiKeys::class)->issue('Finance', ['workforce.read', 'workforce.costs']);
    $body = $this->withHeaders(['X-Api-Key' => $read['plaintext']])->getJson('/api/v1/workforce/plans/P1/versions/1')->assertOk()->getContent();
    expect($body)->not->toContain('777777')->not->toContain('planned_cost')
        ->and($this->withHeaders(['X-Api-Key' => $costs['plaintext']])->getJson('/api/v1/workforce/plans/P1/versions/1')->json('data.lines.0.planned_cost'))->toEqual(777777);
});

it('serves positions and workforce over the API with separate scopes, codes only and 404 for foreign records', function () {
    $positions = app(ApiKeys::class)->issue('HRIS', ['positions.read']);
    $workforce = app(ApiKeys::class)->issue('BI', ['workforce.read']);
    $key = ['X-Api-Key' => $positions['plaintext']];

    $list = $this->withHeaders($key)->getJson('/api/v1/positions')->assertOk();
    expect($list->json('meta.total'))->toBe(3)
        ->and($this->withHeaders($key)->getJson('/api/v1/positions/LEAD/occupancy')->json('data.occupants.0.employee_code'))->toBe($this->manager->employee_code)
        ->and($this->withHeaders($key)->getJson('/api/v1/positions/LEAD/occupancy')->getContent())->not->toContain('"employee_id"')->not->toContain($this->manager->person->full_name)
        ->and($this->withHeaders($key)->getJson('/api/v1/positions/LEAD?on=2025-06-01')->json('data.status'))->toBeNull()   // not in force then
        ->and($this->withHeaders($key)->getJson('/api/v1/positions/vacancies')->json('data.0.code'))->toBe('CHILD');
    $this->withHeaders($key)->getJson('/api/v1/workforce/headcount')->assertForbidden();
    expect($this->withHeaders(['X-Api-Key' => $workforce['plaintext']])->getJson('/api/v1/workforce/headcount')->json('data.occupied_seats'))->toBe(2);
    $this->withHeaders(['X-Api-Key' => $workforce['plaintext']])->getJson('/api/v1/positions')->assertForbidden();
    $this->flushHeaders()->getJson('/api/v1/positions')->assertUnauthorized();

    $other = provisionTenant();
    actAsTenant($other);
    $foreignAdmin = tenantUser($other, ['*']);
    app(Positions::class)->create(['code' => 'FOREIGN', 'title' => 'Foreign', 'company_id' => Company::factory()->create()->id], $foreignAdmin);
    actAsTenant($this->tenant);
    $this->withHeaders($key)->getJson('/api/v1/positions/FOREIGN')->assertNotFound();
    $this->withHeaders($key)->getJson('/api/v1/positions/FOREIGN/occupancy')->assertNotFound();
});

it('audits position lifecycle moves with actor, reason and effective date, and plan decisions', function () {
    app(Positions::class)->transition($this->child, 'frozen', 'Budget review', $this->admin, '2026-11-01');
    $event = AuditEvent::query()->where('entity_type', Position::class)->where('entity_id', (string) $this->child->id)->where('action', 'STATUS_CHANGE')->latest('occurred_at')->first();

    expect($event)->not->toBeNull()->and($event->reason)->toBe('Budget review')
        ->and($event->effective_date?->toDateString())->toBe('2026-11-01')
        ->and($event->actor_id)->toBe($this->admin->id)
        ->and($event->metadata['event'])->toBe('position_frozen')
        ->and($event->fieldChanges->first()->after)->toBe('frozen');
});
