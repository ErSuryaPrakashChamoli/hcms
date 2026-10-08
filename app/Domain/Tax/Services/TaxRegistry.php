<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Contracts\TaxIdValidator;
use App\Domain\Tax\Contracts\TaxPresentation;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Jurisdictions\EuropeanUnion\EuVatDestination;
use App\Domain\Tax\Jurisdictions\India\GstinValidator;
use App\Domain\Tax\Jurisdictions\India\GstStates;
use App\Domain\Tax\Jurisdictions\India\IndiaGstDeterminer;
use App\Domain\Tax\Jurisdictions\India\IndiaGstPresentation;
use App\Domain\Tax\Jurisdictions\UnitedArabEmirates\UaeVatDestination;
use App\Domain\Tax\Jurisdictions\UnitedKingdom\UkVatDestination;
use App\Domain\Tax\Jurisdictions\UnitedStates\UsSalesTaxDestination;
use App\Domain\Tax\Jurisdictions\UnitedStates\UsStates;

/**
 * SaaS.7: the one place jurisdiction modules are wired in. Supplier side: India GST only (Markedge's Indian entity
 * sells everywhere, B-7). Destination side (a supply abroad: what the customer's jurisdiction requires): UK VAT, the
 * VAT of each EU member state, UAE VAT and US state sales tax. A regime without the needed module refuses the
 * invoice. Modules hold logic only; every rate, citation and condition is rule data. Nothing outside the Tax domain
 * may reference a jurisdiction module (architecture test); tests register test-only supplier determiners.
 */
class TaxRegistry
{
    /** @var array<string, class-string<TaxDeterminer>> */
    private const DETERMINERS = [TaxRegime::InGst->value => IndiaGstDeterminer::class];

    /** @var array<string, class-string<TaxDeterminer>> the customer jurisdiction's treatment of a supply from abroad */
    private const DESTINATIONS = [TaxRegime::GbVat->value => UkVatDestination::class, TaxRegime::EuVat->value => EuVatDestination::class,
        TaxRegime::AeVat->value => UaeVatDestination::class, TaxRegime::UsSalesTax->value => UsSalesTaxDestination::class];

    /** @var array<string, class-string<TaxPresentation>> */
    private const PRESENTATIONS = [TaxRegime::InGst->value => IndiaGstPresentation::class];

    /** @var array<string, class-string<TaxIdValidator>> */
    private const VALIDATORS = [TaxIdType::InGstin->value => GstinValidator::class];

    public function determiner(TaxRegime $regime): ?TaxDeterminer
    {
        $class = self::DETERMINERS[$regime->value] ?? null;

        return $class === null ? null : app($class);
    }

    public function destination(TaxRegime $regime): ?TaxDeterminer
    {
        $class = self::DESTINATIONS[$regime->value] ?? null;

        return $class === null ? null : app($class);
    }

    /** Every outcome a rule of $regime may price: the supplier side's and the destination side's. @return array<string, string> */
    public function outcomes(TaxRegime $regime): array
    {
        return ($this->determiner($regime)?->outcomes() ?? []) + ($this->destination($regime)?->outcomes() ?? []);
    }

    public function presentation(TaxRegime $regime): ?TaxPresentation
    {
        $class = self::PRESENTATIONS[$regime->value] ?? null;

        return $class === null ? null : app($class);
    }

    public function validator(TaxIdType $type): ?TaxIdValidator
    {
        $class = self::VALIDATORS[$type->value] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * Special tax statuses a customer may have (e.g. India SEZ, UIN), plus, anywhere, being an establishment of the
     * supplier itself (a "distinct person": a supply to it is not an export). @return array<string, string>
     */
    public function specialStatuses(string $country): array
    {
        return ($country === 'IN' ? ['sez' => 'SEZ unit or developer (zero-rating not configured)', 'uin' => 'UIN holder: UN body or embassy (not configured)'] : [])
            + ['supplier_establishment' => 'An establishment of the supplier itself (not an export: a distinct person)'];
    }

    /** Subdivision options a jurisdiction module knows (for selects); empty when none is implemented. @return array<string, string> */
    public function subdivisions(string $country): array
    {
        // Former ISO codes stay accepted through canonical(); the options list the current ones.
        return match ($country) {
            'IN' => GstStates::options(),
            'US' => UsStates::options(),
            default => [],
        };
    }
}
