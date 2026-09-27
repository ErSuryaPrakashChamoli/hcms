<?php

namespace App\Domain\Compliance\Support;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Organisation\Models\Establishment;

/**
 * Where an employee's statutory obligations sit for a payroll period (Phase 5 Parts C, D, J):
 * the establishment (and how it was found), its state, and per-statute applicability — from the
 * establishment profiles when the establishment has any, otherwise from the legacy company profile.
 */
final class StatutoryContext
{
    /** @param  array<string, EstablishmentStatutoryProfile|null>  $profiles */
    public function __construct(
        public readonly ?Establishment $establishment,
        public readonly ?string $establishmentSource,
        public readonly CompanyStatutoryProfile $legacy,
        public readonly array $profiles,
        public readonly bool $usesEstablishmentProfiles,
        public readonly ?string $ptState,
        public readonly ?string $ptStateSource,
        public readonly ?string $lwfState,
    ) {}

    public function jurisdiction(): string
    {
        return strtoupper(($this->usesEstablishmentProfiles ? $this->establishment?->country : null) ?: ($this->legacy->jurisdiction ?: 'IN'));
    }

    public function applies(string $statute): bool
    {
        if ($this->usesEstablishmentProfiles) {
            return (bool) ($this->profiles[$statute]?->applicable ?? false);
        }

        return (bool) $this->legacy->getAttribute(['EPF' => 'pf_applicable', 'ESI' => 'esi_applicable', 'PT' => 'pt_applicable', 'LWF' => 'lwf_applicable', 'TDS' => 'tds_applicable'][$statute]);
    }

    public function restrictPfToCeiling(): bool
    {
        return $this->usesEstablishmentProfiles
            ? (bool) ($this->profiles['EPF']?->setting('restrict_to_ceiling') ?? true)
            : (bool) $this->legacy->pf_restrict_to_ceiling;
    }

    public function profileSource(): string
    {
        return $this->usesEstablishmentProfiles ? 'establishment_profile' : 'legacy_company_profile';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'legal_entity_id' => $this->establishment?->legal_entity_id,
            'establishment_id' => $this->establishment?->getKey(),
            'establishment_source' => $this->establishmentSource,
            'profile_source' => $this->profileSource(),
            'profile_ids' => array_filter(array_map(fn ($p) => $p?->getKey(), $this->profiles)),
            'pt_state' => $this->ptState,
            'pt_state_source' => $this->ptStateSource,
            'lwf_state' => $this->lwfState,
        ];
    }
}
