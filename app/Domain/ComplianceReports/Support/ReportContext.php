<?php

namespace App\Domain\ComplianceReports\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;

/**
 * What one report is generated for: the company, the establishment (registers, challans) or state (PT, LWF) or legal
 * entity (TDS), the period, the catalogue definition and the person generating it. Identifiers (UAN, ESI IP number,
 * PAN, bank account, registration numbers) are masked unless the pack was asked for in full by someone allowed to.
 */
final class ReportContext
{
    public function __construct(
        public readonly Company $company,
        public readonly ReportPeriod $period,
        public readonly ReportDefinition $definition,
        public readonly ReportData $data,
        public readonly ?User $user = null,
        public readonly ?Establishment $establishment = null,
        public readonly ?LegalEntity $legalEntity = null,
        public readonly ?string $state = null,
        public readonly bool $unmasked = false,
        ?ResolvedForm $resolved = null,
    ) {
        $this->resolved = $resolved ?? $definition->resolveFor($establishment, $period);
    }

    /** The form, legal basis and sourcing that apply to this establishment for this period. */
    public readonly ResolvedForm $resolved;

    public function forPeriod(ReportPeriod $period): self
    {
        return new self($this->company, $period, $this->definition, $this->data, $this->user, $this->establishment, $this->legalEntity, $this->state, $this->unmasked);
    }

    public function identifier(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return $this->unmasked ? $value : '••••'.mb_substr($value, -4);
    }

    public function stateName(?string $code = null): string
    {
        $code = ReportCatalogue::canonicalState($code ?? $this->state ?? $this->establishment?->state);

        return $code === null ? '' : (string) (app(ReportCatalogue::class)->states()[$code] ?? config("peopleos.compliance.states.{$code}") ?? $code);
    }

    /**
     * The header block every sheet carries: employer, establishment and address, the registration the report is filed
     * under, the period and the legal basis and form.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function header(?string $registrationType = null): array
    {
        $entity = $this->legalEntity ?? $this->establishment?->legalEntity;
        $rows = [['Employer', $entity?->legal_name ?? $this->company->legal_name ?? $this->company->name]];

        if ($this->establishment !== null) {
            $rows[] = ['Establishment', trim($this->establishment->name.($this->establishment->code ? " ({$this->establishment->code})" : ''))];
            $address = trim(implode(', ', array_filter([$this->establishment->address, $this->establishment->district, $this->stateName($this->establishment->state), $this->establishment->postal_code])));
            if ($address !== '') {
                $rows[] = ['Address', $address];
            }
        } elseif ($this->state !== null) {
            $rows[] = ['State', $this->stateName()];
        }

        if ($registrationType !== null && ($number = $this->data->registrationNumber($this, $registrationType)) !== null) {
            $rows[] = [(string) config("peopleos.compliance.registration_types.{$registrationType}.label", $registrationType), $this->identifier($number)];
        }

        $rows[] = ['Period', $this->period->label()];
        if ($this->resolved->form !== '') {
            $rows[] = ['Form', $this->resolved->form];
        }
        $rows[] = ['Legal basis', $this->resolved->legalBasis];
        $rows[] = ['What this is', $this->definition->outputLabel().($this->definition->filing ? '. Filed by the employer on: '.$this->definition->filing : '')];
        $rows[] = ['Source confidence', $this->resolved->confidenceLabel().($this->resolved->verifiedOn ? ' (checked '.$this->resolved->verifiedOn.')' : '').($this->resolved->legalReview ? ' — legal review: '.$this->resolved->legalReview : '')];
        if ($this->resolved->configurationRequired !== null) {
            $rows[] = ['Configuration required', $this->resolved->configurationRequired];
        }
        $rows[] = ['Generated', now()->format('d-m-Y H:i').' by PeopleOS'.($this->user ? ' for '.$this->user->name : '')];

        return $rows;
    }
}
