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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SaaS.7 completion (B-10, B-14): Razorpay, in TEST MODE ONLY (ProviderRegistry enables it only with rzp_test_ keys
 * and never in production; the constructor refuses anything else). No SDK: the documented REST API over HTTPS with
 * basic auth, from configuration (secrets from the environment, never stored or logged).
 *
 * - start: an order (amount in minor units, currency, receipt = the payment's ULID, notes). Idempotent: a retry
 *   first looks the order up by its receipt. The order id is the payment's provider reference.
 * - Confirmation: payment.captured / order.paid webhooks (X-Razorpay-Signature = hex HMAC-SHA256 of the raw body
 *   with the webhook secret; X-Razorpay-Event-Id is the event id, unique per event, which is the replay defence as
 *   the signature carries no timestamp) or a server-side fetch of the order's payments. payment.failed concerns one
 *   attempt: the order stays open (pending), so a later capture still settles it.
 * - Settlement (B-14): Razorpay settles INR. For a payment in another currency the captured payment's base_amount /
 *   base_currency (the INR value before fees) is recorded as the settlement snapshot; the invoice and payment amounts
 *   stay in the invoice currency.
 * - Refunds: POST /payments/{id}/refund with receipt = the refund's ULID; a retry looks the refund up first.
 */
final class RazorpayProvider implements PaymentProvider
{
    public const SIGNATURE_HEADER = 'x-razorpay-signature';

    public const EVENT_ID_HEADER = 'x-razorpay-event-id';

    public function __construct()
    {
        if (! self::testKeysConfigured()) {
            throw new PaymentProviderException('Razorpay is available in test mode only (rzp_test_ keys, outside production).');
        }
    }

    /** Test keys, a secret and a webhook secret configured, outside production. */
    public static function testKeysConfigured(): bool
    {
        $c = (array) config('peopleos.billing.razorpay', []);

        return (bool) ($c['enabled'] ?? false) && ! app()->environment('production')
            && str_starts_with((string) ($c['key_id'] ?? ''), 'rzp_test_') && strlen((string) ($c['key_secret'] ?? '')) >= 8
            && strlen((string) ($c['webhook_secret'] ?? '')) >= 16;
    }

    public function key(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return 'Razorpay (test mode)';
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return in_array($currency->value, (array) config('peopleos.billing.razorpay.currencies', ['INR']), true);
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
            throw new PaymentProviderException('Razorpay collects positive amounts only.');
        }
        $receipt = substr($start->paymentReference, 0, 40);
        $existing = $this->call(fn (PendingRequest $http) => $http->get('/orders', ['receipt' => $receipt, 'count' => 10]));
        foreach ((array) ($existing['items'] ?? []) as $order) {
            if (($order['receipt'] ?? null) === $receipt && (int) ($order['amount'] ?? -1) === $start->amount->minor && ($order['currency'] ?? null) === $start->amount->currency->value) {
                return new ProviderCheckout((string) $order['id']);
            }
        }
        $order = $this->call(fn (PendingRequest $http) => $http->post('/orders', ['amount' => $start->amount->minor, 'currency' => $start->amount->currency->value,
            'receipt' => $receipt, 'notes' => ['payment_reference' => $start->paymentReference, 'description' => mb_substr($start->description, 0, 250)]]));
        if (! is_string($order['id'] ?? null) || ! str_starts_with($order['id'], 'order_')) {
            throw new PaymentProviderException('Razorpay did not return an order.');
        }

        return new ProviderCheckout($order['id']);
    }

    public function fetch(string $providerReference): ?ProviderPaymentUpdate
    {
        if (preg_match('/^order_[A-Za-z0-9]{1,40}$/', $providerReference) !== 1) {
            return null;
        }
        $payments = (array) ($this->call(fn (PendingRequest $http) => $http->get("/orders/{$providerReference}/payments"), allowMissing: true)['items'] ?? []);
        $captured = collect($payments)->first(fn ($p) => ($p['status'] ?? null) === 'captured');

        return $captured !== null ? $this->update($captured, PaymentStatus::Succeeded) : new ProviderPaymentUpdate($providerReference, PaymentStatus::Pending);
    }

    public function verifyWebhook(string $rawBody, array $headers): VerifiedProviderEvent
    {
        $secret = (string) config('peopleos.billing.razorpay.webhook_secret', '');
        if (strlen($secret) < 16) {
            throw new WebhookRejectedException('not_configured');
        }
        $signature = (string) ($headers[self::SIGNATURE_HEADER][0] ?? '');
        $eventId = (string) ($headers[self::EVENT_ID_HEADER][0] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new WebhookRejectedException('missing_signature');
        }
        if (! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            throw new WebhookRejectedException('bad_signature');
        }
        $payload = json_decode($rawBody, true);
        if (preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', $eventId) !== 1 || ! is_array($payload) || ($payload['entity'] ?? null) !== 'event' || ! is_string($payload['event'] ?? null)) {
            throw new WebhookRejectedException('malformed');
        }

        return new VerifiedProviderEvent($eventId, mb_substr($payload['event'], 0, 64), $payload);
    }

    public function interpret(VerifiedProviderEvent $event): ?ProviderPaymentUpdate
    {
        $status = match ($event->type) {
            'payment.captured', 'order.paid' => PaymentStatus::Succeeded,
            'payment.authorized', 'payment.failed' => PaymentStatus::Pending,   // an attempt; the order stays open
            default => null,
        };
        $payment = $event->payload['payload']['payment']['entity'] ?? null;
        if ($status === null || ! is_array($payment) || ! is_string($payment['order_id'] ?? null)) {
            return null;
        }

        return $this->update($payment, $status);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(RefundStart $refund): ProviderRefund
    {
        $paymentId = $refund->transactionReference;
        if ($paymentId === null || preg_match('/^pay_[A-Za-z0-9]{1,40}$/', $paymentId) !== 1) {
            throw new PaymentProviderException('The captured Razorpay payment is not known: refresh the payment first.');
        }
        $receipt = substr($refund->refundReference, 0, 40);
        $existing = (array) ($this->call(fn (PendingRequest $http) => $http->get("/payments/{$paymentId}/refunds", ['count' => 100]))['items'] ?? []);
        $known = collect($existing)->first(fn ($r) => ($r['receipt'] ?? null) === $receipt || (($r['notes']['refund_reference'] ?? null) === $refund->refundReference));
        $result = $known ?? $this->call(fn (PendingRequest $http) => $http->post("/payments/{$paymentId}/refund", ['amount' => $refund->amount->minor,
            'receipt' => $receipt, 'notes' => ['refund_reference' => $refund->refundReference]]));

        return $this->refundResult($result);
    }

    public function fetchRefund(string $paymentReference, ?string $transactionReference, string $providerRefundReference): ?ProviderRefund
    {
        if ($transactionReference === null || preg_match('/^rfnd_[A-Za-z0-9]{1,40}$/', $providerRefundReference) !== 1) {
            return null;
        }
        $refund = $this->call(fn (PendingRequest $http) => $http->get("/payments/{$transactionReference}/refunds/{$providerRefundReference}"), allowMissing: true);

        return $refund === [] ? null : $this->refundResult($refund);
    }

    /** @param  array<string, mixed>  $payment  a Razorpay payment entity */
    private function update(array $payment, PaymentStatus $status): ProviderPaymentUpdate
    {
        try {
            $amount = isset($payment['amount'], $payment['currency']) ? Money::ofMinor((int) $payment['amount'], strtoupper((string) $payment['currency'])) : null;
            $settlement = isset($payment['base_amount'], $payment['base_currency']) ? Money::ofMinor((int) $payment['base_amount'], strtoupper((string) $payment['base_currency']))
                : ($amount?->currency === Currency::INR ? $amount : null);
        } catch (InvalidArgumentException) {
            [$amount, $settlement] = [null, null]; // an unknown currency can never match an invoice: reconciliation records the exception
        }
        $method = match ((string) ($payment['method'] ?? '')) {
            'card', 'emi' => PaymentMethod::Card,
            'netbanking' => PaymentMethod::BankTransfer,
            'wallet' => PaymentMethod::Wallet,
            'upi' => PaymentMethod::Local,
            default => PaymentMethod::Other,
        };
        $failure = $payment['error_code'] ?? null;

        return new ProviderPaymentUpdate((string) $payment['order_id'], $status, $amount, $method, is_string($failure) ? mb_substr($failure, 0, 32) : null,
            is_string($payment['error_description'] ?? null) ? mb_substr($payment['error_description'], 0, 300) : null,
            $status === PaymentStatus::Succeeded ? $settlement : null,
            is_string($payment['id'] ?? null) ? mb_substr($payment['id'], 0, 191) : null,
            $status === PaymentStatus::Succeeded && $settlement !== null ? 'razorpay:base_amount' : null);
    }

    /** @param  array<string, mixed>  $refund */
    private function refundResult(array $refund): ProviderRefund
    {
        if (! is_string($refund['id'] ?? null)) {
            throw new PaymentProviderException('Razorpay did not return a refund.');
        }
        $status = match ((string) ($refund['status'] ?? '')) {
            'processed' => 'succeeded',
            'failed' => 'failed',
            default => 'processing',
        };

        return new ProviderRefund($refund['id'], $status, $status === 'failed' ? 'provider_failed' : null);
    }

    /**
     * @param  \Closure(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function call(\Closure $request, bool $allowMissing = false): array
    {
        $c = (array) config('peopleos.billing.razorpay');
        try {
            $response = $request(Http::baseUrl((string) $c['base_url'])->withBasicAuth((string) $c['key_id'], (string) $c['key_secret'])
                ->acceptJson()->asJson()->timeout((int) ($c['timeout_seconds'] ?? 15)));
        } catch (ConnectionException) {
            throw new PaymentProviderException('Razorpay could not be reached.');
        }
        if ($allowMissing && $response->status() === 404) {
            return [];
        }
        if (! $response->successful()) {
            $description = $response->json('error.description');
            throw new PaymentProviderException('Razorpay refused the request ('.$response->status().')'.(is_string($description) ? ': '.mb_substr($description, 0, 200) : '.'));
        }

        return (array) $response->json();
    }
}
