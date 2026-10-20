<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Models\Employee;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Integration\Contracts\InboundEventHandler;
use App\Domain\Integration\Exceptions\IntegrationRejected;
use App\Domain\Integration\Models\ApiKey;
use App\Domain\Integration\Models\ExternalReference;
use App\Domain\Integration\Models\InboundEvent;
use App\Domain\Integration\Models\IntegrationSystem;
use App\Domain\Integration\Services\ApiKeys;
use App\Domain\Integration\Services\ExternalReferences;
use App\Domain\Integration\Services\InboundEvents;
use App\Domain\Integration\Services\IntegrationMappings;
use App\Domain\Integration\Support\Signature;
use App\Domain\Organisation\Models\Department;
use App\Filament\Resources\InboundEvents\InboundEventResource;
use App\Filament\Resources\IntegrationSystems\IntegrationSystemResource;
use App\Filament\Resources\IntegrationSystems\Pages\CreateIntegrationSystem;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Performance/PerformanceTestHelpers.php';
require_once __DIR__.'/IntegrationHubTestHelpers.php';

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->employee = activeEmployee();
    $this->hub = hubIntegration();
    $this->post = function (string $body, array $headers, string $system = 'erp') {
        actAsTenant(null);
        $response = $this->call('POST', "/api/v1/integrations/{$system}/events", [], [], [], $this->transformHeadersToServerVars($headers), $body);
        $this->flushHeaders();
        actAsTenant($this->tenant);

        return $response;
    };
});

it('accepts a signed event once, processes it through its handler, and links the external id without touching PeopleOS ids', function () {
    $body = hubEvent('evt-1', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => $this->employee->employee_code, 'external_entity_type' => 'worker', 'external_entity_id' => 'W-900']);
    $first = ($this->post)($body, hubHeaders($this->hub['secret'], $body, $this->hub['key'], extra: ['X-Correlation-Id' => 'corr-12345678']));
    $first->assertStatus(202)->assertJsonPath('data.duplicate', false)->assertJsonPath('data.correlation_id', 'corr-12345678');

    $event = InboundEvent::query()->sole();
    expect($event->status)->toBe('succeeded')->and($event->attempts)->toBe(1)->and($event->result['entity_id'])->toBe($this->employee->id);
    $ref = ExternalReference::query()->sole();
    expect($ref->entity_type)->toBe('employee')->and($ref->entity_id)->toBe($this->employee->id)->and($ref->external_entity_id)->toBe('W-900')
        ->and(Employee::query()->find($this->employee->id)->employee_code)->toBe($this->employee->employee_code)
        ->and(app(ExternalReferences::class)->resolve($this->hub['system'], 'worker', 'W-900')->is($this->employee))->toBeTrue();

    // The business audit trail carries the correlation id end to end.
    expect(AuditEvent::query()->where('action', 'EXTERNAL_REFERENCE_LINKED')->value('request_id'))->toBe('corr-12345678')
        ->and(AuditEvent::query()->whereIn('action', ['INTEGRATION_EVENT_RECEIVED', 'INTEGRATION_EVENT_PROCESSED'])->count())->toBe(2);

    // A repeated delivery returns the stored event and does nothing again.
    ($this->post)($body, hubHeaders($this->hub['secret'], $body, $this->hub['key']))->assertOk()->assertJsonPath('data.duplicate', true)->assertJsonPath('data.status', 'succeeded');
    expect(InboundEvent::query()->count())->toBe(1)->and(ExternalReference::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'EXTERNAL_REFERENCE_LINKED')->count())->toBe(1);

    // The same idempotency key with another body is a conflict, never a second action.
    $other = hubEvent('evt-1', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => $this->employee->employee_code, 'external_entity_type' => 'worker', 'external_entity_id' => 'W-901']);
    ($this->post)($other, hubHeaders($this->hub['secret'], $other, $this->hub['key']))->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');

    // Lookup API: the integration's own reference → PeopleOS code.
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $this->hub['key'])->getJson('/api/v1/integrations/erp/references?external_entity_type=worker&external_entity_id=W-900')
        ->assertOk()->assertJsonPath('data.peopleos_code', $this->employee->employee_code);
    $this->withHeader('X-Api-Key', $this->hub['key'])->getJson('/api/v1/integrations/erp/events/evt-1')->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('refuses bad signatures, stale timestamps, unknown systems, unsupported types, and keys not bound to the integration', function () {
    $body = hubEvent('evt-2', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => 'X', 'external_entity_type' => 'w', 'external_entity_id' => '1']);
    ($this->post)($body, hubHeaders('whsec_wrong', $body, $this->hub['key']))->assertStatus(401)->assertJsonPath('code', 'invalid_signature');
    ($this->post)($body, hubHeaders($this->hub['secret'], $body, $this->hub['key'], now()->getTimestamp() - 3600))->assertStatus(401)->assertJsonPath('code', 'stale_timestamp');
    ($this->post)($body, hubHeaders($this->hub['secret'], $body, $this->hub['key']), 'nope')->assertNotFound();
    $odd = hubEvent('evt-3', 'payroll.push', ['x' => 1]);
    ($this->post)($odd, hubHeaders($this->hub['secret'], $odd, $this->hub['key']))->assertStatus(422)->assertJsonPath('code', 'unsupported_event_type');
    // A tampered body fails the signature.
    $headers = hubHeaders($this->hub['secret'], $body, $this->hub['key']);
    ($this->post)(str_replace('evt-2', 'evt-9', $body), $headers)->assertStatus(401);

    $bound = hubIntegration('payroll', ['api_key_id' => null]);
    $bound['system']->update(['api_key_id' => ApiKey::query()->where('name', 'Hub erp')->value('id')]);
    ($this->post)($body, hubHeaders($bound['secret'], $body, $bound['key']), 'payroll')->assertForbidden();

    expect(InboundEvent::query()->count())->toBe(0)->and(AuditEvent::query()->where('action', 'INTEGRATION_SIGNATURE_REJECTED')->count())->toBe(3);
});

it('fails a refused event without a business effect, retries transient errors with backoff, dead-letters after the limit and reprocesses with a reason', function () {
    $body = hubEvent('evt-4', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => 'NOBODY', 'external_entity_type' => 'worker', 'external_entity_id' => 'W-1']);
    ($this->post)($body, hubHeaders($this->hub['secret'], $body, $this->hub['key']))->assertStatus(202);
    $event = InboundEvent::query()->sole();
    expect($event->status)->toBe('failed')->and($event->last_error)->toContain('No PeopleOS record')->and(ExternalReference::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'INTEGRATION_EVENT_FAILED')->count())->toBe(1);

    // Transient failure: a handler that throws a plain exception retries, then dead-letters.
    config(['peopleos.integration.handlers' => config('peopleos.integration.handlers') + ['flaky.event' => FlakyTestHandler::class], 'peopleos.integration.max_attempts' => 2]);
    $flaky = hubEvent('evt-5', 'flaky.event', ['n' => 1]);
    ($this->post)($flaky, hubHeaders($this->hub['secret'], $flaky, $this->hub['key']))->assertStatus(202);
    $flakyEvent = InboundEvent::query()->where('external_event_id', 'evt-5')->sole();
    expect($flakyEvent->status)->toBe('retrying')->and($flakyEvent->attempts)->toBe(1)->and($flakyEvent->next_attempt_at->gt(now()))->toBeTrue();
    expect(app(InboundEvents::class)->processDue()['skipped'] + app(InboundEvents::class)->processDue()['succeeded'])->toBe(0); // not due yet
    $this->travelTo(now()->addMinutes(3));
    app(InboundEvents::class)->processDue();
    expect($flakyEvent->refresh()->status)->toBe('dead_letter')->and($flakyEvent->attempts)->toBe(2)
        ->and(AuditEvent::query()->where('action', 'INTEGRATION_EVENT_DEAD_LETTERED')->count())->toBe(1);

    // Reprocess (audited) → it runs again; the flaky handler now succeeds.
    FlakyTestHandler::$fail = false;
    $manager = tenantUser($this->tenant, ['integration.manage']);
    expect(fn () => app(InboundEvents::class)->reprocess($flakyEvent, $manager, ''))->toThrow(IntegrationRejected::class);
    app(InboundEvents::class)->reprocess($flakyEvent->refresh(), $manager, 'Upstream fixed');
    expect($flakyEvent->refresh()->status)->toBe('succeeded')->and($flakyEvent->reprocess_count)->toBe(1)
        ->and(AuditEvent::query()->where('action', 'INTEGRATION_EVENT_REPROCESSED')->value('reason'))->toBe('Upstream fixed');
});

it('purges payload bodies after the retention window while keeping metadata, and never stores a payload in the audit trail', function () {
    $body = hubEvent('evt-6', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => $this->employee->employee_code, 'external_entity_type' => 'worker', 'external_entity_id' => 'SECRET-W-77']);
    ($this->post)($body, hubHeaders($this->hub['secret'], $body, $this->hub['key']))->assertStatus(202);
    $event = InboundEvent::query()->sole();
    expect($event->getRawOriginal('payload'))->not->toContain('SECRET-W-77')->and($event->payload_metadata['keys'])->toContain('external_entity_id')
        ->and(AuditEvent::query()->where('action', 'like', 'INTEGRATION_EVENT_%')->with('fieldChanges')->get()->toJson())->not->toContain('SECRET-W-77');
    expect(app(InboundEvents::class)->purgePayloads())->toBe(0);
    $this->travelTo(now()->addDays(8));
    expect(app(InboundEvents::class)->purgePayloads())->toBe(1)->and($event->refresh()->payload)->toBeNull()->and($event->payload_purged_at)->not->toBeNull()
        ->and($event->payload_sha256)->toHaveLength(64)->and($event->payload_metadata['keys'])->not->toBeEmpty();
});

it('never re-points an external id, keeps references tenant-isolated, and resolves mappings with the PeopleOS code as default', function () {
    $refs = app(ExternalReferences::class);
    $other = activeEmployee();
    $refs->link($this->hub['system'], $this->employee, 'worker', 'W-1');
    expect($refs->link($this->hub['system'], $this->employee, 'worker', 'W-1')->id)->toBe(ExternalReference::query()->value('id'))
        ->and(fn () => $refs->link($this->hub['system'], $other, 'worker', 'W-1'))->toThrow(IntegrationRejected::class, 'already linked')
        ->and(fn () => ExternalReference::query()->first()->update(['entity_id' => $other->id]))->toThrow(RuntimeException::class)
        ->and(fn () => ExternalReference::query()->first()->delete())->toThrow(RuntimeException::class);

    $dept = Department::factory()->create(['code' => 'FIN']);
    $mappings = app(IntegrationMappings::class);
    expect($mappings->resolve($this->hub['system'], 'department', 'FIN')->is($dept))->toBeTrue();
    $mappings->map($this->hub['system'], 'department', 'Finance & Accounts', $dept);
    expect($mappings->resolve($this->hub['system'], 'department', 'Finance & Accounts')->is($dept))->toBeTrue()
        ->and($mappings->resolve($this->hub['system'], 'department', 'Unknown'))->toBeNull();

    // Another tenant sees none of it, and its key cannot reach this tenant's integration.
    $tenantB = provisionTenant('Beta');
    actAsTenant($tenantB);
    expect(ExternalReference::query()->count())->toBe(0)->and(InboundEvent::query()->count())->toBe(0);
    $keyB = app(ApiKeys::class)->issue('B', ['integrations.write', 'integrations.read'])['plaintext'];
    actAsTenant(null);
    $this->withHeader('X-Api-Key', $keyB)->getJson('/api/v1/integrations/erp/references?external_entity_type=worker&external_entity_id=W-1')->assertNotFound();
    $this->flushHeaders();
    $body = hubEvent('evt-7', 'reference.link', ['entity_type' => 'employee', 'peopleos_code' => $this->employee->employee_code, 'external_entity_type' => 'worker', 'external_entity_id' => 'W-2']);
    actAsTenant($this->tenant);
    ($this->post)($body, hubHeaders($this->hub['secret'], $body, $keyB))->assertNotFound();
});

it('signs outbound webhooks with tenant and correlation id, strips sensitive keys, claims each attempt once, and replays dead letters', function () {
    $endpoint = WebhookEndpoint::create(['name' => 'ERP', 'url' => 'https://erp.example.test/hook', 'secret' => 'whsec', 'events' => ['*'], 'status' => 'active']);
    Context::add('request_id', 'req-abcdef12');
    app(Webhooks::class)->publish('employee.updated', ['employee_code' => 'E1', 'pan' => 'ABCDE1234F', 'nested' => ['account_number' => '123', 'ok' => 1]], $this->employee);
    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->correlation_id)->toBe('req-abcdef12')->and($delivery->payload['tenant'])->toBe($this->tenant->slug)
        ->and($delivery->payload['data'])->toBe(['employee_code' => 'E1', 'nested' => ['ok' => 1]]);

    Http::fake(['https://erp.example.test/*' => Http::sequence()->push('', 500)->push('', 500)->push('ok', 200)]);
    $webhooks = app(Webhooks::class);
    expect($webhooks->deliver($delivery->id))->toBe('retrying')->and($webhooks->deliver($delivery->id))->toBe('skipped'); // not due: never twice
    Http::assertSent(fn ($r) => $r->hasHeader('X-Correlation-Id', 'req-abcdef12') && Webhooks::verify('whsec', $r->header('X-PeopleOS-Timestamp')[0], $r->body(), $r->header('X-PeopleOS-Signature')[0]));
    expect(Webhooks::verify('whsec', (string) (now()->getTimestamp() - 3600), '{}', Signature::sign('whsec', (string) (now()->getTimestamp() - 3600), '{}')))->toBeFalse();

    $delivery->refresh()->update(['attempts' => 4, 'next_attempt_at' => now()]);
    expect($webhooks->deliver($delivery->id))->toBe('dead_letter')->and($delivery->refresh()->status)->toBe('dead_letter');
    $admin = tenantUser($this->tenant, ['webhook.manage']);
    $webhooks->replay($delivery, $admin);
    expect($webhooks->deliverDue()['delivered'])->toBe(1)->and($delivery->refresh()->status)->toBe('delivered')->and($delivery->replay_count)->toBe(1)
        ->and(AuditEvent::query()->whereIn('action', ['WEBHOOK_DEAD_LETTERED', 'WEBHOOK_REPLAYED'])->count())->toBe(2);
});

final class FlakyTestHandler implements InboundEventHandler
{
    public static bool $fail = true;

    public function handle(InboundEvent $event, IntegrationSystem $system, array $data): array
    {
        if (self::$fail) {
            throw new RuntimeException('upstream timeout');
        }

        return ['ok' => true];
    }
}

it('renders the Integration Hub admin and registers an integration with a one-time secret', function () {
    $admin = tenantUser($this->tenant, ['*']);
    $this->actingAs($admin);
    $this->get(IntegrationSystemResource::getUrl('index'))->assertOk()->assertSee('erp');
    $this->get(IntegrationSystemResource::getUrl('edit', ['record' => $this->hub['system']]))->assertOk();
    $this->get(InboundEventResource::getUrl('index'))->assertOk();
    Livewire::test(CreateIntegrationSystem::class)
        ->fillForm(['code' => 'bgv-co', 'name' => 'BGV Co', 'kind' => 'bgv', 'require_signature' => true, 'signature_tolerance_seconds' => 300])
        ->call('create')->assertHasNoFormErrors()->assertNotified();
    $created = IntegrationSystem::query()->where('code', 'bgv-co')->sole();
    expect($created->inbound_secret)->toStartWith('whsec_')->and($created->getRawOriginal('inbound_secret'))->not->toStartWith('whsec_')
        ->and(AuditEvent::query()->where('entity_id', (string) $created->id)->with('fieldChanges')->get()->toJson())->not->toContain($created->inbound_secret);
});
