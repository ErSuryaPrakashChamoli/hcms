<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Providers\ManualBankTransferProvider;
use App\Domain\Payments\Providers\RazorpayProvider;
use App\Domain\Payments\Providers\SandboxProvider;

/**
 * SaaS.7: the payment providers enabled here. Operator-recorded bank transfers are always available (B-10: bank
 * transfer now); the sandbox only when configured and never in production; Razorpay (B-10: next) only in test mode,
 * with rzp_test_ keys, never in production. No live provider can be enabled by configuration alone.
 */
final class ProviderRegistry
{
    /** @return array<string, PaymentProvider> */
    public function all(): array
    {
        $providers = ['manual' => app(ManualBankTransferProvider::class)];
        if ((bool) config('peopleos.billing.sandbox.enabled', false) && ! app()->environment('production')) {
            $providers['sandbox'] = app(SandboxProvider::class);
        }
        if (RazorpayProvider::testKeysConfigured()) {
            $providers['razorpay'] = app(RazorpayProvider::class);
        }

        return $providers;
    }

    public function get(string $key): ?PaymentProvider
    {
        return $this->all()[$key] ?? null;
    }
}
