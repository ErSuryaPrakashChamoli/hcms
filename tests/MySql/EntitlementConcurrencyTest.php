<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';

/*
 | SaaS.3 §36–§37: concurrent entitlement configuration on MySQL never leaves two answers for one day, retries are
 | idempotent, and the shadow write gate holds across processes. Same opt-in as the other MySQL suites.
 | The database cache store is used so cache invalidation and the shadow gate are shared between processes.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'cache.default' => 'database', 'cache.stores.database.connection' => 'concurrency']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $this->tenant = provisionTenant('Entitlement race '.uniqid());
    $this->operator = platformAdmin();
    $this->today = now()->toDateString();
    actAsTenant($this->tenant);
});

afterEach(function () {
    if (isset($this->tenant)) {
        expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
            ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
    }
});

/** A configuration call as a separate process makes it (fresh tenant and operator instances). */
function entitlementCall(object $test, Closure $call): Closure
{
    return function () use ($test, $call) {
        $call(app(EntitlementConfiguration::class), Tenant::query()->findOrFail($test->tenant->id), $test->operator->fresh());
    };
}

it('1. leaves exactly one active value for a day when two operators set the same capability at once', function () {
    $results = race([
        entitlementCall($this, fn ($c, $t, $op) => $c->set($t, Capability::Payroll, true, $this->today, null, 'Operator one', $op)),
        entitlementCall($this, fn ($c, $t, $op) => $c->set($t, Capability::Payroll, false, $this->today, null, 'Operator two', $op)),
    ], slow: ['eloquent.creating: '.TenantEntitlement::class, 'eloquent.updating: '.TenantEntitlement::class]);

    expect($results)->toBe(['ok', 'ok']);
    $active = TenantEntitlement::query()->where('capability', 'payroll')->where('status', 'active')->get();
    expect($active)->toHaveCount(1)
        ->and(TenantEntitlement::query()->where('capability', 'payroll')->where('status', 'cancelled')->count())->toBe(1)
        ->and(TenantEntitlementProfile::query()->sole()->version)->toBe(2);
});

it('2. creates one row when the same change is submitted twice at once', function () {
    $same = entitlementCall($this, fn ($c, $t, $op) => $c->set($t, Capability::Leave, true, $this->today, null, 'Retry-safe change', $op));
    expect(race([$same, $same], slow: ['eloquent.creating: '.TenantEntitlement::class]))->toBe(['ok', 'ok']);

    expect(TenantEntitlement::query()->where('capability', 'leave')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', 'ENTITLEMENT_SET')->count())->toBe(1);
});

it('3. grants one of two overlapping overrides and refuses the other', function () {
    $results = race([
        entitlementCall($this, fn ($c, $t, $op) => $c->grantOverride($t, Capability::Ai, true, $this->today, null, 'Pilot on', $op)),
        entitlementCall($this, fn ($c, $t, $op) => $c->grantOverride($t, Capability::Ai, false, $this->today, null, 'Hold this one', $op)),
    ], slow: ['eloquent.creating: '.EntitlementOverride::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('Revoke it first')
        ->and(EntitlementOverride::query()->where('status', 'active')->count())->toBe(1);
});

it('4. configures a tenant once when two first-time writers race on the profile', function () {
    $configure = entitlementCall($this, fn ($c, $t, $op) => $c->configure($t, $this->today, 'First configuration', $op));
    expect(race([$configure, $configure]))->toBe(['ok', 'ok']);

    expect(TenantEntitlementProfile::query()->count())->toBe(1)
        ->and(TenantEntitlementProfile::query()->sole()->version)->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', 'ENTITLEMENT_CONFIGURED')->count())->toBe(1);
});

it('5. invalidates the shared cache on every change, so no process keeps serving the old answer', function () {
    app(EntitlementConfiguration::class)->configure($this->tenant, $this->today, 'Contract', $this->operator);
    app(EntitlementConfiguration::class)->set($this->tenant, Capability::Payroll, true, $this->today, null, 'Payroll on', $this->operator);
    actAsTenant($this->tenant);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->outcome)->toBe(O::Allow); // warms the shared cache

    expect(race([
        entitlementCall($this, fn ($c, $t, $op) => $c->set($t, Capability::Payroll, false, $this->today, null, 'Payroll off', $op)),
        entitlementCall($this, fn ($c, $t, $op) => $c->grantOverride($t, Capability::Ai, true, $this->today, null, 'AI pilot', $op)),
    ]))->toBe(['ok', 'ok']);

    app()->forgetInstance(EntitlementStateStore::class); // the parent's next request
    actAsTenant($this->tenant);
    expect(app(Entitlements::class)->evaluate(Capability::Payroll)->outcome)->toBe(O::Deny)
        ->and(app(Entitlements::class)->evaluate(Capability::Ai)->outcome)->toBe(O::Allow);
});

it('6. lets one process through the shadow write gate per window, and never duplicates a row', function () {
    $observe = fn (int $n) => function () use ($n) {
        actAsTenant($this->tenant);
        foreach (range(1, $n) as $i) {
            app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate');
        }
        app(ShadowRecorder::class)->flush();
    };
    expect(race([$observe(3), $observe(5)]))->toBe(['ok', 'ok']);

    $rows = EntitlementShadowObservation::query()->where('surface', 'payroll.run.calculate')->get();
    expect($rows)->toHaveCount(1)->and([3, 5])->toContain($rows->first()->occurrences);
});
