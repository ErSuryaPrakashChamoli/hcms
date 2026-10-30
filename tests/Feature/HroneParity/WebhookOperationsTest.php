<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Models\AuditEventChange;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookDeliveryAttempt;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Services\Retention;
use App\Domain\Enterprise\Services\Webhooks;
use App\Domain\Enterprise\Support\WebhookSamples;
use App\Domain\Integration\Models\IntegrationLogExport;
use App\Domain\Offboarding\Support\DataInventory;
use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource;
use App\Filament\Resources\WebhookEndpoints\Pages\EditWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Filament\Support\WebhookDeliveryActions;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| HROne parity SV-26 (webhook operations): timed attempts kept per delivery, secret rotation with a grace period and a second
| signature header, acknowledging dead letters (they leave the failure count), bulk replay, the event test with sample
| payloads, the success rate and status filter, the "Webhook deliveries" page across endpoints with its queued CSV export.
| Defaults keep the earlier behaviour: no rotation = one signature; "Send test" sends the same ping.
*/

beforeEach(function () {
    $this->travelTo('2026-10-29 10:00:00');
    Storage::fake(config('peopleos.documents.disk', 'local'));
    $this->tenant = provisionTenant('Alpha');
    $this->admin = tenantUser($this->tenant, ['*']);
    actAsTenant($this->tenant);
    $this->endpoint = WebhookEndpoint::create(['name' => 'ERP', 'url' => 'https://erp.example.test/hooks', 'secret' => 'old-secret', 'events' => ['leave.requested', 'employee.joined']]);
    actAsTenant(null);
});

function sv26Delivery(WebhookEndpoint $endpoint, array $attributes = []): WebhookDelivery
{
    return WebhookDelivery::create([...['webhook_endpoint_id' => $endpoint->id, 'event' => 'leave.requested', 'event_id' => (string) Str::ulid(), 'payload' => ['event' => 'leave.requested', 'data' => ['employee_code' => 'E1']],
        'status' => 'pending', 'next_attempt_at' => now()], ...$attributes]);
}

it('times every attempt and keeps it, with the last duration on the delivery', function () {
    actAsTenant($this->tenant);
    Http::fake(['https://erp.example.test/*' => Http::sequence()->push('nope', 500)->push('ok', 200)]);
    $delivery = sv26Delivery($this->endpoint);
    $webhooks = app(Webhooks::class);
    expect($webhooks->deliverDue()['retrying'])->toBe(1);
    $this->travelTo(now()->addMinutes(3));
    expect($webhooks->deliverDue()['delivered'])->toBe(1);

    $attempts = WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->orderBy('attempt')->get();
    expect($attempts->pluck('attempt')->all())->toBe([1, 2])->and($attempts->pluck('response_code')->all())->toBe([500, 200])
        ->and($attempts->first()->response_excerpt)->toBe('nope')->and($attempts->every(fn ($a) => $a->duration_ms >= 0 && $a->tenant_id === $this->tenant->id))->toBeTrue()
        ->and($attempts->contains('signed_with_previous', true))->toBeFalse()
        ->and($delivery->fresh()->duration_ms)->not->toBeNull()
        ->and(WebhookDeliveryActions::history($delivery->fresh()))->toHaveCount(2)
        ->and(WebhookDeliveryActions::history($delivery->fresh())[0])->toContain('#1')->toContain('HTTP 500');

    // Without a rotation, one signature only (as before).
    Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('X-PeopleOS-Signature') && ! $r->hasHeader(Webhooks::PREVIOUS_SIGNATURE_HEADER));
    actAsTenant(null);
});

it('rotates a secret with a grace period: both secrets sign until it ends, then only the new one; never audited or exported', function () {
    actAsTenant($this->tenant);
    $webhooks = app(Webhooks::class);
    expect(fn () => $webhooks->rotateSecret($this->endpoint, $this->admin, 0, 'x'))->toThrow(RuntimeException::class, 'between 1 and 72')
        ->and(fn () => $webhooks->rotateSecret($this->endpoint, $this->admin, 73, 'x'))->toThrow(RuntimeException::class, 'between 1 and 72')
        ->and(fn () => $webhooks->rotateSecret($this->endpoint, $this->admin, 2, ' '))->toThrow(RuntimeException::class, 'reason')
        ->and(fn () => $webhooks->rotateSecret($this->endpoint, tenantUser($this->tenant, ['employee.view']), 2, 'x'))->toThrow(RuntimeException::class, 'webhook.manage');

    $new = $webhooks->rotateSecret($this->endpoint, $this->admin, 2, 'Receiver moved to a vault');
    $endpoint = $this->endpoint->fresh();
    $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->first();
    expect(strlen($new))->toBe(48)->and($endpoint->secret)->toBe($new)->and($endpoint->previousSecretInGrace())->toBe('old-secret')
        ->and($raw->previous_secret)->not->toContain('old-secret')->and(decrypt($raw->previous_secret, false))->toBe('old-secret')
        ->and($endpoint->previous_secret_expires_at->toDateTimeString())->toBe('2026-10-29 12:00:00')
        ->and($endpoint->toArray())->not->toHaveKey('previous_secret')
        ->and(DataInventory::SECRET_COLUMNS)->toContain('previous_secret', 'secret');

    Http::fake(['https://erp.example.test/*' => Http::response('ok', 200)]);
    $delivery = sv26Delivery($endpoint);
    $webhooks->deliverDue();
    Http::assertSent(function (ClientRequest $r) use ($new) {
        $ts = $r->header('X-PeopleOS-Timestamp')[0];

        return Webhooks::verify($new, $ts, $r->body(), $r->header('X-PeopleOS-Signature')[0])
            && Webhooks::verify('old-secret', $ts, $r->body(), $r->header(Webhooks::PREVIOUS_SIGNATURE_HEADER)[0]);
    });
    expect(WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->sole()->signed_with_previous)->toBeTrue();

    // The rotation is one audited event; the secret is masked and the previous one never enters the trail.
    $event = AuditEvent::query()->withoutTenancy()->where('tenant_id', $this->tenant->id)->where('action', AuditAction::SigningSecretRotated)->sole();
    expect($event->reason)->toBe('Receiver moved to a vault')->and($event->fieldChanges->pluck('field')->all())->not->toContain('previous_secret')
        ->and($event->fieldChanges->firstWhere('field', 'secret')->after)->toBe(config('peopleos.audit.mask'));
    $all = json_encode(AuditEventChange::query()->get(['before', 'after']));
    expect($all)->not->toContain($new)->and($all)->not->toContain('old-secret');

    // After the grace period: cleared, one signature again.
    $this->travelTo('2026-10-29 12:00:01');
    Http::fake(['https://erp.example.test/*' => Http::response('ok', 200)]);
    sv26Delivery($endpoint);
    $webhooks->deliverDue();
    $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->first();
    expect($raw->previous_secret)->toBeNull()->and($raw->previous_secret_expires_at)->toBeNull();
    Http::assertSent(fn (ClientRequest $r) => ! $r->hasHeader(Webhooks::PREVIOUS_SIGNATURE_HEADER));

    // Replacing the secret by editing the endpoint is immediate: no grace period is kept.
    $webhooks->rotateSecret($endpoint->fresh(), $this->admin, 5, 'Again');
    $edited = $endpoint->fresh();
    $edited->update(['secret' => 'typed-by-hand']);
    expect($edited->fresh()->previousSecretInGrace())->toBeNull();
    actAsTenant(null);
});

it('acknowledges dead letters so they leave the failure count, and replays several in one audited operation', function () {
    actAsTenant($this->tenant);
    $dead = sv26Delivery($this->endpoint, ['status' => 'dead_letter', 'attempts' => 5, 'response_code' => 503, 'dead_lettered_at' => now(), 'next_attempt_at' => null]);
    $dead2 = sv26Delivery($this->endpoint, ['status' => 'dead_letter', 'attempts' => 5, 'response_code' => null, 'dead_lettered_at' => now(), 'next_attempt_at' => null]);
    $done = sv26Delivery($this->endpoint, ['status' => 'delivered', 'attempts' => 1, 'response_code' => 200, 'delivered_at' => now()]);
    $this->endpoint->update(['failure_count' => 2]);
    $webhooks = app(Webhooks::class);
    expect($webhooks->errorBreakdown())->toEqual(['HTTP 503' => 1, 'No response' => 1]);

    expect(fn () => $webhooks->acknowledge([$dead], tenantUser($this->tenant, ['employee.view'])))->toThrow(RuntimeException::class, 'webhook.manage');
    expect($webhooks->acknowledge([$dead, $done], $this->admin, 'Receiver was down for maintenance'))->toBe(['acknowledged' => 1, 'skipped' => 1])
        ->and($webhooks->acknowledge([$dead], $this->admin))->toBe(['acknowledged' => 0, 'skipped' => 1]);
    expect($dead->fresh()->isAcknowledged())->toBeTrue()->and($dead->fresh()->acknowledged_by)->toBe($this->admin->id)
        ->and($this->endpoint->fresh()->failure_count)->toBe(1)
        ->and($webhooks->errorBreakdown())->toBe(['No response' => 1])
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::WebhookAcknowledged)->sole()->reason)->toBe('Receiver was down for maintenance');

    $counts = $webhooks->replayMany([$dead, $dead2, $done], $this->admin);
    expect($counts)->toBe(['replayed' => 2, 'skipped' => 1])
        ->and($dead->fresh()->status)->toBe('pending')->and($dead->fresh()->isAcknowledged())->toBeFalse()->and($dead2->fresh()->replay_count)->toBe(1);
    $replays = AuditEvent::query()->withoutTenancy()->where('action', AuditAction::WebhookReplayed)->get();
    expect($replays)->toHaveCount(2)->and($replays->pluck('operation_id')->unique())->toHaveCount(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::BulkOperation)->where('operation_id', $replays->first()->operation_id)->exists())->toBeTrue();
    actAsTenant(null);
});

it('sends a sample of any event as a test (never real data), and the ping as before by default', function () {
    actAsTenant($this->tenant);
    Http::fake(['https://erp.example.test/*' => Http::response('ok', 200)]);
    $webhooks = app(Webhooks::class);
    $ping = $webhooks->sendTest($this->endpoint);
    expect($ping->status)->toBe('delivered')->and($ping->event)->toBe('ping')->and($ping->payload['data'])->toBe(['message' => 'PeopleOS webhook test']);

    $sample = $webhooks->sendTest($this->endpoint, 'employee.joined');
    expect($sample->payload['test'])->toBeTrue()->and($sample->payload['event'])->toBe('employee.joined')
        ->and($sample->payload['data'])->toMatchArray(['employee_id' => 0, 'employee_code' => 'SAMPLE-0001', 'to' => 'active', 'sample' => true]);
    foreach (config('peopleos.enterprise.webhook_events') as $event) {
        expect(WebhookSamples::data($event)['sample'])->toBeTrue();
    }
    expect(fn () => $webhooks->sendTest($this->endpoint, 'made.up'))->toThrow(RuntimeException::class, 'Unknown webhook event');

    $this->actingAs($this->admin);
    Filament::getPanel('admin')->boot();
    Livewire::test(ListWebhookEndpoints::class)->callTableAction('test', $this->endpoint, ['event' => 'leave.requested'])->assertHasNoTableActionErrors();
    expect(WebhookDelivery::query()->where('event', 'leave.requested')->latest('id')->first()->payload['data']['leave_type'])->toBe('SAMPLE');
    actAsTenant(null);
});

it('shows the success rate over 30 days and filters webhooks by status', function () {
    actAsTenant($this->tenant);
    $paused = WebhookEndpoint::create(['name' => 'Old CRM', 'url' => 'https://crm.example.test/hooks', 'secret' => 's', 'events' => ['*'], 'status' => 'paused']);
    sv26Delivery($this->endpoint, ['status' => 'delivered', 'attempts' => 1]);
    sv26Delivery($this->endpoint, ['status' => 'delivered', 'attempts' => 2]);
    sv26Delivery($this->endpoint, ['status' => 'dead_letter', 'attempts' => 5]);
    sv26Delivery($this->endpoint, ['status' => 'pending', 'attempts' => 0]); // not attempted yet: not counted
    sv26Delivery($this->endpoint, ['status' => 'delivered', 'attempts' => 1])->forceFill(['created_at' => now()->subDays(40)])->save(); // outside the window

    $this->actingAs($this->admin);
    Livewire::test(ListWebhookEndpoints::class)->assertSee('66%')
        ->filterTable('status', 'paused')->assertCanSeeTableRecords([$paused])->assertCanNotSeeTableRecords([$this->endpoint]);
    actAsTenant(null);
});

it('lists every endpoint\'s deliveries, with failed ones to acknowledge or replay in bulk and a queued CSV export', function () {
    actAsTenant($this->tenant);
    $crm = WebhookEndpoint::create(['name' => 'CRM', 'url' => 'https://crm.example.test/hooks/token-in-path', 'secret' => 's', 'events' => ['*']]);
    $ok = sv26Delivery($this->endpoint, ['status' => 'delivered', 'attempts' => 1, 'response_code' => 200, 'duration_ms' => 42]);
    $dead = sv26Delivery($crm, ['status' => 'dead_letter', 'attempts' => 5, 'response_code' => 500, 'next_attempt_at' => null, 'payload' => ['event' => 'x', 'data' => ['employee_code' => 'PAYLOAD-MARKER']]]);
    $crm->update(['failure_count' => 1]);

    $this->actingAs($this->admin);
    $this->get(WebhookDeliveryResource::getUrl('index'))->assertOk()->assertSee('Webhook deliveries');
    Filament::getPanel('admin')->boot();
    Livewire::test(ListWebhookDeliveries::class)->assertCanSeeTableRecords([$ok, $dead])
        ->set('activeTab', 'failed')->assertCanSeeTableRecords([$dead])->assertCanNotSeeTableRecords([$ok])
        ->callTableBulkAction('acknowledgeSelected', [$dead], ['note' => 'Known outage'])->assertHasNoTableBulkActionErrors();
    expect($dead->fresh()->isAcknowledged())->toBeTrue()->and($crm->fresh()->failure_count)->toBe(0);
    Livewire::test(ListWebhookDeliveries::class)->set('activeTab', 'failed')->assertCanNotSeeTableRecords([$dead]);
    Livewire::test(ListWebhookDeliveries::class)->callTableBulkAction('replaySelected', [$dead, $ok])->assertHasNoTableBulkActionErrors();
    expect($dead->fresh()->status)->toBe('pending')->and($ok->fresh()->status)->toBe('delivered');
    Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $crm, 'pageClass' => EditWebhookEndpoint::class])->assertOk()->assertCanSeeTableRecords([$dead]);

    // Queued export: no payloads, destinations as scheme and host only; downloaded by the person who asked.
    Livewire::test(ListWebhookDeliveries::class)->callAction('export', ['from' => '2026-10-01'])->assertHasNoActionErrors();
    $export = IntegrationLogExport::query()->sole();
    $csv = Storage::disk($export->disk)->get($export->path);
    expect($export->kind)->toBe('webhook_deliveries')->and($export->row_count)->toBe(2)
        ->and($csv)->toContain('ERP')->and($csv)->toContain('https://crm.example.test/…')
        ->and($csv)->not->toContain('token-in-path')->and($csv)->not->toContain('PAYLOAD-MARKER')
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::Export)->where('metadata->log', 'webhook_deliveries')->exists())->toBeTrue();

    // webhook.manage only.
    $this->actingAs(tenantUser($this->tenant, ['api_key.manage']));
    $this->get(WebhookDeliveryResource::getUrl('index'))->assertForbidden();
    actAsTenant(null);
});

it('purges attempts with their deliveries, and classifies the new tables', function () {
    actAsTenant($this->tenant);
    Http::fake(['https://erp.example.test/*' => Http::response('ok', 200)]);
    $delivery = sv26Delivery($this->endpoint);
    app(Webhooks::class)->deliverDue();
    expect(WebhookDeliveryAttempt::query()->count())->toBe(1);
    $delivery->forceFill(['created_at' => now()->subDays(91)])->save();
    expect(app(Retention::class)->purge()['webhook_deliveries'])->toBe(1)->and(WebhookDeliveryAttempt::query()->count())->toBe(0);
    actAsTenant(null);

    $categories = DataInventory::tableCategories();
    expect($categories['webhook_delivery_attempts'])->toBe('integrations')->and($categories['integration_log_exports'])->toBe('integrations')
        ->and(DataInventory::FILES)->toHaveKey('integration_log_exports');
});
