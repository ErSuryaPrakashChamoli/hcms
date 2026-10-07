<?php

namespace App\Domain\Payments\Providers;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Exceptions\PaymentProviderException;
use App\Domain\Payments\Exceptions\WebhookRejectedException;
use App\Domain\Payments\Support\PaymentStart;
use App\Domain\Payments\Support\ProviderCheckout;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Payments\Support\ProviderRefund;
use App\Domain\Payments\Support\RefundStart;
use App\Domain\Payments\Support\VerifiedProviderEvent;
use App\Support\Money\Currency;

/**
 * SaaS.7: bank transfers (NEFT, RTGS, SWIFT, SEPA …) received outside PeopleOS and recorded by an operator with the
 * bank reference: controlled operator reconciliation. Nothing is started, fetched or received by webhook.
 */
final class ManualBankTransferProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Bank transfer (recorded by an operator)';
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return true;
    }

    public function methods(): array
    {
        return [PaymentMethod::BankTransfer];
    }

    public function startsPayments(): bool
    {
        return false;
    }

    public function acceptsWebhooks(): bool
    {
        return false;
    }

    public function start(PaymentStart $start): ProviderCheckout
    {
        throw new PaymentProviderException('Bank transfers are not started by PeopleOS: record the transfer once it is received.');
    }

    public function fetch(string $providerReference): ?ProviderPaymentUpdate
    {
        return null;
    }

    public function verifyWebhook(string $rawBody, array $headers): VerifiedProviderEvent
    {
        throw new WebhookRejectedException('no_webhooks');
    }

    public function interpret(VerifiedProviderEvent $event): ?ProviderPaymentUpdate
    {
        return null;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function refund(RefundStart $refund): ProviderRefund
    {
        throw new PaymentProviderException('A bank transfer is refunded by a transfer made outside PeopleOS: record it when it is sent.');
    }

    public function fetchRefund(string $paymentReference, ?string $transactionReference, string $providerRefundReference): ?ProviderRefund
    {
        return null;
    }
}
