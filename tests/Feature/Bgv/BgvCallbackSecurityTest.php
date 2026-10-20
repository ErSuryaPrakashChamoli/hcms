<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Bgv\Models\BgvCheck;
use App\Domain\Bgv\Services\Bgv;
use App\Domain\Employment\Models\Employee;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Integration\Services\IntegrationSystems;
use App\Domain\Integration\Support\Signature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/*
 * Production readiness closure (blocker 1): the BGV callback authenticates the integration and the
 * request signature before anything in the body is read or applied. It is replay- and
 * duplicate-safe through the Integration Hub event store, tenant-bound, and audited without the payload.
 */

beforeEach(function () {
    $this->travelTo('2026-10-03 10:00:00');
    $this->tenant = provisionTenant('Alpha');
    actAsTenant($this->tenant);
    $this->actingAs(tenantUser($this->tenant, ['*']));
    $this->employee = Employee::factory()->create();
    $this->case = app(Bgv::class)->initiate($this->employee, ['identity', 'address'], 'manual', true);
    $this->case->update(['external_reference' => 'VENDOR-1']);
    $this->key = app(ApiKeys::class)->issue('BGV vendor', ['bgv.write']);
    $created = app(IntegrationSystems::class)->create(['code' => 'bgvco', 'name' => 'BGV Co', 'kind' => 'bgv', 'api_key_id' => $this->key['key']->id], tenantUser($this->tenant, ['integration.manage']));
    $this->secret = $created['secret'];
    $this->system = $created['system'];
    auth()->logout();
    actAsTenant(null);
    $this->body = json_encode(['checks' => [['type' => 'identity', 'status' => 'clear', 'notes' => 'Aadhaar 1234 5678 9012 matched'], ['type' => 'address', 'status' => 'clear']]]);
});

/** Send a raw body with optional signature headers. */
function bgvPost($test, string $body, ?string $apiKey, array $headers = [], string $reference = 'VENDOR-1')
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($apiKey ? ['HTTP_X_API_KEY' => $apiKey] : []);
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', "/api/v1/bgv/cases/{$reference}/checks", [], [], [], $server, $body);
}

function bgvSigned(string $secret, string $body, ?int $timestamp = null, array $extra = []): array
{
    $ts = (string) ($timestamp ?? now()->getTimestamp());

    return ['X-PeopleOS-Timestamp' => $ts, 'X-PeopleOS-Signature' => Signature::sign($secret, $ts, $body)] + $extra;
}

function bgvChecks(): array
{
    return BgvCheck::query()->withoutGlobalScopes()->orderBy('type')->pluck('status', 'type')->all();
}

it('1. applies results with a valid signature, audited, payload stored encrypted and never in the audit trail', function () {
    bgvPost($this, $this->body, $this->key['plaintext'], bgvSigned($this->secret, $this->body))
        ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.overall_result', 'clear')->assertHeader('Idempotent-Replayed', 'false');

    expect(bgvChecks())->toBe(['address' => 'clear', 'identity' => 'clear']);
    app(TenantContext::class)->runAs($this->tenant, function () {
        $event = InboundEvent::query()->sole();
        expect($event->event_type)->toBe('bgv.results')->and($event->status)->toBe('succeeded')
            ->and(DB::table('inbound_events')->value('payload'))->not->toContain('Aadhaar')
            ->and(json_encode(AuditEvent::query()->where('module', 'integration')->get()->pluck('metadata')))->not->toContain('Aadhaar')->not->toContain($this->secret);
    });
});

it('2–4. refuses an invalid signature, a missing signature and a modified body before touching any case', function () {
    $signed = bgvSigned($this->secret, $this->body);
    bgvPost($this, $this->body, $this->key['plaintext'], ['X-PeopleOS-Timestamp' => $signed['X-PeopleOS-Timestamp'], 'X-PeopleOS-Signature' => 'sha256='.str_repeat('0', 64)])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_signature');
    bgvPost($this, $this->body, $this->key['plaintext'])->assertStatus(401);
    $tampered = str_replace('"address","status":"clear"', '"address","status":"failed"', $this->body);
    expect($tampered)->not->toBe($this->body);
    bgvPost($this, $tampered, $this->key['plaintext'], $signed)->assertStatus(401)->assertJsonPath('code', 'invalid_signature');

    expect(bgvChecks())->toBe(['address' => 'pending', 'identity' => 'pending'])
        ->and(InboundEvent::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', 'INTEGRATION_SIGNATURE_REJECTED')->count())->toBe(3);
});

it('5. refuses a stale timestamp even with a correct signature', function () {
    bgvPost($this, $this->body, $this->key['plaintext'], bgvSigned($this->secret, $this->body, now()->subMinutes(10)->getTimestamp()))
        ->assertStatus(401)->assertJsonPath('code', 'stale_timestamp');
    bgvPost($this, $this->body, $this->key['plaintext'], bgvSigned($this->secret, $this->body, now()->addMinutes(10)->getTimestamp()))->assertStatus(401);
    expect(bgvChecks())->toBe(['address' => 'pending', 'identity' => 'pending']);
});

it('6–7. answers a replayed request and a duplicate valid delivery from the stored outcome, applying nothing twice', function () {
    $signed = bgvSigned($this->secret, $this->body);
    bgvPost($this, $this->body, $this->key['plaintext'], $signed)->assertOk();
    $audits = AuditEvent::query()->withoutGlobalScopes()->count();

    // Replay: the identical signed request inside the window.
    bgvPost($this, $this->body, $this->key['plaintext'], $signed)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.status', 'completed');
    // Duplicate valid delivery: a vendor retry with a fresh signature and the same Idempotency-Key, twice.
    $this->travel(30)->seconds();
    bgvPost($this, $this->body, $this->key['plaintext'], bgvSigned($this->secret, $this->body, extra: ['Idempotency-Key' => 'vendor-evt-77']))->assertOk()->assertHeader('Idempotent-Replayed', 'false');
    $after = AuditEvent::query()->withoutGlobalScopes()->count();
    bgvPost($this, $this->body, $this->key['plaintext'], bgvSigned($this->secret, $this->body, extra: ['Idempotency-Key' => 'vendor-evt-77']))->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    // The same key with a different body is a conflict, never applied.
    $other = json_encode(['checks' => [['type' => 'identity', 'status' => 'failed']]]);
    bgvPost($this, $other, $this->key['plaintext'], bgvSigned($this->secret, $other, extra: ['Idempotency-Key' => 'vendor-evt-77']))->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

    expect(InboundEvent::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(bgvChecks())->toBe(['address' => 'clear', 'identity' => 'clear'])
        ->and(AuditEvent::query()->withoutGlobalScopes()->count())->toBe($after)
        ->and($after)->toBeGreaterThan($audits);
});

it('8. never reaches another tenant: its case does not exist and its integration cannot be used', function () {
    actAsTenant($this->other = provisionTenant('Beta'));
    $this->actingAs(tenantUser($this->other, ['*']));
    $foreignCase = app(Bgv::class)->initiate(Employee::factory()->create(), ['identity'], 'manual', true);
    $foreignCase->update(['external_reference' => 'BETA-1']);
    $betaKey = app(ApiKeys::class)->issue('Beta vendor', ['bgv.write']);
    auth()->logout();
    actAsTenant(null);

    // Alpha's signed request for Beta's case: not found in Alpha.
    $body = json_encode(['checks' => [['type' => 'identity', 'status' => 'clear']]]);
    bgvPost($this, $body, $this->key['plaintext'], bgvSigned($this->secret, $body), 'BETA-1')->assertNotFound();
    // Beta's key with Alpha's integration signature: Beta's key is bound to no BGV integration.
    bgvPost($this, $body, $betaKey['plaintext'], bgvSigned($this->secret, $body), 'BETA-1')->assertStatus(403)->assertJsonPath('code', 'integration_required');
    expect(app(TenantContext::class)->runAs($this->other, fn () => $foreignCase->checks()->value('status')))->toBe('pending');
});

it('9. refuses an API key without an active bound BGV integration, or a non-BGV integration', function () {
    $unbound = app(TenantContext::class)->runAs($this->tenant, fn () => app(ApiKeys::class)->issue('Loose', ['bgv.write']));
    bgvPost($this, $this->body, $unbound['plaintext'], bgvSigned($this->secret, $this->body))->assertStatus(403)->assertJsonPath('code', 'integration_required');

    app(TenantContext::class)->runAs($this->tenant, fn () => $this->system->forceFill(['status' => 'inactive'])->save());
    bgvPost($this, $this->body, $this->key['plaintext'], bgvSigned($this->secret, $this->body))->assertStatus(409)->assertJsonPath('code', 'integration_inactive');

    bgvPost($this, $this->body, null, bgvSigned($this->secret, $this->body))->assertStatus(401);
    expect(bgvChecks())->toBe(['address' => 'pending', 'identity' => 'pending']);

    // Through the generic hub endpoint, only a bgv integration may report results.
    app(TenantContext::class)->runAs($this->tenant, function () {
        $this->system->forceFill(['status' => 'active'])->save();
        $erp = app(IntegrationSystems::class)->create(['code' => 'erp', 'name' => 'ERP', 'kind' => 'finance'], tenantUser($this->tenant, ['integration.manage']));
        $event = app(InboundEvents::class)->record($erp['system'], 'bgv.results', 'e-1', 'e-1', ['case_reference' => 'VENDOR-1', 'checks' => [['type' => 'identity', 'status' => 'failed']]], '{}');
        app(InboundEvents::class)->process($event['event']->refresh());
        expect($event['event']->refresh()->status)->toBe('failed');
    });
    expect(bgvChecks())->toBe(['address' => 'pending', 'identity' => 'pending']);
});

it('10. rejects malformed payloads after authentication and records nothing', function () {
    foreach (['not json', json_encode(['checks' => []]), json_encode(['checks' => [['type' => 'identity']]]), json_encode(['checks' => [['type' => 'identity', 'status' => 'clear', 'notes' => str_repeat('x', 2001)]]])] as $body) {
        bgvPost($this, $body, $this->key['plaintext'], bgvSigned($this->secret, $body))->assertStatus(422);
    }
    expect(InboundEvent::query()->withoutGlobalScopes()->count())->toBe(0)->and(bgvChecks())->toBe(['address' => 'pending', 'identity' => 'pending']);
});

it('never writes the callback payload, the signature or the secret to the logs', function () {
    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);
    $signed = bgvSigned($this->secret, $this->body);
    bgvPost($this, $this->body, $this->key['plaintext'], ['X-PeopleOS-Timestamp' => $signed['X-PeopleOS-Timestamp'], 'X-PeopleOS-Signature' => 'sha256=bad']);
    bgvPost($this, $this->body, $this->key['plaintext'], $signed);
    $logged = collect($handler->getRecords())->map(fn ($r) => $r->message.json_encode($r->context))->implode("\n");

    expect($logged)->not->toContain('Aadhaar')->not->toContain($this->secret)->not->toContain($signed['X-PeopleOS-Signature'])->not->toContain($this->key['plaintext']);
});
