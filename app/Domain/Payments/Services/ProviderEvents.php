<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Exceptions\WebhookRejectedException;
use App\Domain\Payments\Jobs\ApplyProviderEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Payments\Support\VerifiedProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS.7: the payment-provider webhook pipeline (platform-level, unauthenticated by session, authenticated by the
 * provider's signature). Verify the raw body → store the event once per (provider, event id) with its encrypted
 * payload and SHA-256 (a duplicate is a no-op; a different body under a known id is refused) → find the payment by
 * the verified provider reference (the only way a tenant is ever resolved; nothing in the payload names one) →
 * apply it in a tenant-aware job through the same reconciler as every other confirmation. A scheduled sweep
 * retries what could not be resolved or applied yet. Rejections are logged, not audited, and carry no payload.
 */
final class ProviderEvents
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ProviderRegistry $providers, private readonly PaymentReconciler $reconciler) {}

    /**
     * @param  array<string, list<string|null>>  $headers
     * @return array{0: int, 1: string} HTTP status and a short outcome (never echoing the payload)
     */
    public function receive(string $providerKey, string $rawBody, array $headers, ?string $requestId = null): array
    {
        $provider = $this->providers->get($providerKey);
        if ($provider === null || ! $provider->acceptsWebhooks()) {
            return [404, 'unknown_provider'];
        }
        if (strlen($rawBody) > (int) config('peopleos.billing.webhook_max_bytes', 65536)) {
            return [413, 'too_large'];
        }
        try {
            $verified = $provider->verifyWebhook($rawBody, array_change_key_case($headers, CASE_LOWER));
        } catch (WebhookRejectedException $e) {
            Log::warning('Billing webhook rejected', ['provider' => $providerKey, 'reason' => $e->reasonCode, 'request_id' => $requestId]);

            return [401, 'rejected'];
        }
        $sha = hash('sha256', $rawBody);
        try {
            $event = PaymentProviderEvent::query()->create(['provider' => $providerKey, 'event_id' => $verified->eventId, 'type' => $verified->type,
                'payload' => $verified->payload, 'payload_sha256' => $sha, 'status' => ProviderEventStatus::Received, 'received_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $known = PaymentProviderEvent::query()->where(['provider' => $providerKey, 'event_id' => $verified->eventId])->first();
            if ($known !== null && hash_equals($known->payload_sha256, $sha)) {
                return [200, 'duplicate'];
            }
            Log::alert('Billing webhook conflict: a different body under a known event id', ['provider' => $providerKey, 'event_id' => $verified->eventId, 'request_id' => $requestId]);

            return [409, 'conflict'];
        }
        $update = $provider->interpret($verified);
        if ($update === null) {
            // B-12: chargebacks (disputes) are not processed in SaaS.7 (no live provider; deferred to the provider's
            // activation). Such an event is stored, marked and logged for an operator, never silently dropped.
            $outcome = match (true) {
                preg_match('/dispute|chargeback/i', $verified->type) === 1 => 'chargeback_not_processed',
                preg_match('/^refund\./i', $verified->type) === 1 => 'refund_event_not_processed',
                default => 'not_a_payment_event',
            };
            if ($outcome === 'chargeback_not_processed') {
                Log::warning('Billing webhook: a chargeback (dispute) event was received; chargebacks are not processed yet (B-12): handle it manually.',
                    ['provider' => $providerKey, 'event_id' => $verified->eventId, 'type' => $verified->type, 'request_id' => $requestId]);
            }
            $event->forceFill(['status' => ProviderEventStatus::Ignored, 'outcome' => $outcome, 'processed_at' => now()])->save();

            return [200, 'ignored'];
        }
        $this->route($event, $update);

        return [200, 'accepted'];
    }

    /** Applies a routed event inside its tenant (called by ApplyProviderEvent). Claimed with a lease; idempotent. */
    public function apply(int $eventId): void
    {
        $claimed = PaymentProviderEvent::query()->whereKey($eventId)->whereIn('status', [ProviderEventStatus::Received, ProviderEventStatus::Failed])
            ->whereNotNull('payment_id')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', now()))
            ->update(['status' => ProviderEventStatus::Processing, 'attempts' => DB::raw('attempts + 1'), 'claimed_until' => now()->addMinutes(5), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $event = PaymentProviderEvent::query()->findOrFail($eventId);
        try {
            $payment = Payment::query()->findOrFail($event->payment_id);
            $provider = $this->providers->get($event->provider) ?? throw new \RuntimeException("The provider {$event->provider} is no longer enabled.");
            $update = $provider->interpret(new VerifiedProviderEvent($event->event_id, $event->type, $event->payload ?? []))
                ?? throw new \RuntimeException('The stored event no longer describes a payment.');
            $outcome = $this->reconciler->apply($payment, $update, 'provider', null, "{$event->provider}:{$event->event_id}");
            $status = str_starts_with($outcome, 'exception:') ? ProviderEventStatus::Exception
                : (in_array($outcome, ['duplicate', 'out_of_order'], true) ? ProviderEventStatus::Ignored : ProviderEventStatus::Applied);
            $event->forceFill(['status' => $status, 'outcome' => $outcome, 'processed_at' => now(), 'claimed_until' => null, 'last_error' => null])->save();
        } catch (Throwable $e) {
            $event->forceFill(['status' => ProviderEventStatus::Failed, 'outcome' => 'error', 'claimed_until' => null,
                'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 500)])->save();
            report($e);
        }
    }

    /**
     * Retries what is due: events whose payment was unknown when they arrived (the payment may have been recorded
     * since), events stuck after a crashed worker, and failed events below the attempt limit.
     *
     * @return array{routed: int, dispatched: int}
     */
    public function sweep(): array
    {
        $routed = 0;
        $dispatched = 0;
        PaymentProviderEvent::query()->where('status', ProviderEventStatus::Processing)->where('claimed_until', '<', now())
            ->update(['status' => ProviderEventStatus::Failed, 'claimed_until' => null, 'last_error' => 'lease expired', 'updated_at' => now()]);
        $unresolved = PaymentProviderEvent::query()->where(['status' => ProviderEventStatus::Exception, 'outcome' => 'unknown_reference'])
            ->where('received_at', '>=', now()->subDays(2))->orderBy('id')->limit(500)->get();
        foreach ($unresolved as $event) {
            $provider = $this->providers->get($event->provider);
            $update = $provider?->interpret(new VerifiedProviderEvent($event->event_id, $event->type, $event->payload ?? []));
            if ($update !== null && $this->route($event, $update)) {
                $routed++;
            }
        }
        $due = PaymentProviderEvent::query()->whereNotNull('payment_id')->whereNotNull('resolved_tenant_id')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->where('status', ProviderEventStatus::Failed)
                ->orWhere(fn ($q) => $q->where('status', ProviderEventStatus::Received)->where('received_at', '<', now()->subMinutes(5))))
            ->orderBy('id')->limit(500)->get();
        foreach ($due as $event) {
            ApplyProviderEvent::dispatch($event->resolved_tenant_id, $event->id);
            $dispatched++;
        }

        return ['routed' => $routed, 'dispatched' => $dispatched];
    }

    /** Resolves the payment (and so the tenant) from the verified provider reference only. */
    private function route(PaymentProviderEvent $event, ProviderPaymentUpdate $update): bool
    {
        $payment = Payment::query()->withoutTenancy()->where(['provider' => $event->provider, 'provider_reference' => $update->providerReference])->first();
        if ($payment === null) {
            $event->forceFill(['status' => ProviderEventStatus::Exception, 'outcome' => 'unknown_reference', 'provider_reference' => mb_substr($update->providerReference, 0, 191)])->save();

            return false;
        }
        $event->forceFill(['status' => ProviderEventStatus::Received, 'outcome' => null, 'provider_reference' => $payment->provider_reference,
            'resolved_tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id])->save();
        DB::afterCommit(fn () => ApplyProviderEvent::dispatch($payment->tenant_id, $event->id));

        return true;
    }
}
