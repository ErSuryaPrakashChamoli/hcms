<?php

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\ShadowRecorder;
use Illuminate\Support\Facades\DB;

/* SaaS.3 §39: evaluation costs a cached read per tenant per request, then nothing; observation costs no query. */

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->tenant = provisionTenant('Alpha');
    $config = app(EntitlementConfiguration::class);
    $operator = platformAdmin();
    $config->configure($this->tenant, '2027-01-04', 'Contract', $operator);
    $config->set($this->tenant, Capability::Payroll, true, '2027-01-04', null, 'Payroll', $operator);
    $config->grantOverride($this->tenant, Capability::Ai, true, '2027-01-04', null, 'Pilot', $operator);
    actAsTenant($this->tenant);
    cache()->flush();
    app()->forgetInstance(EntitlementStateStore::class);
});

function queriesDuring(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('loads a tenant\'s state with three indexed reads on a cold cache, then none for the rest of the request', function () {
    $e = app(Entitlements::class);
    expect(queriesDuring(fn () => $e->evaluate(Capability::Payroll)))->toBe(3)
        ->and(queriesDuring(function () use ($e) {
            foreach (range(1, 500) as $i) {
                $e->evaluate(Capability::Payroll);
                $e->evaluate(Capability::AiExternalModel);
                $e->evaluate(Capability::Leave, '2027-01-05');
            }
        }))->toBe(0);
});

it('serves the next request from the cache without a database read', function () {
    app(Entitlements::class)->evaluate(Capability::Payroll);
    app()->forgetInstance(EntitlementStateStore::class); // a new request

    expect(queriesDuring(fn () => app(Entitlements::class)->evaluate(Capability::Payroll)))->toBe(0);
});

it('observes without any query until the deferred flush, which writes one row per distinct observation', function () {
    app(Entitlements::class)->evaluate(Capability::Payroll); // warm
    expect(queriesDuring(function () {
        foreach (range(1, 1000) as $i) {
            app(Entitlements::class)->observe(Capability::Payroll, 'payroll.run.calculate');
        }
    }))->toBe(0);
    expect(queriesDuring(fn () => app(ShadowRecorder::class)->flush()))->toBe(1);
});

it('costs an unconfigured tenant one read per cache lifetime', function () {
    $fresh = provisionTenant('Fresh');
    actAsTenant($fresh);
    expect(queriesDuring(fn () => app(Entitlements::class)->evaluate(Capability::Payroll)))->toBe(1);
    app()->forgetInstance(EntitlementStateStore::class);
    expect(queriesDuring(fn () => app(Entitlements::class)->evaluate(Capability::Payroll)))->toBe(0);
});
