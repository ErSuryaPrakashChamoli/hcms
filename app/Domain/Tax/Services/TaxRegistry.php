<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Contracts\TaxDeterminer;
use App\Domain\Tax\Contracts\TaxIdValidator;
use App\Domain\Tax\Contracts\TaxPresentation;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Jurisdictions\India\GstinValidator;
use App\Domain\Tax\Jurisdictions\India\GstStates;
use App\Domain\Tax\Jurisdictions\India\IndiaGstDeterminer;
use App\Domain\Tax\Jurisdictions\India\IndiaGstPresentation;

/**
 * SaaS.7: the one place jurisdiction modules are wired in. Only India GST is implemented (the jurisdiction approved
 * for SaaS.7); a regime without a determiner refuses every invoice. Nothing outside the Tax domain may reference a
 * jurisdiction module (architecture test). Not final: tests register test-only determiners for other regimes to
 * prove the billing, invoice and tax-line model is regime-neutral; production wires India only.
 */
class TaxRegistry
{
    /** @var array<string, class-string<TaxDeterminer>> */
    private const DETERMINERS = [TaxRegime::InGst->value => IndiaGstDeterminer::class];

    /** @var array<string, class-string<TaxPresentation>> */
    private const PRESENTATIONS = [TaxRegime::InGst->value => IndiaGstPresentation::class];

    /** @var array<string, class-string<TaxIdValidator>> */
    private const VALIDATORS = [TaxIdType::InGstin->value => GstinValidator::class];

    public function determiner(TaxRegime $regime): ?TaxDeterminer
    {
        $class = self::DETERMINERS[$regime->value] ?? null;

        return $class === null ? null : app($class);
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

    /** Special tax statuses a jurisdiction module recognises (e.g. India SEZ, UIN); none elsewhere. @return array<string, string> */
    public function specialStatuses(string $country): array
    {
        return $country === 'IN' ? ['sez' => 'SEZ unit or developer (zero-rating not configured)', 'uin' => 'UIN holder: UN body or embassy (not configured)'] : [];
    }

    /** Subdivision options a jurisdiction module knows (for selects); empty when none is implemented. @return array<string, string> */
    public function subdivisions(string $country): array
    {
        // Former ISO codes stay accepted through canonical(); the options list the current ones.
        return $country === 'IN' ? GstStates::options() : [];
    }
}
