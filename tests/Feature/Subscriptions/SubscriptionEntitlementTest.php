<?php

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Enums\DecisionReason as R;
use App\Domain\Entitlements\Enums\DecisionSource;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Models\TenantPlanAssignment;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| SaaS.6: the subscription feeds the existing engine through plan assignments (one source of truth for the plan in
| force; one writer per tenant). A lapse means no plan in force (UNKNOWN), never DENY and never an authorisation
| failure; legacy tenants are untouched; commercial status is shadow context only; payroll keeps running.
*/

beforeEach(function () {
    $this->travelTo('2027-03-01 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->subs = app(CommercialSubscriptions::class);
    $this->config = app(EntitlementConfiguration::class);
    $this->growth = publishedPlan($this->operator, 'growth', ['leave' => true, 'payroll' => true, 'active_employees.max' => 100], '2027-03-01');
    $this->starter = publishedPlan($this->operator, 'starter', ['leave' => true, 'active_employees.max' => 25], '2027-03-01');
    actAsTenant($this->tenant);
});

function commercial(Capability $capability, ?string $day = null): array
{
    $d = app(Entitlements::class)->evaluate($capability, $day);

    return [$d->outcome, $d->reason, $d->source, $d->commercialStatus];
}

it('projects every entitled period onto the plan in force, and nothing for a lapsed or cancelled one', function () {
    $sub = $this->subs->startTrial($this->tenant, $this->starter, '2027-03-01', '2027-03-14', 'Trial of Starter', $this->operator);
    $sub = $this->subs->convert($sub, '2027-03-10', null, 'Signed for Growth later', $this->operator);
    $sub = $this->subs->changePlan($sub, $this->growth, '2027-04-01', 'Upgrade in April', $this->operator);
    $sub = $this->subs->enterGrace($sub, '2027-05-01', '2027-05-10', 'Renewal pending', $this->operator);

    expect(commercial(Capability::Payroll, '2027-03-05'))->toBe([O::Deny, R::NotInPlan, DecisionSource::Plan, 'trial'])      // Starter trial
        ->and(commercial(Capability::Leave, '2027-03-12'))->toBe([O::Allow, R::Entitled, DecisionSource::Plan, 'active'])
        ->and(commercial(Capability::Payroll, '2027-04-02'))->toBe([O::Allow, R::Entitled, DecisionSource::Plan, 'active'])   // Growth from April
        ->and(commercial(Capability::Payroll, '2027-05-05'))->toBe([O::Allow, R::Entitled, DecisionSource::Plan, 'grace'])   // grace keeps the plan
        ->and(commercial(Capability::Payroll, '2027-05-11'))->toBe([O::Unknown, R::NoPlanInForce, DecisionSource::None, null]); // lapsed: no plan, never DENY

    $rows = TenantPlanAssignment::query()->where('status', 'active')->orderBy('effective_from')->get()
        ->map(fn ($a) => [$a->plan_version_id, $a->commercial_status, $a->effective_from->toDateString(), $a->effective_to?->toDateString(), $a->subscription_id])->all();
    expect($rows)->toBe([
        [$this->starter->id, 'trial', '2027-03-01', '2027-03-09', $sub->id],
        [$this->starter->id, 'active', '2027-03-10', '2027-03-31', $sub->id],
        [$this->growth->id, 'active', '2027-04-01', '2027-04-30', $sub->id],
        [$this->growth->id, 'grace', '2027-05-01', '2027-05-10', $sub->id],
    ]);

    $this->subs->cancel($sub, '2027-03-20', 'Changed their mind', $this->operator);
    expect(commercial(Capability::Leave, '2027-03-19')[1])->toBe(R::Entitled)
        ->and(commercial(Capability::Leave, '2027-03-20'))->toBe([O::Unknown, R::NoPlanInForce, DecisionSource::None, null])
        ->and(TenantPlanAssignment::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', '2027-03-20'))->count())->toBe(0);
});

it('has one writer for a subscribed tenant: manual assignment is refused; an older manual plan is painted over, history kept', function () {
    $manual = $this->config->assignPlan($this->tenant, $this->growth, '2027-03-01', null, 'Manual SaaS.4 assignment', $this->operator);
    expect(commercial(Capability::Payroll)[3])->toBeNull();   // a manual plan has no commercial status

    $this->travelTo('2027-03-10 09:00:00');
    $this->subs->start($this->tenant, $this->starter, '2027-03-10', null, 'Now on a subscription', $this->operator);
    expect($manual->fresh()->effective_to->toDateString())->toBe('2027-03-09')                // ended, not rewritten
        ->and(commercial(Capability::Payroll, '2027-03-05'))->toBe([O::Allow, R::Entitled, DecisionSource::Plan, null])
        ->and(commercial(Capability::Payroll, '2027-03-10'))->toBe([O::Deny, R::NotInPlan, DecisionSource::Plan, 'active'])
        ->and(TenantEntitlementProfile::query()->sole()->subscription_managed)->toBeTrue()
        ->and(fn () => $this->config->assignPlan($this->tenant, $this->growth, '2027-03-11', null, 'Manual change', $this->operator))->toThrow(RuntimeException::class, 'managed by its subscription')
        ->and(fn () => $this->config->endPlanAssignment(TenantPlanAssignment::query()->whereNotNull('subscription_id')->sole(), '2027-03-20', 'Manual end', $this->operator))->toThrow(RuntimeException::class, 'managed by its subscription');
});

it('leaves tenants without a subscription exactly as they were: no subscription, no plan, no flag, UNKNOWN', function () {
    $legacy = provisionTenant('Legacy');
    $legacy->forceFill(['status' => 'trial', 'trial_ends_at' => '2027-03-31'])->save(); // a legacy trial tenant (metadata only)
    $manual = provisionTenant('Manual');
    $this->config->assignPlan($manual, $this->growth, '2027-03-01', null, 'SaaS.4 manual plan', $this->operator);
    $this->subs->start($this->tenant, $this->growth, '2027-03-01', null, 'Alpha subscribes', $this->operator);

    app(TenantContext::class)->runAs($legacy, function () use ($legacy) {
        expect(TenantSubscription::query()->count())->toBe(0)->and(SubscriptionPeriod::query()->count())->toBe(0)
            ->and(TenantPlanAssignment::query()->count())->toBe(0)
            ->and(commercial(Capability::Payroll))->toBe([O::Unknown, R::TenantUnconfigured, DecisionSource::None, null])
            ->and($legacy->fresh()->status->value)->toBe('trial')                                   // legacy metadata untouched
            ->and($legacy->fresh()->trial_ends_at->toDateString())->toBe('2027-03-31');
    });
    app(TenantContext::class)->runAs($manual, fn () => expect(commercial(Capability::Payroll))->toBe([O::Allow, R::Entitled, DecisionSource::Plan, null])
        ->and(TenantEntitlementProfile::query()->sole()->subscription_managed)->toBeFalse());
});

it('records the commercial status beside each shadow observation, and never refuses anything because of it', function () {
    $this->subs->startTrial($this->tenant, $this->starter, '2027-03-01', '2027-03-31', 'Trial of Starter', $this->operator);
    app(ShadowRecorder::class)->flush();
    app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate');
    app(ShadowRecorder::class)->flush();
    $row = EntitlementShadowObservation::query()->where('surface', 'payroll.run.calculate')->sole();
    expect([$row->outcome, $row->reason, $row->last_source, $row->last_commercial_status])->toBe(['DENY', 'NOT_IN_PLAN', 'plan', 'trial']);
});

it('keeps payroll running to payslips when the subscription has lapsed: a lapse is never an authorisation failure', function () {
    syncComplianceRules();
    $sub = $this->subs->startTrial($this->tenant, $this->starter, '2027-03-01', '2027-03-05', 'Short trial without payroll', $this->operator);
    $this->travelTo('2027-03-22 09:00:00');
    $this->artisan('peopleos:subscriptions:settle')->assertSuccessful();
    actAsTenant($this->tenant);
    $hr = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $approver = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $this->actingAs($hr);
    $company = payrollCompany();
    $employee = salariedEmployee(600000);
    expect(commercial(Capability::Payroll)[1])->toBe(R::NoPlanInForce)
        ->and($hr->hasPermission('payroll.calculate'))->toBeTrue();      // authorisation untouched

    $runs = app(PayrollRuns::class);
    $run = $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($company, 2027, 3))), $approver));
    expect($run->status)->toBe('finalized')->and(Payslip::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});
