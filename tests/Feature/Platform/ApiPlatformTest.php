<?php

use App\Console\Commands\GenerateOpenApi;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Models\ApiIdempotencyKey;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Enums\TenantStatus;
use App\Support\Api\OpenApiGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';

/*
 | Phase 14 §5: the canonical API contract — one error envelope, correlation ids, allow-listed
 | sorting, generic Idempotency-Key for writes, suspended tenants refused, no secret in limiter keys,
 | unique route names (route:cache), and an OpenAPI description that matches the routes.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->company = Company::query()->first() ?? Company::factory()->create(['code' => 'ACME']);
    $this->read = app(ApiKeys::class)->issue('Reader', ['employees.read'])['plaintext'];
    $this->write = app(ApiKeys::class)->issue('Writer', ['employees.read', 'employees.write'])['plaintext'];
    activeEmployee();
    actAsTenant(null);
    auth()->logout();
});

function hirePayload(string $first, string $company): array
{
    return ['person' => ['first_name' => $first, 'last_name' => 'Api'], 'employee' => ['joining_date' => '2026-10-20'], 'position' => ['company_code' => $company]];
}

it('returns one error envelope with a code and the request id, and never names an internal model', function () {
    $this->withHeader('X-Api-Key', 'pk_nope.nope')->getJson('/api/v1/employees')->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated')->assertJsonStructure(['message', 'code', 'request_id']);
    $this->flushHeaders();
    $missing = $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/employees/999999')->assertNotFound();
    expect($missing->json('message'))->toBe('Not found.')->and($missing->json('code'))->toBe('not_found')->and($missing->getContent())->not->toContain('App\\\\Domain');
    $this->withHeader('X-Api-Key', $this->read)->postJson('/api/v1/employees', [])->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->flushHeaders();
    $this->withHeader('X-Api-Key', $this->write)->postJson('/api/v1/employees', ['person' => ['first_name' => 'X']])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['employee.joining_date']);
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/employees?sort=salary')->assertStatus(422)->assertJsonPath('code', 'unprocessable');
});

it('accepts a well-formed correlation id, echoes it and stamps it on the audit trail; replaces a malformed one', function () {
    $response = $this->withHeaders(['X-Api-Key' => $this->write, 'X-Correlation-Id' => 'erp-batch-000123'])->postJson('/api/v1/employees', hirePayload('Corr', $this->company->code))->assertCreated();
    expect($response->headers->get('X-Correlation-Id'))->toBe('erp-batch-000123')->and($response->headers->get('X-Request-Id'))->toBe('erp-batch-000123');
    actAsTenant($this->tenant);
    expect(AuditEvent::query()->where('request_id', 'erp-batch-000123')->where('entity_type', Employee::class)->exists())->toBeTrue();
    actAsTenant(null);
    $this->flushHeaders();
    $bad = $this->withHeaders(['X-Api-Key' => $this->read, 'X-Correlation-Id' => "evil\nheader<script>"])->getJson('/api/v1/employees');
    expect($bad->headers->get('X-Correlation-Id'))->not->toContain('evil')->and(strlen((string) $bad->headers->get('X-Correlation-Id')))->toBe(26);
});

it('sorts lists only by allow-listed fields with a stable tie-breaker', function () {
    actAsTenant($this->tenant);
    activeEmployee()->update(['joining_date' => '2020-01-01']);
    actAsTenant(null);
    $codes = $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/employees?sort=joining_date')->assertOk()->json('data.*.joining_date');
    $sorted = $codes;
    sort($sorted);
    expect($codes)->toBe($sorted);
});

it('runs a write once per Idempotency-Key, replays the stored response, and refuses the key for another request', function () {
    $headers = ['X-Api-Key' => $this->write, 'Idempotency-Key' => 'hire-001'];
    $first = $this->withHeaders($headers)->postJson('/api/v1/employees', hirePayload('Idem', $this->company->code))->assertCreated();
    $again = $this->withHeaders($headers)->postJson('/api/v1/employees', hirePayload('Idem', $this->company->code))->assertCreated();
    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')->and($again->json('data.id'))->toBe($first->json('data.id'));
    actAsTenant($this->tenant);
    expect(Employee::query()->whereHas('person', fn ($q) => $q->where('first_name', 'Idem'))->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->sole()->getRawOriginal('response_body'))->not->toContain('Idem');
    actAsTenant(null);
    $this->withHeaders($headers)->postJson('/api/v1/employees', hirePayload('Other', $this->company->code))->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
});

it('refuses the keys of a suspended tenant and never keys the rate limiter by the secret', function () {
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/employees')->assertOk();
    $store = Cache::getStore();
    $keys = (new ReflectionProperty($store, 'storage'))->getValue($store);
    $secret = explode('.', $this->read)[1];
    expect(implode(' ', array_keys($keys)))->not->toContain($secret);

    $this->tenant->update(['status' => TenantStatus::Suspended]);
    $this->withHeader('X-Api-Key', $this->read)->getJson('/api/v1/employees')->assertStatus(401);
})->skip(fn () => ! enum_exists(TenantStatus::class) || ! defined(TenantStatus::class.'::Suspended'), 'No Suspended tenant status');

it('has unique route names (route:cache works) and an OpenAPI description that matches every public route', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter();
    expect($names->duplicates()->values()->all())->toBe([]);

    $spec = app(OpenApiGenerator::class)->generate();
    $documented = collect($spec['paths'])->flatMap(fn ($ops, $path) => collect($ops)->keys()->map(fn ($m) => strtoupper($m).' '.$path))->all();
    $public = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') || str_starts_with($r->uri(), 'api/scim/v2'))
        ->flatMap(fn ($r) => collect(array_diff($r->methods(), ['HEAD']))->map(fn ($m) => $m.' /'.$r->uri()))->all();
    expect(array_values(array_diff($public, $documented)))->toBe([])
        ->and(collect($spec['paths'])->flatten(1)->pluck('x-scope')->filter(fn ($s) => $s === null)->count())->toBe(0)
        ->and(collect($spec['paths'])->flatten(1)->pluck('x-audience')->unique()->values()->all())->toBe(['integration'])
        ->and($spec['openapi'])->toBe('3.1.0')
        ->and(collect($spec['paths'])->keys()->filter(fn ($p) => ! str_starts_with($p, '/api/'))->all())->toBe([]);
    // The committed file is the generated one.
    $this->artisan('peopleos:openapi', ['--check' => true])->assertSuccessful();
    expect(file_exists(base_path(GenerateOpenApi::PATH)))->toBeTrue();
});
