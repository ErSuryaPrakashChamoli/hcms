<?php

use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome as O;
use App\Domain\Entitlements\Models\EntitlementOverride;
use App\Domain\Entitlements\Models\EntitlementShadowObservation;
use App\Domain\Entitlements\Models\TenantEntitlement;
use App\Domain\Entitlements\Services\EntitlementConfiguration;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Entitlements\Services\EntitlementStateStore;
use App\Domain\Entitlements\Services\ShadowRecorder;
use App\Filament\Pages\PlatformEntitlementsPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\EntitlementProbeJob;

/*
| SaaS.3 §34–§35: platform operators alone configure entitlements; tenants are isolated in configuration, cache,
| observations and queued work; configuration changes are audited on both chains.
*/

beforeEach(function () {
    $this->travelTo('2027-01-04 09:00:00');
    $this->a = provisionTenant('Alpha');
    $this->b = provisionTenant('Beta');
    $this->operator = platformAdmin();
    $this->config = app(EntitlementConfiguration::class);
    $this->config->configure($this->b, '2027-01-04', 'Beta contract', $this->operator);
    $this->config->set($this->b, Capability::Payroll, true, '2027-01-04', null, 'Beta payroll', $this->operator);
    actAsTenant($this->a);
    $this->admin = tenantUser($this->a, ['*']);
});

it('refuses tenant administrators, whatever their roles, in the service and on the page', function () {
    expect(fn () => $this->config->set($this->a, Capability::Payroll, true, '2027-01-04', null, 'Self-grant attempt', $this->admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->config->grantOverride($this->b, Capability::Ai, true, '2027-01-04', null, 'Grant another tenant', $this->admin))->toThrow(RuntimeException::class, 'Only platform operators')
        ->and(fn () => $this->config->configure($this->a, '2027-01-04', 'Configure myself', $this->admin))->toThrow(RuntimeException::class, 'Only platform operators');

    $this->actingAs($this->admin);
    $this->get(PlatformEntitlementsPage::getUrl())->assertForbidden();
    expect(PlatformEntitlementsPage::canAccess())->toBeFalse()
        ->and(TenantEntitlement::query()->count())->toBe(0);
});

it('lets a platform operator configure through the page, audited on the tenant chain and the platform chain', function () {
    $this->actingAs($this->operator);
    actAsTenant(null);
    $this->get(PlatformEntitlementsPage::getUrl(['tenant' => $this->a->id]))->assertOk()->assertSee('unconfigured')->assertSee('payroll');

    Livewire::test(PlatformEntitlementsPage::class, ['tenant' => $this->a->id])
        ->callAction('configure', data: ['from' => '2027-01-04', 'reason' => 'Contract signed'])
        ->callAction('set', data: ['capability' => 'ai', 'enabled' => true, 'from' => '2027-01-04', 'reason' => 'AI included', 'reference' => 'C-1'])
        ->callAction('grantOverride', data: ['capability' => 'active_employees.max', 'unlimited' => false, 'limit' => 50, 'from' => '2027-01-04', 'reason' => 'Pilot cap'])
        ->assertHasNoActionErrors();

    expect(app(Entitlements::class)->evaluateFor($this->a->id, Capability::Ai)->outcome)->toBe(O::Allow)
        ->and(app(Entitlements::class)->evaluateFor($this->a->id, Capability::ActiveEmployeesMax, usage: 51)->outcome)->toBe(O::Deny);
    $events = AuditEvent::query()->withoutTenancy()->where('module', 'entitlements')->where('actor_id', $this->operator->id)->get();
    expect($events->where('tenant_id', $this->a->id)->pluck('action')->map->value->sort()->values()->all())->toBe(['ENTITLEMENT_CONFIGURED', 'ENTITLEMENT_OVERRIDE_GRANTED', 'ENTITLEMENT_SET'])
        ->and($events->whereNull('tenant_id')->filter(fn ($e) => ($e->metadata['subject_tenant_id'] ?? null) === $this->a->id)->count())->toBe(3)
        ->and($events->every(fn ($e) => filled($e->reason)))->toBeTrue();
    // Audit rows are append-only.
    expect(fn () => $events->first()->update(['reason' => 'rewritten']))->toThrow(ImmutableAuditRecordException::class);
});

it('keeps tenants apart: configuration, decisions, observations and cache', function () {
    actAsTenant($this->a);
    expect(TenantEntitlement::query()->count())->toBe(0)
        ->and(EntitlementOverride::query()->count())->toBe(0)
        ->and(app(Entitlements::class)->evaluate(Capability::Payroll)->reason->value)->toBe('TENANT_UNCONFIGURED');

    app(TenantContext::class)->runAs($this->b, fn () => app(Entitlements::class)->observe(Capability::Payroll, 'test.b'));
    app(ShadowRecorder::class)->flush();
    expect(EntitlementShadowObservation::query()->count())->toBe(0);
    app(TenantContext::class)->runAs($this->b, fn () => expect(EntitlementShadowObservation::query()->count())->toBe(1));

    // Tenant A's cache key and forget never touch tenant B's state.
    $store = app(EntitlementStateStore::class);
    $b = $store->for($this->b->id);
    $store->forget($this->a->id);
    expect(cache()->has(EntitlementStateStore::cacheKey($this->b->id)))->toBeTrue()
        ->and($store->for($this->b->id)->configuredFrom)->toBe($b->configuredFrom)
        ->and(EntitlementStateStore::cacheKey($this->a->id))->not->toBe(EntitlementStateStore::cacheKey($this->b->id));
});

it('evaluates each queued job with its own tenant, never the previous job\'s', function () {
    EntitlementProbeJob::$seen = [];
    actAsTenant($this->b);
    Bus::dispatchSync(new EntitlementProbeJob);
    actAsTenant($this->a);
    Bus::dispatchSync(new EntitlementProbeJob);
    actAsTenant($this->b);
    Bus::dispatchSync(new EntitlementProbeJob);

    expect(collect(EntitlementProbeJob::$seen)->map(fn ($d) => [$d['tenant_id'], $d['decision']])->all())
        ->toBe([[$this->b->id, 'ALLOW'], [$this->a->id, 'UNKNOWN'], [$this->b->id, 'ALLOW']]);
});
