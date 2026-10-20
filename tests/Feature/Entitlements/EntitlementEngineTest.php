<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Enums\DecisionReason as R;
use App\Domain\Entitlements\Enums\DecisionSource;
use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\EntitlementEvaluator;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Support\Decision;
use App\Domain\Entitlements\Support\EntitlementState;
use App\Support\Tenancy\TenantContext;

/*
| SaaS.3: the entitlement engine. Decisions, precedence, effective dating, overrides, limits, idempotency.
| Business dates are evaluated in the application time zone (UTC), inclusive at both ends (HasEffectiveDates).
*/

beforeEach(function () {
    $this->travelTo('2027-01-01 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $this->operator = platformAdmin();
    $this->config = app(EntitlementConfiguration::class);
    actAsTenant($this->tenant);
});

/** The bound tenant's decision for a capability on a date. */
function decide(Capability $capability, ?string $day = null, ?int $usage = null): Decision
{
    return app(Entitlements::class)->evaluate($capability, $day, $usage);
}

it('answers UNKNOWN for a tenant with no commercial configuration, and NOT_APPLICABLE for the core', function () {
    expect(decide(Capability::Payroll)->outcome)->toBe(O::Unknown)
        ->and(decide(Capability::Payroll)->reason)->toBe(R::TenantUnconfigured)
        ->and(decide(Capability::ActiveEmployeesMax, usage: 5)->reason)->toBe(R::TenantUnconfigured)
        ->and(decide(Capability::Core)->outcome)->toBe(O::NotApplicable)
        ->and(decide(Capability::Core)->source)->toBe(DecisionSource::Catalog)
        ->and(decide(Capability::Payroll)->enforced())->toBeFalse();
});

it('allows what the configuration grants and denies what a configured tenant lacks', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract signed', $this->operator);
    $row = $this->config->set($this->tenant, Capability::Payroll, true, '2027-01-01', null, 'Contract: payroll', $this->operator);

    $payroll = decide(Capability::Payroll);
    expect($payroll->outcome)->toBe(O::Allow)->and($payroll->reason)->toBe(R::Entitled)
        ->and($payroll->source)->toBe(DecisionSource::Configuration)->and($payroll->entitlementId)->toBe($row->id);
    expect(decide(Capability::Attendance)->outcome)->toBe(O::Deny)->and(decide(Capability::Attendance)->reason)->toBe(R::NotEntitled);
    // Before the configuration started, nothing is assumed.
    expect(decide(Capability::Payroll, '2026-12-31')->reason)->toBe(R::BeforeConfiguration);
});

it('keeps history: payroll granted on 1 January and removed on 1 April is still allowed in February', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    $this->config->set($this->tenant, Capability::Payroll, true, '2027-01-01', null, 'Payroll included', $this->operator);
    $this->travelTo('2027-04-01 00:00:00');
    $this->config->set($this->tenant, Capability::Payroll, false, '2027-04-01', null, 'Payroll removed at renewal', $this->operator);

    expect(decide(Capability::Payroll, '2027-02-15')->outcome)->toBe(O::Allow)
        ->and(decide(Capability::Payroll, '2027-03-31')->outcome)->toBe(O::Allow)
        ->and(decide(Capability::Payroll, '2027-04-01')->outcome)->toBe(O::Deny)
        ->and(decide(Capability::Payroll)->outcome)->toBe(O::Deny);
    $rows = TenantEntitlement::query()->orderBy('id')->get();
    expect($rows->first()->effective_to->toDateString())->toBe('2027-03-31')->and($rows->first()->value_bool)->toBeTrue();
});

it('handles effective-date edges: starts today, starts later, ends today, ended, adjacent, midnight', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    $this->config->set($this->tenant, Capability::Leave, true, '2027-01-01', '2027-01-01', 'One day only', $this->operator);
    $this->config->set($this->tenant, Capability::Attendance, true, '2027-01-05', null, 'Starts on the 5th', $this->operator);
    $this->config->set($this->tenant, Capability::Analytics, true, '2027-01-01', '2027-01-10', 'Ten days', $this->operator);
    $this->config->set($this->tenant, Capability::Analytics, false, '2027-01-11', null, 'Then off (adjacent)', $this->operator);

    expect(decide(Capability::Leave)->outcome)->toBe(O::Allow)                     // starts and ends today
        ->and(decide(Capability::Leave, '2027-01-02')->outcome)->toBe(O::Deny)       // ended
        ->and(decide(Capability::Attendance)->outcome)->toBe(O::Deny)                // starts later
        ->and(decide(Capability::Attendance, '2027-01-05')->outcome)->toBe(O::Allow)
        ->and(decide(Capability::Analytics, '2027-01-10')->outcome)->toBe(O::Allow)  // last day inclusive
        ->and(decide(Capability::Analytics, '2027-01-11')->outcome)->toBe(O::Deny);  // adjacent row

    // Midnight is the UTC date boundary.
    $this->travelTo('2027-01-10 23:59:59');
    expect(decide(Capability::Analytics)->outcome)->toBe(O::Allow);
    $this->travelTo('2027-01-11 00:00:00');
    expect(decide(Capability::Analytics)->outcome)->toBe(O::Deny);
});

it('paints a bounded change over an open-ended value and resumes it afterwards', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    $this->config->set($this->tenant, Capability::Ai, true, '2027-01-01', null, 'AI included', $this->operator);
    $this->config->set($this->tenant, Capability::Ai, false, '2027-02-01', '2027-02-28', 'AI paused for February', $this->operator);

    expect(decide(Capability::Ai, '2027-01-31')->outcome)->toBe(O::Allow)
        ->and(decide(Capability::Ai, '2027-02-14')->outcome)->toBe(O::Deny)
        ->and(decide(Capability::Ai, '2027-03-01')->outcome)->toBe(O::Allow);
    expect(TenantEntitlement::query()->where('status', 'active')->count())->toBe(3);
});

it('gives an override precedence over the configuration, keeps it in history after revocation', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    $this->config->set($this->tenant, Capability::Ai, false, '2027-01-01', null, 'No AI in the contract', $this->operator);
    $override = $this->config->grantOverride($this->tenant, Capability::Ai, true, '2027-01-01', '2027-03-31', 'Pilot', $this->operator, 'DEAL-7');

    $ai = decide(Capability::Ai);
    expect($ai->outcome)->toBe(O::Allow)->and($ai->reason)->toBe(R::OverrideGranted)->and($ai->overrideId)->toBe($override->id);

    $this->travelTo('2027-02-10 10:00:00');
    $this->config->revokeOverride($override, 'Pilot cancelled', $this->operator);
    expect(decide(Capability::Ai)->outcome)->toBe(O::Deny)
        ->and(decide(Capability::Ai, '2027-02-09')->reason)->toBe(R::OverrideGranted);

    // An override can also take something away, and an unconfigured tenant is still governed by an explicit override.
    $other = provisionTenant('Beta');
    $this->config->grantOverride($other, Capability::Payroll, false, '2027-02-10', null, 'Contract dispute hold', $this->operator);
    expect(app(Entitlements::class)->evaluateFor($other->id, Capability::Payroll)->reason)->toBe(R::OverrideDenied)
        ->and(app(Entitlements::class)->evaluateFor($other->id, Capability::Leave)->reason)->toBe(R::TenantUnconfigured);
});

it('refuses overlapping overrides, past starts, wrong value types and non-commercial capabilities', function () {
    $this->config->grantOverride($this->tenant, Capability::Ai, true, '2027-01-01', '2027-01-31', 'Pilot', $this->operator);

    expect(fn () => $this->config->grantOverride($this->tenant, Capability::Ai, false, '2027-01-15', null, 'Second', $this->operator))->toThrow(RuntimeException::class, 'Revoke it first')
        ->and(fn () => $this->config->set($this->tenant, Capability::Ai, true, '2026-12-31', null, 'Backdated', $this->operator))->toThrow(RuntimeException::class, 'past days keep their answer')
        ->and(fn () => $this->config->set($this->tenant, Capability::Ai, 5, '2027-01-01', null, 'Number for a module', $this->operator))->toThrow(RuntimeException::class, 'on or off')
        ->and(fn () => $this->config->set($this->tenant, Capability::ActiveEmployeesMax, true, '2027-01-01', null, 'Bool for a limit', $this->operator))->toThrow(RuntimeException::class, 'whole number')
        ->and(fn () => $this->config->set($this->tenant, Capability::Core, true, '2027-01-01', null, 'Core module', $this->operator))->toThrow(RuntimeException::class, 'not a commercial capability')
        ->and(fn () => $this->config->set($this->tenant, Capability::Ai, true, '2027-02-01', '2027-01-01', 'Inverted', $this->operator))->toThrow(RuntimeException::class, 'cannot be before');
});

it('is idempotent: the same change twice creates one row and one audit', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    $this->config->configure($this->tenant, '2027-01-01', 'Contract (retry)', $this->operator);
    $first = $this->config->set($this->tenant, Capability::Leave, true, '2027-01-01', null, 'Leave included', $this->operator);
    $again = $this->config->set($this->tenant, Capability::Leave, true, '2027-01-01', null, 'Leave included (retry)', $this->operator);
    $o1 = $this->config->grantOverride($this->tenant, Capability::Ai, true, '2027-01-01', '2027-01-31', 'Pilot', $this->operator);
    $o2 = $this->config->grantOverride($this->tenant, Capability::Ai, true, '2027-01-01', '2027-01-31', 'Pilot (retry)', $this->operator);

    expect($again->id)->toBe($first->id)->and($o2->id)->toBe($o1->id)
        ->and(TenantEntitlement::query()->count())->toBe(1)
        ->and(EntitlementOverride::query()->count())->toBe(1);
    $audits = AuditEvent::query()->withoutTenancy()->where('module', 'entitlements')->where('tenant_id', $this->tenant->id)->orderBy('id')->pluck('action')->map(fn ($a) => $a->value)->all();
    expect($audits)->toBe(['ENTITLEMENT_CONFIGURED', 'ENTITLEMENT_SET', 'ENTITLEMENT_OVERRIDE_GRANTED']);
});

it('keeps features inside their module', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    $this->config->set($this->tenant, Capability::AiExternalModel, true, '2027-01-01', null, 'External model', $this->operator);

    expect(decide(Capability::AiExternalModel)->reason)->toBe(R::ModuleNotEntitled);
    $this->config->set($this->tenant, Capability::Ai, true, '2027-01-01', null, 'AI module', $this->operator);
    expect(decide(Capability::AiExternalModel)->outcome)->toBe(O::Allow);
});

it('evaluates limits as available but exceeded, unlimited, unmeasured or not agreed', function () {
    $this->config->configure($this->tenant, '2027-01-01', 'Contract', $this->operator);
    expect(decide(Capability::ActiveEmployeesMax, usage: 3)->reason)->toBe(R::LimitNotConfigured);

    $this->config->set($this->tenant, Capability::ActiveEmployeesMax, 500, '2027-01-01', null, 'Up to 500', $this->operator);
    expect(decide(Capability::ActiveEmployeesMax, usage: 500)->reason)->toBe(R::WithinLimit)
        ->and(decide(Capability::ActiveEmployeesMax, usage: 501)->reason)->toBe(R::LimitExceeded)
        ->and(decide(Capability::ActiveEmployeesMax, usage: 501)->limit)->toBe(500)
        ->and(decide(Capability::ActiveEmployeesMax)->reason)->toBe(R::UsageUnavailable);

    // The enterprise contract override: 750 this quarter.
    $this->config->grantOverride($this->tenant, Capability::ActiveEmployeesMax, 750, '2027-01-01', '2027-03-31', 'Enterprise uplift', $this->operator);
    expect(decide(Capability::ActiveEmployeesMax, usage: 700)->reason)->toBe(R::WithinLimit)
        ->and(decide(Capability::ActiveEmployeesMax, '2027-04-01', usage: 700)->reason)->toBe(R::LimitExceeded);

    $this->config->set($this->tenant, Capability::UsersMax, null, '2027-01-01', null, 'Unlimited users', $this->operator);
    expect(decide(Capability::UsersMax, usage: 10_000)->reason)->toBe(R::Unlimited);
});

it('decides deterministically from the state alone, without a database', function () {
    $state = new EntitlementState(99, '2027-01-01', [
        ['id' => 1, 'capability' => 'payroll', 'value_bool' => true, 'value_int' => null, 'from' => '2027-01-01', 'to' => null],
        ['id' => 2, 'capability' => 'payroll', 'value_bool' => false, 'value_int' => null, 'from' => '2027-01-01', 'to' => null], // corrupt overlap
    ]);
    $evaluator = new EntitlementEvaluator;
    $a = $evaluator->decide($state, Capability::Payroll, '2027-01-02');
    $b = $evaluator->decide(EntitlementState::fromArray($state->toArray()), Capability::Payroll, '2027-01-02');

    // The tie-break is deterministic (latest start, then highest id) and survives the cache round-trip.
    expect($a->toArray())->toBe($b->toArray())->and($a->entitlementId)->toBe(2)->and($a->outcome)->toBe(O::Deny);
});

it('never lets a tenant evaluate another tenant through the HCM contract', function () {
    $other = provisionTenant('Beta');
    $this->config->configure($other, '2027-01-01', 'Beta contract', $this->operator);
    $this->config->set($other, Capability::Payroll, true, '2027-01-01', null, 'Beta payroll', $this->operator);

    actAsTenant($this->tenant);
    expect(decide(Capability::Payroll)->tenantId)->toBe($this->tenant->id)->and(decide(Capability::Payroll)->reason)->toBe(R::TenantUnconfigured)
        ->and(TenantEntitlement::query()->count())->toBe(0);
    app(TenantContext::class)->runAs($other, fn () => expect(decide(Capability::Payroll)->outcome)->toBe(O::Allow));
});
