<?php

use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Integration\Models\ApiIdempotencyKey;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\TenantContext;

/* SaaS.2: the API rate-limit setting is wired, and Idempotency-Key rows have a bounded, tenant-isolated life. */

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->tenant = provisionTenant();
    $this->tenantB = provisionTenant('B');
    actAsTenant($this->tenant);
    $this->company = Company::factory()->create(['code' => 'ACME']);
    $this->write = app(ApiKeys::class)->issue('Writer', ['employees.read', 'employees.write'])['plaintext'];
    actAsTenant($this->tenantB);
    $this->companyB = Company::factory()->create(['code' => 'BETA']);
    $this->writeB = app(ApiKeys::class)->issue('Writer B', ['employees.read', 'employees.write'])['plaintext'];
    actAsTenant(null);
});

function saas2Hire(string $first, string $company): array
{
    return ['person' => ['first_name' => $first, 'last_name' => 'Api'], 'employee' => ['joining_date' => '2026-10-20'], 'position' => ['company_code' => $company]];
}

function saas2Count(object $test, string $first): int
{
    return app(TenantContext::class)->runAs($test->tenant, fn () => Employee::query()->whereHas('person', fn ($q) => $q->where('first_name', $first))->count());
}

it('defines the API rate limit in configuration and applies it per key', function () {
    expect(config('peopleos.api.rate_limit_per_minute'))->toBe(120);

    config(['peopleos.api.rate_limit_per_minute' => 2]);
    $this->withHeader('X-Api-Key', $this->write)->getJson('/api/v1/employees')->assertOk();
    $this->withHeader('X-Api-Key', $this->write)->getJson('/api/v1/employees')->assertOk();
    $this->withHeader('X-Api-Key', $this->write)->getJson('/api/v1/employees')->assertStatus(429);

    // Another tenant's key has its own bucket: one tenant cannot exhaust another's limit.
    $this->flushHeaders();
    $this->withHeader('X-Api-Key', $this->writeB)->getJson('/api/v1/employees')->assertOk();
});

it('remembers an Idempotency-Key for the configured window, then lets the key be used again', function () {
    expect(config('peopleos.api.idempotency_ttl_hours'))->toBe(24);
    $headers = ['X-Api-Key' => $this->write, 'Idempotency-Key' => 'hire-ttl'];

    $this->withHeaders($headers)->postJson('/api/v1/employees', saas2Hire('Ttl', 'ACME'))->assertCreated();
    $row = app(TenantContext::class)->runAs($this->tenant, fn () => ApiIdempotencyKey::query()->sole());
    expect($row->expires_at->toDateTimeString())->toBe('2026-10-15 10:00:00');

    // Inside the window: replayed, never re-run; another body with the key: refused.
    $this->travelTo('2026-10-15 09:59:00');
    $this->withHeaders($headers)->postJson('/api/v1/employees', saas2Hire('Ttl', 'ACME'))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    $this->withHeaders($headers)->postJson('/api/v1/employees', saas2Hire('Other', 'ACME'))->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    expect(saas2Count($this, 'Ttl'))->toBe(1);

    // After the window the key is free: the new request runs once and claims the key afresh.
    $this->travelTo('2026-10-15 10:00:01');
    $fresh = $this->withHeaders($headers)->postJson('/api/v1/employees', saas2Hire('Later', 'ACME'))->assertCreated();
    expect($fresh->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and(saas2Count($this, 'Later'))->toBe(1)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => ApiIdempotencyKey::query()->sole()->expires_at->toDateTimeString()))->toBe('2026-10-16 10:00:01');
});

it('purges expired idempotency rows tenant by tenant, and keeps live ones', function () {
    $this->withHeaders(['X-Api-Key' => $this->write, 'Idempotency-Key' => 'a-1'])->postJson('/api/v1/employees', saas2Hire('A1', 'ACME'))->assertCreated();
    $this->flushHeaders();
    $this->withHeaders(['X-Api-Key' => $this->writeB, 'Idempotency-Key' => 'b-1'])->postJson('/api/v1/employees', saas2Hire('B1', 'BETA'))->assertCreated();
    $this->travelTo('2026-10-15 12:00:00');
    $this->flushHeaders();
    $this->withHeaders(['X-Api-Key' => $this->write, 'Idempotency-Key' => 'a-2'])->postJson('/api/v1/employees', saas2Hire('A2', 'ACME'))->assertCreated();

    $count = fn ($tenant) => app(TenantContext::class)->runAs($tenant, fn () => ApiIdempotencyKey::query()->pluck('idempotency_key')->all());

    // Tenant B's purge touches only tenant B.
    $purgedB = app(TenantContext::class)->runAs($this->tenantB, fn () => app(Retention::class)->purge());
    expect($purgedB['api_idempotency_keys'])->toBe(1)
        ->and($count($this->tenantB))->toBe([])
        ->and($count($this->tenant))->toBe(['a-1', 'a-2']);

    $purgedA = app(TenantContext::class)->runAs($this->tenant, fn () => app(Retention::class)->purge());
    expect($purgedA['api_idempotency_keys'])->toBe(1)->and($count($this->tenant))->toBe(['a-2']);
});

it('never replays one tenant\'s stored response to another tenant using the same key', function () {
    $this->withHeaders(['X-Api-Key' => $this->write, 'Idempotency-Key' => 'shared'])->postJson('/api/v1/employees', saas2Hire('Alpha', 'ACME'))->assertCreated();
    $this->flushHeaders();
    $b = $this->withHeaders(['X-Api-Key' => $this->writeB, 'Idempotency-Key' => 'shared'])->postJson('/api/v1/employees', saas2Hire('Bravo', 'BETA'))->assertCreated();

    expect($b->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($b->json('data.first_name') ?? $b->json('data.person.first_name') ?? 'Bravo')->not->toBe('Alpha')
        ->and(app(TenantContext::class)->runAs($this->tenantB, fn () => Employee::query()->whereHas('person', fn ($q) => $q->where('first_name', 'Bravo'))->count()))->toBe(1);
});
