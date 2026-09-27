<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Outbound webhooks (§88): queue a delivery per subscribed endpoint, sign with HMAC-SHA256, retry with backoff. */
final class Webhooks
{
    /** @param  array<string, mixed>  $payload */
    public function publish(string $event, array $payload, ?Model $subject = null): int
    {
        $eventId = (string) Str::ulid();
        $body = ['id' => $eventId, 'event' => $event, 'occurred_at' => now()->toIso8601String(), 'subject' => $subject ? ['type' => $subject->getMorphClass(), 'id' => $subject->getKey()] : null, 'data' => $payload];
        $queued = 0;

        foreach (WebhookEndpoint::query()->where('status', 'active')->get() as $endpoint) {
            if (! $endpoint->subscribedTo($event)) {
                continue;
            }
            WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'event_id' => $eventId, 'payload' => $body, 'status' => 'pending', 'next_attempt_at' => now()]);
            $queued++;
        }

        return $queued;
    }

    /** Attempt every due delivery once; returns [delivered, failed, retrying]. */
    public function deliverDue(int $limit = 100): array
    {
        $result = ['delivered' => 0, 'failed' => 0, 'retrying' => 0];
        $max = (int) config('peopleos.enterprise.webhook_max_attempts', 5);

        WebhookDelivery::query()->with('endpoint')->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->get()->each(function (WebhookDelivery $delivery) use (&$result, $max) {
            $outcome = $this->attempt($delivery);
            if ($outcome === 'delivered') {
                $result['delivered']++;
            } elseif ($delivery->attempts >= $max) {
                $delivery->update(['status' => 'failed']);
                $delivery->endpoint->increment('failure_count');
                $result['failed']++;
            } else {
                $delivery->update(['next_attempt_at' => now()->addMinutes(2 ** $delivery->attempts)]);
                $result['retrying']++;
            }
        });

        return $result;
    }

    public function attempt(WebhookDelivery $delivery): string
    {
        $endpoint = $delivery->endpoint;
        $json = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$json, $endpoint->secret);

        try {
            $response = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json', 'User-Agent' => 'PeopleOS-Webhooks/1.0',
                'X-PeopleOS-Event' => $delivery->event, 'X-PeopleOS-Delivery' => $delivery->event_id,
                'X-PeopleOS-Timestamp' => $timestamp, 'X-PeopleOS-Signature' => 'sha256='.$signature,
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

    public static function verify(string $secret, string $timestamp, string $body, string $signatureHeader): bool
    {
        return hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signatureHeader);
    }
}
