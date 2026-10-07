<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Providers\ManualBankTransferProvider;
use App\Domain\Payments\Providers\SandboxProvider;

/**
 * SaaS.7: the payment providers enabled here. Operator-recorded bank transfers are always available; the sandbox
 * only when configured and never in production. No real provider adapter exists: choosing one is decision B-10.
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

        return $providers;
    }

    public function get(string $key): ?PaymentProvider
    {
        return $this->all()[$key] ?? null;
    }
}
