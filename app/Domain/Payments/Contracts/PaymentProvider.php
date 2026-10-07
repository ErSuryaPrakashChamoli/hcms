<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Exceptions\PaymentProviderException;
use App\Domain\Payments\Exceptions\WebhookRejectedException;
use App\Domain\Payments\Support\PaymentStart;
use App\Domain\Payments\Support\ProviderCheckout;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Payments\Support\VerifiedProviderEvent;
use App\Support\Money\Currency;

/**
 * SaaS.7: the payment provider boundary. Invoices, subscriptions, tax, tenant lifecycle and entitlements never
 * know a provider; a provider adapter never touches them. Provider SDKs, field names and event names live only in
 * the adapter. Confirmation comes only from verifyWebhook()/interpret() on a signed event or from fetch() on the
 * server, never from a browser redirect.
 */
interface PaymentProvider
{
    public function key(): string;

    public function label(): string;

    public function supportsCurrency(Currency $currency): bool;

    /** @return list<PaymentMethod> */
    public function methods(): array;

    /** Whether payments are started at the provider (false for operator-recorded transfers). */
    public function startsPayments(): bool;

    public function acceptsWebhooks(): bool;

    /** Idempotent on $start->idempotencyKey. @throws PaymentProviderException */
    public function start(PaymentStart $start): ProviderCheckout;

    /** The provider's current view of a payment, fetched server-side; null when it does not know it. */
    public function fetch(string $providerReference): ?ProviderPaymentUpdate;

    /** @param  array<string, list<string|null>>  $headers  @throws WebhookRejectedException */
    public function verifyWebhook(string $rawBody, array $headers): VerifiedProviderEvent;

    /** Null when the event is not about a payment PeopleOS handles (it is then ignored). */
    public function interpret(VerifiedProviderEvent $event): ?ProviderPaymentUpdate;
}
