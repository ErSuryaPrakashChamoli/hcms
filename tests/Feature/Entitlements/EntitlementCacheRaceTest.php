<?php

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
| The entitlement cache against stale state (2026-11-25, I.3 of the remediation programme). Both races are played out
| deterministically in one process; tests/MySql/EntitlementConcurrencyTest and PlanConcurrencyTest race real processes.
| 1. A reader loads the state, a change commits and invalidates, then the reader stores what it loaded: the next
|    request must still see the change (a plain forget let the old state back for the cache lifetime).
| 2. A reader whose state is older than the generation it read (a snapshot taken before the commit) never stores it.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->tenant = provisionTenant('Race');
    $this->operator = platformAdmin();
    $this->config = app(EntitlementConfiguration::class);
    $this->config->configure($this->tenant, '2027-01-04', 'Contract', $this->operator);
    $this->config->set($this->tenant, Capability::Payroll, true, '2027-01-04', null, 'Payroll on', $this->operator);
    actAsTenant($this->tenant);
});

/** A new request: no memo, the shared cache only. */
function freshDecision(int $tenantId, Capability $capability): O
{
    app()->forgetInstance(EntitlementStateStore::class);
    app()->forgetInstance(Entitlements::class);

    return app(Entitlements::class)->evaluateFor($tenantId, $capability)->outcome;
}

it('never serves the state a reader loaded before a change committed', function () {
    app(EntitlementStateStore::class)->forget($this->tenant->id);   // a cold cache
    $changed = false;
    // Right after the reader's last read (the active overrides), an operator switches payroll off: committed and
    // invalidated before the reader stores the state it read.
    DB::listen(function (QueryExecuted $query) use (&$changed) {
        if (! $changed && str_contains($query->sql, 'entitlement_overrides')) {
            $changed = true;
            app(EntitlementConfiguration::class)->set($this->tenant, Capability::Payroll, false, '2027-01-04', null, 'Payroll off', $this->operator);
        }
    });

    $reader = new EntitlementStateStore(app('cache.store'), app(TenantContext::class));
    expect($reader->for($this->tenant->id)->version)->toBe(2)   // it loaded the state before the change (version 2 of 3)
        ->and($changed)->toBeTrue()
        ->and(freshDecision($this->tenant->id, Capability::Payroll))->toBe(O::Deny);
});

it('does not store state older than the generation it read', function () {
    $store = app(EntitlementStateStore::class);
    $version = (int) TenantEntitlementProfile::query()->value('version');
    // A change at version + 1 has committed elsewhere; this reader's snapshot still shows $version.
    cache()->forever(EntitlementStateStore::generationKey($this->tenant->id), ($version + 1).'.01JTESTGENERATION');

    expect($store->for($this->tenant->id)->version)->toBe($version)                                   // served to this request
        ->and(cache()->has(EntitlementStateStore::cacheKey($this->tenant->id)))->toBeFalse();       // never shared

    // Its own state, once current, is shared as before.
    cache()->forever(EntitlementStateStore::generationKey($this->tenant->id), $version.'.01JTESTGENERATION');
    app()->forgetInstance(EntitlementStateStore::class);
    app(EntitlementStateStore::class)->for($this->tenant->id);
    expect(cache()->has(EntitlementStateStore::cacheKey($this->tenant->id)))->toBeTrue();
});

it('moves every change to a new generation carrying the profile version', function () {
    $before = cache()->get(EntitlementStateStore::generationKey($this->tenant->id));
    $this->config->set($this->tenant, Capability::Leave, true, '2027-01-04', null, 'Leave on', $this->operator);
    $after = (string) cache()->get(EntitlementStateStore::generationKey($this->tenant->id));

    expect($after)->not->toBe($before)
        ->and((int) strtok($after, '.'))->toBe((int) TenantEntitlementProfile::query()->value('version'))
        ->and(freshDecision($this->tenant->id, Capability::Leave))->toBe(O::Allow);
});
