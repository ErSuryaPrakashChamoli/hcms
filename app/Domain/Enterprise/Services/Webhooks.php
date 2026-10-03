<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Support\PayloadGuard;
use App\Domain\Integration\Support\Signature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Outbound webhooks (§88), the Integration Hub's outbound side.
 *
 * - **Outbox:** publish() only writes delivery rows, inside whatever transaction emitted the event. A
 *   rolled-back business change therefore leaves no delivery behind. The HTTP call happens later, from
 *   the scheduler, outside any business transaction.
 * - **Envelope:** `{id, event, occurred_at, tenant, correlation_id, subject, data}`, with data stripped
 *   of secret / sensitive keys.
 * - **Headers:** event, delivery, timestamp, signature (HMAC over "timestamp.body") and correlation id.
 *
 * Phase 14 additions:
 * - one delivery per endpoint and event (unique key);
 * - each attempt is claimed with a leased conditional update, so concurrent runs never send twice;
 * - after the maximum attempts a delivery is dead-lettered and can be replayed (audited);
 * - verify() enforces the timestamp window.
 */
final class Webhooks
{
    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    /** @param  array<string, mixed>  $payload */
    public function publish(string $event, array $payload, ?Model $subject = null): int
    {
        $eventId = (string) Str::ulid();
        $correlation = (string) (Context::get('request_id') ?? $eventId);
        $body = [
            'id' => $eventId, 'event' => $event, 'occurred_at' => now()->toIso8601String(), 'tenant' => $this->tenants->current()?->slug, 'correlation_id' => $correlation,
            'subject' => $subject ? ['type' => $subject->getMorphClass(), 'id' => $subject->getKey()] : null, 'data' => PayloadGuard::clean($payload),
        ];
        $queued = 0;

        foreach (WebhookEndpoint::query()->where('status', 'active')->get() as $endpoint) {
            if (! $endpoint->subscribedTo($event)) {
                continue;
            }
            WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'event_id' => $eventId, 'correlation_id' => mb_substr($correlation, 0, 64), 'payload' => $body, 'status' => 'pending', 'next_attempt_at' => now()]);
            $queued++;
        }

        return $queued;
    }

    /** Attempt every due delivery once; returns [delivered, dead_letter, retrying, skipped]. */
    public function deliverDue(int $limit = 100): array
    {
        $result = ['delivered' => 0, 'dead_letter' => 0, 'retrying' => 0, 'skipped' => 0];
        WebhookDelivery::query()->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id')
            ->each(function (int $id) use (&$result) {
                $result[$this->deliver($id)]++;
            });

        return $result;
    }

    /** One claimed attempt of one delivery: 'delivered' | 'retrying' | 'dead_letter' | 'skipped' (claimed elsewhere). */
    public function deliver(int $deliveryId, bool $force = false): string
    {
        if (! $this->claim($deliveryId, $force)) {
            return 'skipped';
        }
        $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($deliveryId);
        if ($this->attempt($delivery) === 'delivered') {
            return 'delivered';
        }
        if ($delivery->attempts >= (int) config('peopleos.enterprise.webhook_max_attempts', 5)) {
            $delivery->update(['status' => 'dead_letter', 'dead_lettered_at' => now(), 'next_attempt_at' => null]);
            $delivery->endpoint->increment('failure_count');
            $this->audit->record(AuditAction::WebhookDeadLettered, 'enterprise', $delivery->endpoint, [], null, metadata: ['event' => $delivery->event, 'event_id' => $delivery->event_id, 'attempts' => $delivery->attempts, 'response_code' => $delivery->response_code]);

            return 'dead_letter';
        }
        $delivery->update(['next_attempt_at' => now()->addMinutes(2 ** $delivery->attempts)]);

        return 'retrying';
    }

    /** A dead-lettered (or legacy failed) delivery goes back to pending with a fresh attempt budget (audited). */
    public function replay(WebhookDelivery $delivery, User $actor): WebhookDelivery
    {
        if (! $actor->hasPermission('webhook.manage')) {
            throw new RuntimeException('This needs webhook.manage.');
        }
        if (! in_array($delivery->status, ['dead_letter', 'failed'], true)) {
            throw new RuntimeException('Only dead-lettered deliveries are replayed.');
        }
        $delivery->update(['status' => 'pending', 'attempts' => 0, 'replay_count' => $delivery->replay_count + 1, 'next_attempt_at' => now(), 'dead_lettered_at' => null]);
        $this->audit->record(AuditAction::WebhookReplayed, 'enterprise', $delivery->endpoint, [], null, actor: $actor, metadata: ['event' => $delivery->event, 'event_id' => $delivery->event_id, 'replay' => $delivery->replay_count]);

        return $delivery;
    }

    public function attempt(WebhookDelivery $delivery): string
    {
        $endpoint = $delivery->endpoint;
        $json = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;

        try {
            $response = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json', 'User-Agent' => 'PeopleOS-Webhooks/1.0',
                'X-PeopleOS-Event' => $delivery->event, 'X-PeopleOS-Delivery' => $delivery->event_id,
                'X-PeopleOS-Timestamp' => $timestamp, 'X-PeopleOS-Signature' => Signature::sign((string) $endpoint->secret, $timestamp, $json),
                'X-Correlation-Id' => (string) ($delivery->correlation_id ?? $delivery->event_id),
            ])->withBody($json, 'application/json')->post($endpoint->url);
            $code = $response->status();
            $excerpt = Str::limit($response->body(), 500);
        } catch (\Throwable $e) {
            $code = 0;
            $excerpt = Str::limit($e->getMessage(), 500);
        }

        $ok = $code >= 200 && $code < 300;
        $delivery->update(['attempts' => $delivery->attempts + 1, 'response_code' => $code ?: null, 'response_excerpt' => $excerpt, 'status' => $ok ? 'delivered' : 'pending', 'delivered_at' => $ok ? now() : null]);
        if ($ok) {
            $endpoint->update(['last_delivered_at' => now()]);
        }

        return $ok ? 'delivered' : 'retry';
    }

    /** Consumer-side verification (also used by tests): signature and the timestamp window. */
    public static function verify(string $secret, string $timestamp, string $body, string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        return Signature::check($secret, $timestamp, $body, $signatureHeader, $toleranceSeconds) === 'valid';
    }

    /** Leased conditional update: only one runner attempts a due delivery; an abandoned lease expires after 5 minutes. */
    private function claim(int $deliveryId, bool $force): bool
    {
        return WebhookDelivery::query()->whereKey($deliveryId)->where('status', 'pending')
            ->when(! $force, fn ($q) => $q->where('next_attempt_at', '<=', now()))
            ->update(['next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]) === 1;
    }
}
