<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Enums\DecisionReason as R;
use App\Domain\Entitlements\Enums\DecisionSource as S;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\PlanCatalog;
use App\Domain\Entitlements\Support\Decision;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/PlanTestHelpers.php';

/*
| SaaS.4: tenant → plan. A platform operator assigns a published plan version to a tenant for an effective-dated
| period; the existing engine answers from it (source `plan`), below the tenant's own terms and overrides. History is
| kept, the catalogue never rewrites a tenant's answer, tenants without a plan stay UNKNOWN, and authorisation is
| untouched. Fictional plans only.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->config = app(EntitlementConfiguration::class);
    $this->starter = publishedPlan($this->operator, 'starter', ['leave' => true, 'attendance' => true, 'ai' => false, 'active_employees.max' => 50, 'users.max' => null], '2027-01-04');
    $this->growth = publishedPlan($this->operator, 'growth', ['leave' => true, 'attendance' => true, 'payroll' => true, 'ai' => true, 'ai.external_model' => true, 'active_employees.max' => 500], '2027-01-04');
    actAsTenant($this->tenant);
});

/** The bound tenant's decision (the HCM contract). */
function onPlan(Capability $capability, ?string $day = null, ?int $usage = null): Decision
{
    return app(Entitlements::class)->evaluate($capability, $day, $usage);
}

it('answers from the assigned plan: included, not in the plan, explicitly excluded, and limits', function () {
    $a = $this->config->assignPlan($this->tenant, $this->starter, '2027-01-04', null, 'Signed Starter', $this->operator, 'DEAL-1');

    $leave = onPlan(Capability::Leave);
    expect([$leave->outcome, $leave->reason, $leave->source, $leave->assignmentId, $leave->planVersionId, $leave->enforced()])
        ->toBe([O::Allow, R::Entitled, S::Plan, $a->id, $this->starter->id, false])
        ->and($leave->toArray())->toMatchArray(['source' => 'plan', 'plan_assignment_id' => $a->id, 'plan_version_id' => $this->starter->id]);
    expect([onPlan(Capability::Payroll)->outcome, onPlan(Capability::Payroll)->reason])->toBe([O::Deny, R::NotInPlan])   // not sold
        ->and([onPlan(Capability::Ai)->outcome, onPlan(Capability::Ai)->reason, onPlan(Capability::Ai)->source])->toBe([O::Deny, R::NotEntitled, S::Plan]) // switched off
        ->and(onPlan(Capability::AiExternalModel)->reason)->toBe(R::NotInPlan)
        ->and(onPlan(Capability::ActiveEmployeesMax, usage: 50)->reason)->toBe(R::WithinLimit)
        ->and(onPlan(Capability::ActiveEmployeesMax, usage: 51)->reason)->toBe(R::LimitExceeded)
        ->and(onPlan(Capability::ActiveEmployeesMax, usage: 51)->limit)->toBe(50)
        ->and(onPlan(Capability::ActiveEmployeesMax)->reason)->toBe(R::UsageUnavailable)
        ->and(onPlan(Capability::UsersMax)->reason)->toBe(R::Unlimited)
        ->and([onPlan(Capability::LocationsMax)->outcome, onPlan(Capability::LocationsMax)->reason, onPlan(Capability::LocationsMax)->source])->toBe([O::Unknown, R::LimitNotConfigured, S::Plan])
        ->and(onPlan(Capability::Core)->outcome)->toBe(O::NotApplicable);

    // A feature still needs its module, whichever plan says so.
    $featureOnly = publishedPlan($this->operator, 'feature-only', ['leave' => true, 'ai.external_model' => true], '2027-01-04');
    $this->config->assignPlan($this->tenant, $featureOnly, '2027-01-05', null, 'Feature without its module', $this->operator);
    expect([onPlan(Capability::AiExternalModel, '2027-01-05')->outcome, onPlan(Capability::AiExternalModel, '2027-01-05')->reason, onPlan(Capability::AiExternalModel, '2027-01-05')->source])
        ->toBe([O::Deny, R::ModuleNotEntitled, S::Plan]);
    $this->config->assignPlan($this->tenant, $this->growth, '2027-01-06', null, 'Upgrade to Growth', $this->operator);
    expect(onPlan(Capability::AiExternalModel, '2027-01-06')->outcome)->toBe(O::Allow);
});

it('keeps the tenant\'s own terms and overrides above the plan, and the configured default below it', function () {
    $a = $this->config->assignPlan($this->tenant, $this->starter, '2027-01-04', null, 'Signed Starter', $this->operator);
    $this->config->configure($this->tenant, '2027-01-04', 'Own commercial terms', $this->operator);
    $this->config->set($this->tenant, Capability::Payroll, true, '2027-01-04', null, 'Payroll sold separately', $this->operator);
    $this->config->grantOverride($this->tenant, Capability::Leave, false, '2027-01-04', '2027-01-31', 'Hold during dispute', $this->operator);

    expect([onPlan(Capability::Payroll)->outcome, onPlan(Capability::Payroll)->source])->toBe([O::Allow, S::Configuration])
        ->and([onPlan(Capability::Leave)->outcome, onPlan(Capability::Leave)->source])->toBe([O::Deny, S::Override])
        ->and([onPlan(Capability::Leave, '2027-02-01')->outcome, onPlan(Capability::Leave, '2027-02-01')->source])->toBe([O::Allow, S::Plan])
        ->and([onPlan(Capability::Attendance)->outcome, onPlan(Capability::Attendance)->source])->toBe([O::Allow, S::Plan]);

    // Without the plan, a configured tenant falls back to the SaaS.3 rule: absent means not entitled.
    $this->config->endPlanAssignment($a, '2027-01-03', 'Plan replaced by own terms', $this->operator);
    expect($a->fresh()->status)->toBe(TenantPlanAssignment::CANCELLED)
        ->and([onPlan(Capability::Attendance)->outcome, onPlan(Capability::Attendance)->reason, onPlan(Capability::Attendance)->source])->toBe([O::Deny, R::NotEntitled, S::Configuration]);
});

it('keeps history: future, current, replaced, ended and expired assignments each answer for their own days', function () {
    $a = $this->config->assignPlan($this->tenant, $this->starter, '2027-01-11', null, 'Starter from next week', $this->operator);
    expect(onPlan(Capability::Leave)->reason)->toBe(R::BeforeConfiguration)
        ->and(onPlan(Capability::Leave, '2027-01-11')->outcome)->toBe(O::Allow);

    // A later assignment ends the earlier one the day before it starts.
    $b = $this->config->assignPlan($this->tenant, $this->growth, '2027-02-01', null, 'Upgrade in February', $this->operator);
    expect($a->fresh()->effective_to->toDateString())->toBe('2027-01-31')
        ->and(onPlan(Capability::Payroll, '2027-01-20')->reason)->toBe(R::NotInPlan)
        ->and(onPlan(Capability::Payroll, '2027-02-01')->outcome)->toBe(O::Allow);

    // Replacing a future assignment before it starts cancels it (it never took effect); nothing is rewritten.
    $c = $this->config->assignPlan($this->tenant, $this->starter, '2027-02-01', '2027-02-28', 'Stay on Starter one more month', $this->operator);
    $continued = TenantPlanAssignment::query()->where('status', 'active')->whereDate('effective_from', '2027-03-01')->sole();
    expect($b->fresh()->status)->toBe(TenantPlanAssignment::CANCELLED)
        ->and($b->fresh()->superseded_by)->toBe($c->id)
        ->and($continued->plan_version_id)->toBe($this->growth->id)
        ->and(onPlan(Capability::Payroll, '2027-02-15')->reason)->toBe(R::NotInPlan)
        ->and(onPlan(Capability::Payroll, '2027-03-01')->outcome)->toBe(O::Allow);

    // Months later the plan ends; the past keeps its answers and the gap is explicit, never DENY.
    $this->travelTo('2027-04-15 09:00:00');
    $this->config->endPlanAssignment($continued, '2027-04-30', 'Contract ends', $this->operator);
    expect(onPlan(Capability::Payroll, '2027-04-30')->outcome)->toBe(O::Allow)
        ->and([onPlan(Capability::Payroll, '2027-05-01')->outcome, onPlan(Capability::Payroll, '2027-05-01')->reason])->toBe([O::Unknown, R::NoPlanInForce])
        ->and(onPlan(Capability::Payroll, '2027-01-20')->reason)->toBe(R::NotInPlan)
        ->and(onPlan(Capability::Payroll, '2027-02-15')->reason)->toBe(R::NotInPlan)
        ->and(onPlan(Capability::Payroll, '2027-03-15')->outcome)->toBe(O::Allow)
        ->and(fn () => $this->config->endPlanAssignment($continued->fresh(), '2027-04-01', 'Back-dated end', $this->operator))->toThrow(RuntimeException::class, 'yesterday at the earliest');
    expect(TenantPlanAssignment::query()->count())->toBe(4); // a, b (cancelled), c, the continuation: all kept
});

it('assigns only published versions on sale, from today, with a reason, idempotently, audited on both chains', function () {
    $catalog = app(PlanCatalog::class);
    $draft = $catalog->draft($this->growth->plan, 'Growth v2 draft', $this->operator);
    expect(fn () => $this->config->assignPlan($this->tenant, $draft, '2027-01-04', null, 'Assign a draft', $this->operator))->toThrow(RuntimeException::class, 'is a draft')
        ->and(fn () => $this->config->assignPlan($this->tenant, $this->growth, '2027-01-03', null, 'Back-dated plan', $this->operator))->toThrow(RuntimeException::class, 'today or later')
        ->and(fn () => $this->config->assignPlan($this->tenant, $this->growth, '2027-01-04', null, 'no', $this->operator))->toThrow(RuntimeException::class, 'reason');

    // Publishing v2 from March ends v1's sale in February: v1 can no longer be assigned from March.
    $catalog->set($draft, Capability::ActiveEmployeesMax, 750, 'Bigger limit', $this->operator);
    $catalog->publish($draft, '2027-03-01', 'Growth v2', $this->operator);
    expect(fn () => $this->config->assignPlan($this->tenant, $this->growth->fresh(), '2027-03-01', null, 'Old version later', $this->operator))->toThrow(RuntimeException::class, 'inside that window');
    $catalog->retire($this->starter, 'Starter withdrawn', $this->operator);
    expect(fn () => $this->config->assignPlan($this->tenant, $this->starter->fresh(), '2027-01-04', null, 'Retired plan', $this->operator))->toThrow(RuntimeException::class, 'retired');

    $first = $this->config->assignPlan($this->tenant, $this->growth, '2027-01-04', null, 'Signed Growth', $this->operator, 'DEAL-7');
    $again = $this->config->assignPlan($this->tenant, $this->growth, '2027-01-04', null, 'Signed Growth (retry)', $this->operator, 'DEAL-7');
    expect($again->id)->toBe($first->id)->and(TenantPlanAssignment::query()->count())->toBe(1);

    $tenantChain = AuditEvent::query()->where('action', 'PLAN_ASSIGNED')->sole();
    $platformChain = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'PLAN_ASSIGNED')->sole();
    expect($tenantChain->tenant_id)->toBe($this->tenant->id)
        ->and($tenantChain->actor_id)->toBe($this->operator->id)
        ->and($tenantChain->reason)->toBe('Signed Growth')
        ->and($tenantChain->effective_date->toDateString())->toBe('2027-01-04')
        ->and($tenantChain->fieldChanges->map(fn ($c) => [$c->field, $c->before, $c->after])->all())->toBe([['plan', 'none', 'growth v1']])
        ->and($tenantChain->metadata)->toMatchArray(['plan' => 'growth', 'plan_version' => 1, 'reference' => 'DEAL-7'])
        ->and($platformChain->metadata['subject_tenant_id'])->toBe($this->tenant->id)
        ->and(TenantEntitlementProfile::query()->sole()->has_plan_assignments)->toBeTrue();

    // A change of plan records the previous and the new plan.
    $this->config->assignPlan($this->tenant, $this->growth->fresh()->plan->versions()->where('version', 2)->sole(), '2027-03-01', null, 'Move to v2 at renewal', $this->operator);
    expect(AuditEvent::query()->where('action', 'PLAN_ASSIGNED')->orderByDesc('id')->first()->fieldChanges->map(fn ($c) => [$c->before, $c->after])->all())->toBe([['growth v1', 'growth v2']]);
});

it('never changes a tenant\'s answer when the catalogue evolves: new versions and retirement are not retroactive', function () {
    $this->config->assignPlan($this->tenant, $this->growth, '2027-01-04', null, 'Signed Growth', $this->operator);
    $catalog = app(PlanCatalog::class);
    $v2 = $catalog->draft($this->growth->plan, 'Growth v2', $this->operator);
    $catalog->remove($v2, Capability::Payroll, 'Payroll becomes an add-on', $this->operator);
    $catalog->publish($v2, '2027-01-05', 'Growth v2 on sale', $this->operator);
    $catalog->retire($this->growth->fresh(), 'v1 withdrawn', $this->operator);

    expect(onPlan(Capability::Payroll)->outcome)->toBe(O::Allow)
        ->and(onPlan(Capability::Payroll, '2027-06-01')->outcome)->toBe(O::Allow)
        ->and(onPlan(Capability::Payroll, '2027-06-01')->planVersionId)->toBe($this->growth->id);
});

it('leaves tenants without a plan exactly as they were: UNKNOWN, never DENY, nothing created for them', function () {
    $legacy = provisionTenant('Legacy');
    $this->config->assignPlan($this->tenant, $this->starter, '2027-01-04', null, 'Signed Starter', $this->operator);

    app(TenantContext::class)->runAs($legacy, function () {
        foreach (Capability::commercialCases() as $capability) {
            $decision = onPlan($capability);
            expect([$decision->outcome, $decision->reason])->toBe([O::Unknown, R::TenantUnconfigured]);
        }
        expect(TenantPlanAssignment::query()->count())->toBe(0)->and(TenantEntitlementProfile::query()->count())->toBe(0);
    });
});

it('changes no authorisation: a plan neither grants a permission nor takes one away', function () {
    $payroll = tenantUser($this->tenant, ['payroll.view']);
    $nobody = tenantUser($this->tenant, []);
    $before = [$payroll->fresh()->permissionKeys()->sort()->values()->all(), $nobody->fresh()->permissionKeys()->all()];

    $this->config->assignPlan($this->tenant, $this->starter, '2027-01-04', null, 'Starter has no payroll', $this->operator);
    expect(onPlan(Capability::Payroll)->wouldDeny())->toBeTrue()
        ->and(onPlan(Capability::Leave)->allowed())->toBeTrue();

    $payroll = $payroll->fresh();
    $nobody = $nobody->fresh();
    expect([$payroll->permissionKeys()->sort()->values()->all(), $nobody->permissionKeys()->all()])->toBe($before)
        ->and($payroll->hasPermission('payroll.view'))->toBeTrue()   // not narrowed by a plan without payroll
        ->and($nobody->hasPermission('leave.apply'))->toBeFalse();   // not widened by a plan with leave
});

it('explains the plan behind each decision from the console', function () {
    $a = $this->config->assignPlan($this->tenant, $this->starter, '2027-01-04', null, 'Signed Starter', $this->operator);

    $this->artisan('peopleos:entitlements:explain', ['tenant' => $this->tenant->slug])
        ->expectsOutputToContain("plan starter v1 (assignment #{$a->id} from 2027-01-04)")
        ->expectsOutputToContain('NOT_IN_PLAN')
        ->assertSuccessful();
    $this->artisan('peopleos:entitlements:explain', ['tenant' => $this->tenant->slug, 'capability' => 'leave', '--at' => '2027-01-03'])
        ->expectsOutputToContain('no plan in force')->expectsOutputToContain('BEFORE_CONFIGURATION')->assertSuccessful();
});
