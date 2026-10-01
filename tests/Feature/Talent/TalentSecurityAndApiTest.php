<?php

use App\Domain\Career\Services\CareerProfiles;
use App\Domain\Employment\Actions\AssignPositionAction;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\Succession\Models\ReadinessAssessment;
use App\Domain\Succession\Models\SuccessionPlan;
use App\Domain\Succession\Models\Successor;
use App\Domain\Succession\Services\CriticalPositions;
use App\Domain\Succession\Services\Readiness;
use App\Domain\Succession\Services\SuccessionPlans;
use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Models\TalentProfile;
use App\Domain\Talent\Services\TalentAnalytics;
use App\Domain\Talent\Services\TalentAssessments;
use App\Domain\Talent\Services\TalentPools;
use App\Domain\Talent\Services\TalentReviews;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->admin);
    $this->head = Designation::query()->create(['name' => 'Plant Head', 'code' => 'PHEAD']);
    $this->incumbent = activeEmployee(null, ['career.self']);
    app(AssignPositionAction::class)->handle($this->incumbent, ['designation_id' => $this->head->id], 'promotion', '2026-04-01', 'Appointed');
    $this->manager = activeEmployee(null, ['career.self', 'career.team', 'succession.team']);
    $this->candidate = activeEmployee($this->manager, ['career.self']);
    $this->colleague = activeEmployee(null, ['career.self']);
    $this->hr = activeEmployee(null, ['career.view', 'talent.view', 'talent.manage', 'talent.assess', 'talent.confidential', 'succession.view', 'succession.manage', 'succession.assess', 'development.manage']);

    $assessment = ['criticality' => 'critical', 'business_impact' => 'high', 'scarcity' => 'high', 'replacement_difficulty' => 'high', 'operational_dependency' => 'high', 'reason' => 'Single plant head'];
    $this->position = app(CriticalPositions::class)->designate($this->head, null, 'Plant Head', $assessment, 12, $this->hr->user);
    $this->plan = app(SuccessionPlans::class)->create($this->position, ['vacancy_risk' => 'high', 'confidential_notes' => 'Board succession memo'], $this->hr->user);
    $this->successor = app(SuccessionPlans::class)->addSuccessor($this->plan, $this->candidate, 'Strong operator', 'Needs finance depth', 'Candidate under NDA', $this->hr->user);
    $this->readiness = app(Readiness::class)->assess($this->candidate, $this->position->id, null, 'lt_1_year', 'Finance rotation pending', 'Panel notes', $this->hr->user, $this->successor);
    $this->pool = TalentPool::create(['code' => 'OPS', 'name' => 'Operations leaders']);
    $this->membership = app(TalentPools::class)->add($this->pool, $this->candidate, 'Ops leadership potential', $this->hr->user);
    app(TalentAssessments::class)->updateProfile($this->candidate, ['confidential_notes' => 'Health matter disclosed in confidence'], null, $this->hr->user);
});

it('never shows candidacy, pool membership, readiness or talent profiles to the employee themself', function () {
    $user = $this->candidate->user;
    $profile = TalentProfile::query()->where('employee_id', $this->candidate->id)->firstOrFail();

    expect($user->can('view', $this->successor))->toBeFalse()
        ->and($user->can('view', $this->readiness))->toBeFalse()
        ->and($user->can('view', $this->membership))->toBeFalse()
        ->and($user->can('view', $profile))->toBeFalse()
        ->and($user->can('view', $this->plan))->toBeFalse()
        ->and($user->can('viewAny', Successor::class))->toBeFalse();
});

it('limits managers to configured relationships and never grants mentors, buddies or project leads', function () {
    expect($this->manager->user->can('view', $this->successor))->toBeTrue()
        ->and($this->manager->user->can('view', $this->readiness))->toBeTrue()
        ->and($this->manager->user->can('update', $this->successor))->toBeFalse()
        // Talent records are not visible by relationship alone.
        ->and($this->manager->user->can('view', $this->membership))->toBeFalse();

    foreach (['mentor', 'buddy', 'project'] as $type) {
        $other = activeEmployee(null, ['succession.team', 'career.team', 'talent.review']);
        ReportingRelationship::query()->create(['employee_id' => $this->candidate->id, 'manager_id' => $other->id, 'type' => $type, 'is_primary' => false, 'effective_from' => '2026-01-01']);
        expect($other->user->can('view', $this->successor))->toBeFalse("{$type} must not see candidacy")
            ->and($other->user->can('view', $this->readiness))->toBeFalse()
            ->and($other->user->can('view', $this->membership))->toBeFalse();
    }
    expect($this->colleague->user->can('view', $this->successor))->toBeFalse();
});

it('limits HR to their organisation scope at policy and query level', function () {
    $scoped = tenantUser($this->tenant, ['talent.view', 'talent.manage', 'succession.view', 'succession.manage']);
    expect($scoped->can('view', $this->successor))->toBeTrue()->and($scoped->can('view', $this->membership))->toBeTrue();

    app(AccessScopes::class)->assign($scoped, ['location' => [Location::factory()->create(['company_id' => Company::query()->value('id')])->id]]);
    $scoped = $scoped->fresh();
    expect($scoped->can('view', $this->successor))->toBeFalse()
        ->and($scoped->can('view', $this->membership))->toBeFalse()
        ->and($scoped->can('update', $this->readiness))->toBeFalse();
    $this->actingAs($scoped);
    expect(Successor::query()->count())->toBe(0)
        ->and(TalentPoolMembership::query()->count())->toBe(0)
        ->and(ReadinessAssessment::query()->count())->toBe(0)
        ->and(fn () => app(SuccessionPlans::class)->addSuccessor($this->plan, $this->colleague, null, null, null, $scoped))->toThrow(RuntimeException::class, 'organisation scope');

    // Another tenant never reaches these rows.
    $other = provisionTenant();
    actAsTenant($other);
    $foreign = tenantUser($other, ['*']);
    $this->actingAs($foreign);
    expect(SuccessionPlan::query()->count())->toBe(0)->and(Successor::query()->count())->toBe(0)->and($foreign->can('view', $this->successor))->toBeFalse();
});

it('rejects bulk operations by people without the permission and skips out-of-scope employees', function () {
    $intruder = activeEmployee(null, ['talent.view']);
    $result = app(TalentPools::class)->addMany($this->pool, [$this->colleague->id, $this->incumbent->id], 'Bulk', $intruder->user);
    expect($result['added'])->toBe(0)->and($result['skipped'])->toHaveCount(2)
        ->and(fn () => app(TalentReviews::class)->create('Shadow review', null, [], $intruder->user))->toThrow(RuntimeException::class, 'talent.manage');
});

it('keeps confidential fields out of models, arrays and every API response', function () {
    $keys = app(ApiKeys::class);
    $career = $keys->issue('HRIS', ['career.read']);
    $talent = $keys->issue('Talent BI', ['talent.read']);
    $succession = $keys->issue('Board BI', ['succession.read']);
    app(CareerProfiles::class)->recordAspiration($this->candidate, 'long', ['aspiration' => 'Secret wish to run the plant'], $this->candidate->user);
    app(CareerProfiles::class)->addMobilityInterest($this->candidate, 'location', ['location_id' => Location::factory()->create(['company_id' => Company::query()->value('id')])->id, 'notes' => 'Spouse transfer'], $this->candidate->user);

    expect($this->successor->toArray())->not->toHaveKey('confidential_notes')->and($this->plan->toArray())->not->toHaveKey('confidential_notes');

    $bodies = collect([
        [$career, ['tracks', 'paths', 'role-requirements', 'profiles', 'goals', 'mobility-interests', "movements?employee_code={$this->candidate->employee_code}"], 'career'],
        [$talent, ['pools', 'memberships', 'reviews', 'analytics'], 'talent'],
        [$succession, ['critical-positions', 'plans', 'successors', 'readiness', 'analytics', "plans/{$this->plan->id}", "critical-positions/{$this->position->id}"], 'succession'],
    ])->flatMap(fn ($set) => collect($set[1])->map(fn ($path) => $this->withHeaders(['X-Api-Key' => $set[0]['plaintext']])->getJson("/api/v1/{$set[2]}/{$path}")->assertOk()->getContent()))->implode("\n");

    foreach (['Board succession memo', 'Candidate under NDA', 'Strong operator', 'Needs finance depth', 'Finance rotation pending', 'Panel notes', 'Ops leadership potential', 'Health matter', 'Secret wish', 'Spouse transfer', 'Appointed', 'confidential_notes', '"employee_id"'] as $secret) {
        expect($bodies)->not->toContain($secret);
    }
    // Mobility is not shared with anyone until the employee opts in.
    expect($this->withHeaders(['X-Api-Key' => $career['plaintext']])->getJson('/api/v1/career/mobility-interests')->json('meta.total'))->toBe(0)
        ->and($this->withHeaders(['X-Api-Key' => $succession['plaintext']])->getJson('/api/v1/succession/successors')->json('data.0.employee_code'))->toBe($this->candidate->employee_code);
});

it('enforces API scopes and resolves foreign ids to 404', function () {
    $talent = app(ApiKeys::class)->issue('Talent BI', ['talent.read']);
    $succession = app(ApiKeys::class)->issue('Board BI', ['succession.read']);
    $this->withHeaders(['X-Api-Key' => $talent['plaintext']])->getJson('/api/v1/succession/plans')->assertForbidden();
    $this->withHeaders(['X-Api-Key' => $succession['plaintext']])->getJson('/api/v1/talent/pools')->assertForbidden();
    $this->withHeaders(['X-Api-Key' => $succession['plaintext']])->getJson('/api/v1/career/goals')->assertForbidden();
    $this->flushHeaders()->getJson('/api/v1/succession/plans')->assertUnauthorized();

    $other = provisionTenant();
    actAsTenant($other);
    $foreignAdmin = tenantUser($other, ['*']);
    $designation = Designation::query()->create(['name' => 'Foreign', 'code' => 'FOR']);
    $foreignPosition = app(CriticalPositions::class)->designate($designation, null, 'Foreign', ['criticality' => 'low', 'business_impact' => 'low', 'scarcity' => 'low', 'replacement_difficulty' => 'low', 'operational_dependency' => 'low', 'reason' => 'x'], 12, $foreignAdmin);
    $foreignPlan = app(SuccessionPlans::class)->create($foreignPosition, [], $foreignAdmin);
    $foreignEmployee = activeEmployee();
    actAsTenant($this->tenant);

    $this->withHeaders(['X-Api-Key' => $succession['plaintext']])->getJson("/api/v1/succession/plans/{$foreignPlan->id}")->assertNotFound();
    $this->withHeaders(['X-Api-Key' => $succession['plaintext']])->getJson("/api/v1/succession/critical-positions/{$foreignPosition->id}")->assertNotFound();
    expect($this->withHeaders(['X-Api-Key' => $succession['plaintext']])->getJson("/api/v1/succession/successors?employee_code={$foreignEmployee->employee_code}")->json('meta.total'))->toBe(0);
});

it('reports factual coverage and suppresses small people counts in analytics', function () {
    $analytics = app(TalentAnalytics::class);
    $summary = $analytics->summary();

    expect($summary['critical_positions']['active'])->toBe(1)
        ->and($summary['succession']['with_successors'])->toBe(1)
        ->and($summary['succession']['with_ready_now'])->toBe(0)
        ->and($summary['succession']['without_ready_now'])->toBe(['Plant Head'])
        ->and($summary['pools'][0])->toBe(['pool' => 'Operations leaders', 'suppressed' => true])
        ->and(json_encode($summary))->not->toContain('flight', 'promot', 'rank', 'score');

    app(Readiness::class)->assess($this->candidate, $this->position->id, null, 'ready_now', 'Rotation complete', null, $this->hr->user, $this->successor);
    config(['peopleos.talent.analytics_min_group' => 1]);
    $coverage = app(TalentAnalytics::class)->coverage();
    expect($coverage[0])->toMatchArray(['title' => 'Plant Head', 'criticality' => 'critical', 'open_plan' => true, 'successors' => 1, 'ready_now' => 1, 'incumbent_exit_on' => null])
        ->and(app(TalentAnalytics::class)->summary()['pools'][0])->toBe(['pool' => 'Operations leaders', 'count' => 1, 'suppressed' => false]);
});
