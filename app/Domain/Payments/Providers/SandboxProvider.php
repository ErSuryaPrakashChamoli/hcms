<?php

namespace App\Domain\Payments\Providers;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentProviderException;
use App\Domain\Payments\Exceptions\WebhookRejectedException;
use App\Domain\Payments\Support\PaymentStart;
use App\Domain\Payments\Support\ProviderCheckout;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Payments\Support\ProviderRefund;
use App\Domain\Payments\Support\RefundStart;
use App\Domain\Payments\Support\VerifiedProviderEvent;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * SaaS.7: a deterministic test-mode provider (never enabled in production: see ProviderRegistry). It moves no
 * money. Its webhooks are signed like real providers' (HMAC-SHA256 over "timestamp.body" with a shared secret,
 * a timestamp tolerance), so verification, replay protection and idempotency are exercised end to end. Its
 * provider-side state lives in the cache and is set by simulate() (tests and local validation only).
 */
final class SandboxProvider implements PaymentProvider
{
    public const SIGNATURE_HEADER = 'x-sandbox-signature';

    public const TIMESTAMP_HEADER = 'x-sandbox-timestamp';

    public function key(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox (test mode, no money moves)';
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return true;
    }

    public function methods(): array
    {
        return [PaymentMethod::Card, PaymentMethod::BankTransfer, PaymentMethod::Wallet, PaymentMethod::Local];
    }

    public function startsPayments(): bool
    {
        return true;
    }

    public function acceptsWebhooks(): bool
    {
        return true;
    }

    public function start(PaymentStart $start): ProviderCheckout
    {
        if ($start->amount->isNegative() || $start->amount->isZero()) {
            throw new PaymentProviderException('The sandbox collects positive amounts only.');
        }
        $reference = 'sbx_'.substr(hash('sha256', $start->idempotencyKey), 0, 24);
        Cache::add(self::stateKey($reference), ['status' => PaymentStatus::Pending->value, 'amount_minor' => $start->amount->minor, 'currency' => $start->amount->currency->value], now()->addDays(30));

        return new ProviderCheckout($reference);
    }

    public function fetch(string $providerReference): ?ProviderPaymentUpdate
    {
        $state = Cache::get(self::stateKey($providerReference));

        return $state === null ? null : new ProviderPaymentUpdate($providerReference, PaymentStatus::from($state['status']),
            Money::ofMinor((int) $state['amount_minor'], $state['currency']), PaymentMethod::Card, $state['failure_code'] ?? null);
    }

    public function verifyWebhook(string $rawBody, array $headers): VerifiedProviderEvent
    {
        $secret = (string) config('peopleos.billing.sandbox.webhook_secret', '');
        if (strlen($secret) < 16) {
            throw new WebhookRejectedException('not_configured');
        }
        $signature = (string) ($headers[self::SIGNATURE_HEADER][0] ?? '');
        $timestamp = (string) ($headers[self::TIMESTAMP_HEADER][0] ?? '');
        if (preg_match('/^\d{9,11}$/', $timestamp) !== 1 || ! str_starts_with($signature, 'v1=')) {
            throw new WebhookRejectedException('missing_signature');
        }
        if (! hash_equals(hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret), substr($signature, 3))) {
            throw new WebhookRejectedException('bad_signature');
        }
        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('peopleos.billing.sandbox.tolerance_seconds', 300)) {
            throw new WebhookRejectedException('stale_timestamp');
        }
        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null) || preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', $payload['id']) !== 1) {
            throw new WebhookRejectedException('malformed');
        }

        return new VerifiedProviderEvent($payload['id'], mb_substr($payload['type'], 0, 64), $payload);
    }

    public function interpret(VerifiedProviderEvent $event): ?ProviderPaymentUpdate
    {
        $status = match ($event->type) {
            'payment.succeeded' => PaymentStatus::Succeeded,
            'payment.failed' => PaymentStatus::Failed,
            'payment.cancelled' => PaymentStatus::Cancelled,
            'payment.pending' => PaymentStatus::Pending,
            default => null,
        };
        $data = $event->payload['data'] ?? [];
        if ($status === null || ! is_array($data) || ! is_string($data['reference'] ?? null)) {
            return null;
        }
        try {
            $amount = isset($data['amount_minor'], $data['currency']) ? Money::ofMinor((int) $data['amount_minor'], (string) $data['currency']) : null;
        } catch (InvalidArgumentException) {
            $amount = null; // an unknown currency can never match an invoice: reconciliation records the exception
        }

        return new ProviderPaymentUpdate($data['reference'], $status, $amount, PaymentMethod::tryFrom((string) ($data['method'] ?? '')) ?? PaymentMethod::Other,
            isset($data['failure_code']) ? mb_substr((string) $data['failure_code'], 0, 32) : null,
            isset($data['failure_message']) ? mb_substr((string) $data['failure_message'], 0, 300) : null);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /** Deterministic: the same refund reference always gives the same sandbox refund (no second refund on a retry). */
    public function refund(RefundStart $refund): ProviderRefund
    {
        $state = Cache::get(self::stateKey($refund->paymentReference));
        if ($state === null || $state['status'] !== PaymentStatus::Succeeded->value) {
            throw new PaymentProviderException('The sandbox refunds succeeded payments only.');
        }
        $reference = 'rfnd_sbx_'.substr(hash('sha256', $refund->refundReference), 0, 20);
        Cache::add('billing.sandbox.refund.'.$reference, ['status' => 'succeeded'], now()->addDays(30));

        return new ProviderRefund($reference, (string) Cache::get('billing.sandbox.refund.'.$reference)['status']);
    }

    public function fetchRefund(string $paymentReference, ?string $transactionReference, string $providerRefundReference): ?ProviderRefund
    {
        $state = Cache::get('billing.sandbox.refund.'.$providerRefundReference);

        return $state === null ? null : new ProviderRefund($providerRefundReference, $state['status']);
    }

    /** Test and local validation helper: the provider-side outcome of a sandbox payment. */
    public static function simulate(string $reference, PaymentStatus $status, Money $amount, ?string $failureCode = null): void
    {
        Cache::put(self::stateKey($reference), ['status' => $status->value, 'amount_minor' => $amount->minor, 'currency' => $amount->currency->value, 'failure_code' => $failureCode], now()->addDays(30));
    }

    /** Test and local validation helper: the headers a correctly signed sandbox webhook carries. @return array<string, string> */
    public static function signedHeaders(string $body, ?int $timestamp = null, ?string $secret = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $secret ??= (string) config('peopleos.billing.sandbox.webhook_secret', '');

        return ['X-Sandbox-Timestamp' => (string) $timestamp, 'X-Sandbox-Signature' => 'v1='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret)];
    }

    private static function stateKey(string $reference): string
    {
        return 'billing.sandbox.payment.'.$reference;
    }
}
