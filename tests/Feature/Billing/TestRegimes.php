<?php

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Contracts\TaxPresentation;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Services\JurisdictionCatalogue;
use App\Domain\Tax\Services\TaxRegistry;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxDetermination;
use App\Domain\Tax\Support\TaxJurisdiction;

/*
 | SaaS.7 TEST-ONLY regimes. They are not tax rules and are never wired in production: they exist to prove that the
 | engine, invoices and tax lines carry VAT and sales tax without GST-specific columns or code paths.
 */

final class TestGbVatDeterminer implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::GbVat;
    }

    public function outcomes(): array
    {
        return ['domestic' => 'UK customer', 'reverse_charge' => 'EU business customer'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $customer = $context->customer;
        if ($customer->jurisdiction->country === 'GB') {
            return new TaxDetermination(TaxRegime::GbVat, TaxTreatment::Standard, 'domestic', new TaxJurisdiction('GB'), 'Test: UK customer');
        }
        if (in_array($customer->jurisdiction->country, JurisdictionCatalogue::EU_MEMBERS, true) && $customer->customerType === CustomerType::Business && $customer->taxIdValue !== null) {
            return new TaxDetermination(TaxRegime::GbVat, TaxTreatment::ReverseCharge, 'reverse_charge', $customer->jurisdiction, 'Test: business customer accounts for VAT');
        }
        throw new TaxUnavailableException('test_not_configured', 'Test regime: not configured for this customer.');
    }
}

final class TestUsSalesTaxDeterminer implements TaxDeterminer
{
    public function regime(): TaxRegime
    {
        return TaxRegime::UsSalesTax;
    }

    public function outcomes(): array
    {
        return ['state' => 'Destination state'];
    }

    public function determine(TaxContext $context): TaxDetermination
    {
        $place = $context->customer->jurisdiction;
        if ($place->country !== 'US' || $place->subdivision === null) {
            throw new TaxUnavailableException('test_not_configured', 'Test regime: needs a US state.');
        }

        return new TaxDetermination(TaxRegime::UsSalesTax, TaxTreatment::Standard, 'state', $place, 'Test: destination state');
    }
}

final class TestVatPresentation implements TaxPresentation
{
    public function regime(): TaxRegime
    {
        return TaxRegime::GbVat;
    }

    public function rows(array $taxSnapshot): array
    {
        return [['label' => 'VAT treatment', 'value' => (string) ($taxSnapshot['determination']['treatment'] ?? '')]];
    }
}

final class TestTaxRegistry extends TaxRegistry
{
    public function determiner(TaxRegime $regime): ?TaxDeterminer
    {
        return match ($regime) {
            TaxRegime::GbVat => new TestGbVatDeterminer,
            TaxRegime::UsSalesTax => new TestUsSalesTaxDeterminer,
            default => parent::determiner($regime),
        };
    }

    public function presentation(TaxRegime $regime): ?TaxPresentation
    {
        return $regime === TaxRegime::GbVat ? new TestVatPresentation : parent::presentation($regime);
    }
}

function useTestRegimes(): void
{
    app()->instance(TaxRegistry::class, new TestTaxRegistry);
    foreach ([\App\Domain\Tax\Services\TaxRules::class, \App\Domain\Tax\Services\TaxEngine::class] as $service) {
        app()->forgetInstance($service);
    }
}
