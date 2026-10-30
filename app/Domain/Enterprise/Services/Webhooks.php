<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookDeliveryAttempt;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Enterprise\Support\WebhookSamples;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Support\PayloadGuard;
use App\Domain\Integration\Support\Signature;
use App\Support\Http\SafeHttp;
use App\Support\Http\UnsafeOutboundUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
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
 *
 * HROne parity SV-26 additions:
 * - every attempt is timed and kept (webhook_delivery_attempts; the delivery keeps the last duration);
 * - rotateSecret(): a new secret at once, the previous one kept (encrypted) for a 1–72 hour grace period during which
 *   every request also carries `X-PeopleOS-Signature-Previous` (same scheme, previous secret); then it is cleared;
 * - acknowledge() a dead letter (it leaves the endpoint's failure count), replayMany() in bulk (one audit operation);
 * - sendTest() posts a sample of any subscribed event from WebhookSamples (never real data).
 */
final class Webhooks
{
    public const PREVIOUS_SIGNATURE_HEADER = 'X-PeopleOS-Signature-Previous';

    public const GRACE_HOURS_MIN = 1;

    public const GRACE_HOURS_MAX = 72;

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
        if ($queued > 0) {
            app(Entitlements::class)->observe(Capability::IntegrationsWebhooks, 'webhooks.publish'); // SaaS.3: shadow only
        }

        return $queued;
    }

    /** Attempt every due delivery once; returns [delivered, dead_letter, retrying, skipped]. */
    public function deliverDue(int $limit = 100): array
    {
        // SV-26: previous secrets past their grace period are cleared before anything is signed.
        $this->expirePreviousSecrets();
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
        // SV-26: a replayed delivery is live again, so an earlier acknowledgement no longer applies.
        $delivery->update(['status' => 'pending', 'attempts' => 0, 'replay_count' => $delivery->replay_count + 1, 'next_attempt_at' => now(), 'dead_lettered_at' => null,
            'acknowledged_at' => null, 'acknowledged_by' => null]);
        $this->audit->record(AuditAction::WebhookReplayed, 'enterprise', $delivery->endpoint, [], null, actor: $actor, metadata: ['event' => $delivery->event, 'event_id' => $delivery->event_id, 'replay' => $delivery->replay_count]);

        return $delivery;
    }

    /**
     * SV-26: replays several dead letters as one audited operation; deliveries that are not dead letters are skipped.
     *
     * @param  iterable<int|WebhookDelivery>  $deliveries
     * @return array{replayed: int, skipped: int}
     */
    public function replayMany(iterable $deliveries, User $actor): array
    {
        $this->authorise($actor);
        $counts = ['replayed' => 0, 'skipped' => 0];
        $ids = $this->ids($deliveries);
        $this->audit->operation('enterprise', 'Replay webhook deliveries', function () use ($ids, $actor, &$counts) {
            foreach ($ids as $id) {
                $delivery = WebhookDelivery::query()->with('endpoint')->find($id);
                if ($delivery === null || ! $delivery->isFailed()) {
                    $counts['skipped']++;

                    continue;
                }
                $this->replay($delivery, $actor);
                $counts['replayed']++;
            }

            return $counts['replayed'];
        });

        return $counts;
    }

    /**
     * SV-26: marks dead letters as seen ("acknowledged"): they stay in the log, but leave the endpoint's failure count.
     * Deliveries that are not dead letters, or are acknowledged already, are skipped. One audited operation.
     *
     * @param  iterable<int|WebhookDelivery>  $deliveries
     * @return array{acknowledged: int, skipped: int}
     */
    public function acknowledge(iterable $deliveries, User $actor, ?string $note = null): array
    {
        $this->authorise($actor);
        $counts = ['acknowledged' => 0, 'skipped' => 0];
        $ids = $this->ids($deliveries);
        $this->audit->operation('enterprise', 'Acknowledge webhook deliveries', function () use ($ids, $actor, $note, &$counts) {
            foreach ($ids as $id) {
                $done = DB::transaction(function () use ($id, $actor, $note) {
                    $delivery = WebhookDelivery::query()->lockForUpdate()->find($id);
                    if ($delivery === null || ! $delivery->isFailed() || $delivery->isAcknowledged()) {
                        return false;
                    }
                    $delivery->update(['acknowledged_at' => now(), 'acknowledged_by' => $actor->id]);
                    $endpoint = WebhookEndpoint::query()->lockForUpdate()->find($delivery->webhook_endpoint_id);
                    if ($endpoint !== null && $endpoint->failure_count > 0) {
                        $endpoint->decrement('failure_count');
                    }
                    $this->audit->record(AuditAction::WebhookAcknowledged, 'enterprise', $endpoint, [], $note, actor: $actor,
                        metadata: ['event' => $delivery->event, 'event_id' => $delivery->event_id, 'delivery_id' => $delivery->id, 'response_code' => $delivery->response_code]);

                    return true;
                });
                $counts[$done ? 'acknowledged' : 'skipped']++;
            }

            return $counts['acknowledged'];
        });

        return $counts;
    }

    /**
     * SV-26: a new signing secret at once; the previous one keeps signing a second header (X-PeopleOS-Signature-Previous)
     * for the grace period, so the receiver can switch without missing events. Returns the new secret (shown once).
     */
    public function rotateSecret(WebhookEndpoint $endpoint, User $actor, int $graceHours, string $reason): string
    {
        $this->authorise($actor);
        if ($graceHours < self::GRACE_HOURS_MIN || $graceHours > self::GRACE_HOURS_MAX) {
            throw new RuntimeException('The old secret\'s grace period must be between '.self::GRACE_HOURS_MIN.' and '.self::GRACE_HOURS_MAX.' hours.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for rotating the secret.');
        }
        $secret = Str::random(48);
        DB::transaction(function () use ($endpoint, $graceHours, $reason, $secret) {
            $locked = WebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->getKey());
            $locked->previous_secret = (string) $locked->secret;
            $locked->previous_secret_expires_at = now()->addHours($graceHours);
            $locked->secret_rotated_at = now();
            $locked->secret = $secret;
            $locked->withAuditAction(AuditAction::SigningSecretRotated)->withAuditReason($reason)->save();
        });
        $endpoint->refresh();

        return $secret;
    }

    /** SV-26: clears previous secrets whose grace period has ended (they never sign after it). Returns how many. */
    public function expirePreviousSecrets(): int
    {
        return WebhookEndpoint::query()->whereNotNull('previous_secret_expires_at')->where('previous_secret_expires_at', '<=', now())
            ->update(['previous_secret' => null, 'previous_secret_expires_at' => null, 'updated_at' => now()]);
    }

    /**
     * SV-26 (event test): posts a sample of the chosen event at once and returns the delivery with its outcome. "ping" (the
     * default) sends the connection test it always sent; any other event sends its WebhookSamples data marked "test".
     */
    public function sendTest(WebhookEndpoint $endpoint, string $event = WebhookSamples::PING): WebhookDelivery
    {
        if (! WebhookSamples::exists($event)) {
            throw new RuntimeException("Unknown webhook event [{$event}].");
        }
        $eventId = (string) Str::ulid();
        $payload = $event === WebhookSamples::PING
            ? ['event' => 'ping', 'occurred_at' => now()->toIso8601String(), 'data' => WebhookSamples::data($event)]
            : ['id' => $eventId, 'event' => $event, 'occurred_at' => now()->toIso8601String(), 'tenant' => $this->tenants->current()?->slug, 'correlation_id' => $eventId,
                'subject' => null, 'data' => WebhookSamples::data($event), 'test' => true];
        $delivery = WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'event_id' => $eventId, 'payload' => $payload, 'status' => 'pending', 'next_attempt_at' => now()]);
        $this->attempt($delivery);

        return $delivery;
    }

    public function attempt(WebhookDelivery $delivery): string
    {
        $endpoint = $delivery->endpoint;
        $json = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $headers = [
            'Content-Type' => 'application/json', 'User-Agent' => 'PeopleOS-Webhooks/1.0',
            'X-PeopleOS-Event' => $delivery->event, 'X-PeopleOS-Delivery' => $delivery->event_id,
            'X-PeopleOS-Timestamp' => $timestamp, 'X-PeopleOS-Signature' => Signature::sign((string) $endpoint->secret, $timestamp, $json),
            'X-Correlation-Id' => (string) ($delivery->correlation_id ?? $delivery->event_id),
        ];
        // SV-26: during a rotation's grace period, the same body is also signed with the previous secret.
        $previous = $endpoint->previousSecretInGrace();
        if ($previous !== null) {
            $headers[self::PREVIOUS_SIGNATURE_HEADER] = Signature::sign($previous, $timestamp, $json);
        }
        $started = hrtime(true);

        try {
            // Production readiness closure: SSRF guard after DNS, connection pinned, no redirects.
            $response = app(SafeHttp::class)->to($endpoint->url)->timeout(10)->withHeaders($headers)->withBody($json, 'application/json')->post($endpoint->url);
            $code = $response->status();
            $excerpt = Str::limit($response->body(), 500);
        } catch (UnsafeOutboundUrl $e) {
            $code = 0;
            $excerpt = 'Blocked: '.$e->getMessage();
            $this->audit->record(AuditAction::OutboundDestinationBlocked, 'enterprise', $endpoint, [], null, metadata: ['reason' => $e->reason, 'destination' => SafeHttp::redact((string) $endpoint->url), 'event' => $delivery->event]);
        } catch (\Throwable $e) {
            $code = 0;
            // Transport errors can quote the full URL (paths may carry tokens): keep only scheme and host.
            $excerpt = Str::limit(str_replace((string) $endpoint->url, SafeHttp::redact((string) $endpoint->url), $e->getMessage()), 500);
        }
        $duration = (int) max(0, round((hrtime(true) - $started) / 1_000_000));

        $ok = $code >= 200 && $code < 300;
        $attempt = $delivery->attempts + 1;
        $delivery->update(['attempts' => $attempt, 'response_code' => $code ?: null, 'response_excerpt' => $excerpt, 'duration_ms' => $duration, 'status' => $ok ? 'delivered' : 'pending', 'delivered_at' => $ok ? now() : null]);
        WebhookDeliveryAttempt::create(['tenant_id' => $delivery->tenant_id, 'webhook_delivery_id' => $delivery->id, 'attempt' => $attempt, 'response_code' => $code ?: null,
            'duration_ms' => $duration, 'response_excerpt' => $excerpt, 'signed_with_previous' => $previous !== null, 'attempted_at' => now()]);
        if ($ok) {
            $endpoint->update(['last_delivered_at' => now()]);
        }

        return $ok ? 'delivered' : 'retry';
    }

    /**
     * SV-26 ("Error breakdown"): unacknowledged dead letters by HTTP status ("No response" for transport errors and
     * blocked destinations), optionally for one endpoint.
     *
     * @return array<string, int>
     */
    public function errorBreakdown(?int $endpointId = null): array
    {
        return WebhookDelivery::query()->whereIn('status', WebhookDelivery::FAILED)->whereNull('acknowledged_at')
            ->when($endpointId !== null, fn ($q) => $q->where('webhook_endpoint_id', $endpointId))
            ->selectRaw('response_code, count(*) as aggregate')->groupBy('response_code')->orderByDesc('aggregate')->toBase()->get()
            ->mapWithKeys(fn ($row) => [$row->response_code === null ? 'No response' : 'HTTP '.$row->response_code => (int) $row->aggregate])->all();
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

    private function authorise(User $actor): void
    {
        if (! $actor->hasPermission('webhook.manage')) {
            throw new RuntimeException('This needs webhook.manage.');
        }
    }

    /**
     * @param  iterable<int|WebhookDelivery>  $deliveries
     * @return list<int>
     */
    private function ids(iterable $deliveries): array
    {
        $ids = [];
        foreach ($deliveries as $delivery) {
            $ids[] = (int) ($delivery instanceof WebhookDelivery ? $delivery->getKey() : $delivery);
        }

        return array_values(array_unique($ids));
    }
}
