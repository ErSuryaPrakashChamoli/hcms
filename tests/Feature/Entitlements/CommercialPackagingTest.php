<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Enums\DecisionReason as R;
use App\Domain\Entitlements\Enums\DecisionSource as S;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Domain\Entitlements\Support\Decision;
use App\Domain\Entitlements\Support\PlanValues;
use App\Filament\Pages\PlatformEntitlementsPage;
use App\Filament\Pages\PlatformPlansPage;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/PlanTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
| SaaS.5: commercial packaging and the limit model. What a plan version contains (capabilities, features, limits) is
| described with the code-owned catalogue; limits keep their states apart (unlimited, not included, not set, unknown,
| unmeasured, within, exceeded); a published version is a consistent package; limit changes are new versions and
| never move a tenant on their own; no price is part of a plan; nothing here touches authorisation. Fictional plans.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->catalog = app(PlanCatalog::class);
    $this->config = app(EntitlementConfiguration::class);
    actAsTenant($this->tenant);
});

function limitDecision(Capability $capability, ?string $day = null, ?int $usage = null): Decision
{
    return app(Entitlements::class)->evaluate($capability, $day, $usage);
}

it('describes every SaaS.3 limit from the one catalogue: key, unit, module, measurement, minimum, whether it can be not included', function () {
    $limits = collect(Capability::cases())->filter(fn (Capability $c) => $c->type() === CapabilityType::Limit)
        ->mapWithKeys(fn (Capability $c) => [$c->value => [$c->unit(), $c->module()->value, $c->measured(), $c->minimumLimit(), $c->followsModule()]])->all();

    expect($limits)->toBe([
        'active_employees.max' => ['employees', 'core', true, 1, false],
        'users.max' => ['users', 'core', false, 0, false],
        'admin_users.max' => ['users', 'core', false, 0, false],
        'legal_entities.max' => ['legal entities', 'core', false, 0, false],
        'locations.max' => ['locations', 'core', false, 0, false],
        'storage_bytes.max' => ['bytes', 'core', false, 0, false],
        'api_requests_monthly.max' => ['requests per month', 'integrations', false, 0, true],
        'ai_requests_monthly.max' => ['requests per month', 'ai', false, 0, true],
    ]);
    // Modules and features are not limits: no unit, no minimum, never "not included" as a limit.
    expect(collect(Capability::cases())->reject(fn (Capability $c) => $c->type() === CapabilityType::Limit)
        ->every(fn (Capability $c) => $c->unit() === null && $c->minimumLimit() === null && ! $c->followsModule() && ! $c->measured()))->toBeTrue();
});

it('keeps the limit states apart: unlimited, not included, not set, no plan, unmeasured, within and exceeded', function () {
    $full = publishedPlan($this->operator, 'full', ['leave' => true, 'ai' => true, 'integrations' => true, 'active_employees.max' => 50, 'users.max' => null,
        'ai_requests_monthly.max' => 1000, 'api_requests_monthly.max' => null], '2027-01-04');
    $lean = publishedPlan($this->operator, 'lean', ['leave' => true, 'active_employees.max' => 50], '2027-01-04');
    $this->config->assignPlan($this->tenant, $full, '2027-01-04', null, 'Full package', $this->operator);
    $this->config->assignPlan($this->tenant, $lean, '2027-01-05', null, 'Lean package from tomorrow', $this->operator);
    $pair = fn (Decision $d) => [$d->outcome, $d->reason];

    $states = [
        'unlimited' => $pair(limitDecision(Capability::UsersMax, usage: 10_000)),
        'not included' => $pair(limitDecision(Capability::AiRequestsMonthlyMax, '2027-01-05', 0)),
        'not set' => $pair(limitDecision(Capability::LocationsMax)),
        'unmeasured' => $pair(limitDecision(Capability::AiRequestsMonthlyMax)),
        'within' => $pair(limitDecision(Capability::ActiveEmployeesMax, usage: 50)),
        'exceeded' => $pair(limitDecision(Capability::ActiveEmployeesMax, usage: 51)),
        'no plan' => app(TenantContext::class)->runAs(provisionTenant('Beta'), fn () => $pair(limitDecision(Capability::UsersMax, usage: 1))),
    ];
    expect($states)->toBe([
        'unlimited' => [O::Allow, R::Unlimited],
        'not included' => [O::Deny, R::ModuleNotEntitled],
        'not set' => [O::Unknown, R::LimitNotConfigured],
        'unmeasured' => [O::Unknown, R::UsageUnavailable],
        'within' => [O::Allow, R::WithinLimit],
        'exceeded' => [O::Deny, R::LimitExceeded],
        'no plan' => [O::Unknown, R::TenantUnconfigured],
    ])->and(collect($states)->unique(fn ($p) => $p[0]->value.$p[1]->value))->toHaveCount(7);

    // "Not included" names the layer that leaves the module out, and holds whatever value the limit itself has.
    $notIncluded = limitDecision(Capability::ApiRequestsMonthlyMax, '2027-01-05', 1);
    expect([$notIncluded->source, $notIncluded->planVersionId, $notIncluded->limit])->toBe([S::Plan, $lean->id, null]);
    // An override that grants the module brings the limit back to its own answer (no agreed limit on the lean plan).
    $this->config->grantOverride($this->tenant, Capability::Ai, true, '2027-01-05', null, 'AI pilot on the lean plan', $this->operator);
    expect($pair(limitDecision(Capability::AiRequestsMonthlyMax, '2027-01-05', 10)))->toBe([O::Unknown, R::LimitNotConfigured]);
    // A core limit is never "not included".
    expect($pair(limitDecision(Capability::ActiveEmployeesMax, '2027-01-05', 10)))->toBe([O::Allow, R::WithinLimit]);
});

it('validates plan limit values against the catalogue', function () {
    $draft = $this->catalog->create('custom', 'Custom', null, 'Enterprise deal', $this->operator)->versions->first();
    foreach ([
        [['no_such.max' => 5], 'not a capability'],
        [['users.max' => true], 'whole number'],
        [['users.max' => -1], 'whole number'],
        [['active_employees.max' => 0], 'protected'],
        [['leave' => 10], 'included or not'],
    ] as [$values, $message]) {
        expect(fn () => $this->catalog->define($draft, $values, 'Invalid limit', $this->operator))->toThrow(RuntimeException::class, $message);
    }
    $this->catalog->define($draft, ['ai' => true, 'users.max' => 0, 'storage_bytes.max' => PHP_INT_MAX, 'active_employees.max' => 1, 'ai_requests_monthly.max' => null], 'Valid limits', $this->operator);
    expect($this->catalog->values($draft))->toEqual(['ai' => true, 'users.max' => 0, 'storage_bytes.max' => PHP_INT_MAX, 'active_employees.max' => 1, 'ai_requests_monthly.max' => null]);
});

it('publishes only consistent packages: a feature brings its module, a module limit brings its module', function () {
    $draft = $this->catalog->create('mixed', 'Mixed', null, 'Packaging check', $this->operator)->versions->first();
    $this->catalog->define($draft, ['leave' => true, 'ai.external_model' => true, 'api_requests_monthly.max' => 5000, 'integrations.webhooks' => false], 'Draft in progress', $this->operator);

    expect(PlanCatalog::problems($this->catalog->values($draft)))->toBe([
        'ai.external_model is included but its module ai is not',
        'api_requests_monthly.max is set but its module integrations is not included',
    ])->and(fn () => $this->catalog->publish($draft, '2027-01-04', 'Too early', $this->operator))->toThrow(RuntimeException::class, 'Not a consistent package');

    // The page says why before anyone tries.
    actAsTenant(null);
    $this->actingAs($this->operator);
    $this->get(PlatformPlansPage::getUrl(['plan' => $draft->plan_id]))->assertOk()->assertSee('not yet a consistent package')->assertSee('its module ai is not');

    $this->catalog->set($draft, Capability::Ai, true, 'Add the AI module', $this->operator);
    $this->catalog->set($draft, Capability::Integrations, true, 'Add the integrations module', $this->operator);
    expect(PlanCatalog::problems($this->catalog->values($draft)))->toBe([])
        ->and($this->catalog->publish($draft, '2027-01-04', 'Consistent now', $this->operator)->status->value)->toBe('published');
});

it('versions limits: a change is a new version, a pinned tenant keeps its limit, only an explicit assignment moves it', function () {
    $v1 = publishedPlan($this->operator, 'growth', ['leave' => true, 'active_employees.max' => 50], '2027-01-04');
    $this->config->assignPlan($this->tenant, $v1, '2027-01-04', null, 'Growth v1', $this->operator);
    $v2 = $this->catalog->draft($v1->plan, 'Raise the limit', $this->operator);
    $this->catalog->set($v2, Capability::ActiveEmployeesMax, 100, 'Raise to 100', $this->operator);
    $this->catalog->publish($v2, '2027-01-05', 'Growth v2', $this->operator);

    expect(fn () => $this->catalog->set($v1->fresh(), Capability::ActiveEmployeesMax, 75, 'Edit a published limit', $this->operator))->toThrow(RuntimeException::class, 'never change')
        ->and(limitDecision(Capability::ActiveEmployeesMax, '2027-06-01', 75)->outcome)->toBe(O::Deny)   // still pinned to v1 (50)
        ->and(limitDecision(Capability::ActiveEmployeesMax, '2027-06-01', 75)->limit)->toBe(50)
        ->and(TenantPlanAssignment::query()->count())->toBe(1);                                          // nothing moved on its own

    $this->travelTo('2027-02-01 09:00:00');
    $this->config->assignPlan($this->tenant, $v2->fresh(), '2027-02-01', null, 'Moved to v2 at renewal', $this->operator);
    expect(limitDecision(Capability::ActiveEmployeesMax, '2027-01-20', 75)->limit)->toBe(50)            // history kept
        ->and(limitDecision(Capability::ActiveEmployeesMax, '2027-02-01', 75)->outcome)->toBe(O::Allow)
        ->and(limitDecision(Capability::ActiveEmployeesMax, '2027-02-01', 75)->limit)->toBe(100);
});

it('labels plan content in the engine\'s vocabulary on the pages and in the console', function () {
    $content = ['leave' => true, 'ai' => false, 'active_employees.max' => 1200, 'users.max' => null, 'storage_bytes.max' => 10 * 1024 ** 3, 'api_requests_monthly.max' => 100];
    expect(collect([Capability::Leave, Capability::Ai, Capability::Payroll, Capability::ActiveEmployeesMax, Capability::UsersMax, Capability::LocationsMax,
        Capability::StorageBytesMax, Capability::AiRequestsMonthlyMax, Capability::ApiRequestsMonthlyMax, Capability::Core])
        ->mapWithKeys(fn (Capability $c) => [$c->value => PlanValues::label($c, $content)])->all())->toBe([
            'leave' => 'included',
            'ai' => 'excluded',
            'payroll' => 'not in plan',
            'active_employees.max' => '1,200 employees',
            'users.max' => 'unlimited',
            'locations.max' => 'not set (no agreed limit)',
            'storage_bytes.max' => '10 GiB (10,737,418,240 bytes)',
            'ai_requests_monthly.max' => 'not included (ai not in plan)',
            'api_requests_monthly.max' => 'not included (integrations not in plan)',
            'core' => '',
        ]);

    $plan = publishedPlan($this->operator, 'starter', ['leave' => true, 'active_employees.max' => 25], '2027-01-04');
    $this->config->assignPlan($this->tenant, $plan, '2027-01-04', null, 'Starter', $this->operator);
    $this->artisan('peopleos:entitlements:explain', ['tenant' => $this->tenant->slug])
        ->expectsOutputToContain('not set (no agreed limit)')->expectsOutputToContain('25 employees')->expectsOutputToContain('not included (ai not in plan)')->assertSuccessful();

    actAsTenant(null);
    $this->actingAs($this->operator);
    $this->get(PlatformPlansPage::getUrl(['plan' => $plan->plan_id]))->assertOk()
        ->assertSee('25 employees')->assertSee('not set (no agreed limit)')->assertSee('not included (ai not in plan)')->assertSee('not measured yet')->assertSee('employees · measured')
        ->assertSee('No price is part of a plan')->assertDontSee('₹');
    $this->get(PlatformEntitlementsPage::getUrl(['tenant' => $this->tenant->id]))->assertOk()->assertSee('25 employees')->assertSee('not set (no agreed limit)');
});

it('never lets a plan limit change authorisation: a hire beyond it is observed, never refused, and permissions stay as they were', function () {
    $tiny = publishedPlan($this->operator, 'tiny', ['leave' => true, 'active_employees.max' => 1], '2027-01-04');
    $hr = tenantUser($this->tenant, ['*']);
    $nobody = tenantUser($this->tenant, []);
    $before = [$hr->fresh()->permissionKeys()->sort()->values()->all(), $nobody->fresh()->permissionKeys()->all()];
    $this->config->assignPlan($this->tenant, $tiny, '2027-01-04', null, 'One employee only', $this->operator);
    actAsTenant($this->tenant);
    $this->actingAs($hr);
    app(ShadowRecorder::class)->flush();

    $hires = [activeEmployee(null, ['task.view']), activeEmployee(null, ['task.view']), activeEmployee(null, ['task.view'])];
    expect(collect($hires)->every(fn ($e) => $e->lifecycle_state->isEmployed()))->toBeTrue();
    app(ShadowRecorder::class)->flush();
    expect(EntitlementShadowObservation::query()->where('surface', 'employee.activate')->where('reason', 'LIMIT_EXCEEDED')->exists())->toBeTrue()
        ->and([$hr->fresh()->permissionKeys()->sort()->values()->all(), $nobody->fresh()->permissionKeys()->all()])->toBe($before)
        ->and($nobody->fresh()->hasPermission('employee.create'))->toBeFalse();
});

it('keeps limit configuration with platform operators and each tenant\'s limits to itself', function () {
    $admin = tenantUser($this->tenant, ['*']);
    $plan = $this->catalog->create('growth', 'Growth', null, 'New offer', $this->operator);
    expect(fn () => $this->catalog->set($plan->versions->first(), Capability::UsersMax, 1_000_000, 'Raise my own limit', $admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->config->set($this->tenant, Capability::ActiveEmployeesMax, 1_000_000, '2027-01-04', null, 'Raise my own limit', $admin))->toThrow(RuntimeException::class, 'Only platform operators');

    $small = publishedPlan($this->operator, 'small', ['leave' => true, 'active_employees.max' => 10], '2027-01-04');
    $large = publishedPlan($this->operator, 'large', ['leave' => true, 'active_employees.max' => 10_000], '2027-01-04');
    $other = provisionTenant('Beta');
    $this->config->assignPlan($this->tenant, $small, '2027-01-04', null, 'Alpha small', $this->operator);
    $this->config->assignPlan($other, $large, '2027-01-04', null, 'Beta large', $this->operator);
    actAsTenant($this->tenant);
    expect(limitDecision(Capability::ActiveEmployeesMax, usage: 11)->limit)->toBe(10)
        ->and(app(TenantContext::class)->runAs($other, fn () => limitDecision(Capability::ActiveEmployeesMax, usage: 11)->limit))->toBe(10_000)
        ->and(app(EntitlementStateStore::class)->for($this->tenant->id)->plans)->toHaveKey($small->id)->not->toHaveKey($large->id)
        ->and(TenantPlanAssignment::query()->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PLAN_VERSION_EDITED')->where('actor_id', $admin->id)->exists())->toBeFalse()
        ->and(Plan::query()->count())->toBe(3);

    $this->actingAs($admin);
    $this->get(PlatformPlansPage::getUrl())->assertForbidden();
});
