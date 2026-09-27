<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Statutory registrations and establishment statutory profiles (Phase 5 Parts B and D).
 * A changed registration number is a new registration (the old one is superseded), never an edit.
 */
final class StatutoryRegistrations
{
    /** @param  array<string, mixed>  $attributes */
    public function register(array $attributes, string $reason, ?User $actor = null): StatutoryRegistration
    {
        $registration = new StatutoryRegistration([
            ...collect($attributes)->except(['company_id', 'statutory_authority', 'verification_status', 'verified_at', 'verified_by'])->all(),
            'status' => $attributes['status'] ?? 'active',
            'verification_status' => 'unverified',
            'created_by' => $actor?->getKey() ?? auth()->id(),
        ]);

        $duplicate = StatutoryRegistration::query()->withoutGlobalScope(AccessScope::class)
            ->where('registration_type', $registration->registration_type)
            ->where('registration_number_hash', StatutoryRegistration::hashNumber((string) $registration->registration_number))
            ->where('status', 'active')
            ->when($registration->establishment_id, fn ($q, $id) => $q->where('establishment_id', '!=', $id))
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('This registration number is already active for another establishment.');
        }

        $registration->withAuditReason($reason)->withAuditAction(AuditAction::StatutoryRegistrationCreated)->save();

        return $registration;
    }

    /** Non-identifying corrections (name, jurisdiction, notes, metadata, closing date). */
    public function update(StatutoryRegistration $registration, array $changes, string $reason): StatutoryRegistration
    {
        $allowed = ['registration_name', 'jurisdiction', 'notes', 'metadata', 'effective_to', 'status', 'source_reference'];
        $blocked = array_diff(array_keys($changes), $allowed);

        if ($blocked !== []) {
            throw new RuntimeException('Registration identity ('.implode(', ', $blocked).') cannot be edited; supersede the registration with a new one.');
        }
        if (blank($reason)) {
            throw new RuntimeException('A reason is required to change a statutory registration.');
        }

        $registration->withAuditReason($reason)->withAuditAction(AuditAction::StatutoryRegistrationUpdated)->update($changes);

        return $registration;
    }

    /** Replace a registration (new number or type): the old one is closed and marked superseded. */
    public function supersede(StatutoryRegistration $old, array $attributes, CarbonInterface|string $from, string $reason, ?User $actor = null): StatutoryRegistration
    {
        $from = Carbon::parse($from);

        return DB::transaction(function () use ($old, $attributes, $from, $reason, $actor) {
            $this->update($old, ['effective_to' => $from->copy()->subDay()->toDateString(), 'status' => 'superseded'], $reason);

            return $this->register([
                'legal_entity_id' => $old->legal_entity_id,
                'establishment_id' => $old->establishment_id,
                'registration_type' => $old->registration_type,
                'state_code' => $old->state_code,
                'jurisdiction' => $old->jurisdiction,
                ...$attributes,
                'effective_from' => $from->toDateString(),
            ], $reason, $actor);
        });
    }

    /** Mark a registration verified against its certificate (the verifier is never its creator). */
    public function verify(StatutoryRegistration $registration, User $verifier, string $sourceReference, ?string $notes = null): StatutoryRegistration
    {
        if ((int) $registration->created_by === (int) $verifier->getKey()) {
            throw new RuntimeException('A registration cannot be verified by the person who recorded it.');
        }
        if (blank($sourceReference)) {
            throw new RuntimeException('Verification needs the certificate or portal reference it was checked against.');
        }

        $registration->withAuditReason('Verified against '.$sourceReference)->withAuditAction(AuditAction::StatutoryRegistrationUpdated)->update([
            'verification_status' => 'verified',
            'verified_by' => $verifier->getKey(),
            'verified_at' => now(),
            'source_reference' => $sourceReference,
            'notes' => $notes ?? $registration->notes,
        ]);

        return $registration;
    }

    /** The active registration of a type for an establishment (or its legal entity) on a date. */
    public function forEstablishment(Establishment $establishment, string $type, CarbonInterface|string $on): ?StatutoryRegistration
    {
        return StatutoryRegistration::query()->withoutGlobalScope(AccessScope::class)
            ->where('registration_type', $type)
            ->where('legal_entity_id', $establishment->legal_entity_id)
            ->where(fn ($q) => $q->where('establishment_id', $establishment->getKey())->orWhereNull('establishment_id'))
            ->whereIn('status', ['active', 'superseded'])
            ->effectiveOn($on)
            ->orderByRaw('establishment_id is null')->orderByDesc('effective_from')
            ->first();
    }

    /** The establishment profile for a statute on a date (null = establishment has no profile for it). */
    public function profile(Establishment $establishment, string $statute, CarbonInterface|string $on): ?EstablishmentStatutoryProfile
    {
        return EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)
            ->where('establishment_id', $establishment->getKey())->where('statute', $statute)
            ->effectiveOn($on)->orderByDesc('effective_from')->first();
    }

    public function hasProfiles(Establishment $establishment): bool
    {
        return EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)->where('establishment_id', $establishment->getKey())->exists();
    }

    /**
     * ADR-0001 transition for one company: legacy registration columns become registrations and the
     * legacy applicability flags become establishment profiles on the principal establishment.
     */
    public function backfillFromLegacyProfile(CompanyStatutoryProfile $legacy, Establishment $establishment): int
    {
        $created = 0;
        $from = $establishment->effective_from->toDateString();
        $map = [
            'pf_establishment_code' => ['epf_establishment_code', $establishment->getKey()],
            'esi_code' => ['esic_employer_code', $establishment->getKey()],
            'pt_registration' => ['pt_registration_certificate', $establishment->getKey()],
            'tan' => ['tan', null],
            'pan' => ['pan', null],
        ];

        foreach ($map as $column => [$type, $establishmentId]) {
            $number = $legacy->getAttribute($column);
            if (blank($number)) {
                continue;
            }
            $exists = StatutoryRegistration::query()->withoutGlobalScope(AccessScope::class)
                ->where('legal_entity_id', $establishment->legal_entity_id)->where('registration_type', $type)
                ->where('registration_number_hash', StatutoryRegistration::hashNumber((string) $number))->exists();
            if ($exists || ($type === 'pt_registration_certificate' && blank($establishment->state ?? $legacy->pt_state))) {
                continue;
            }
            $this->register([
                'legal_entity_id' => $establishment->legal_entity_id,
                'establishment_id' => $establishmentId,
                'registration_type' => $type,
                'registration_number' => $number,
                'state_code' => $type === 'pt_registration_certificate' ? ($establishment->state ?? $legacy->pt_state) : null,
                'effective_from' => $from,
                'metadata' => ['source' => 'company_statutory_profiles.'.$column],
            ], 'ADR-0001 transition from the legacy statutory profile');
            $created++;
        }

        if (! $this->hasProfiles($establishment)) {
            $statutes = ['EPF' => 'pf_applicable', 'ESI' => 'esi_applicable', 'PT' => 'pt_applicable', 'LWF' => 'lwf_applicable', 'TDS' => 'tds_applicable'];
            foreach ($statutes as $statute => $flag) {
                $registrationType = ['EPF' => 'epf_establishment_code', 'ESI' => 'esic_employer_code', 'PT' => 'pt_registration_certificate', 'LWF' => 'lwf_registration', 'TDS' => 'tan'][$statute];
                $applicable = (bool) $legacy->getAttribute($flag);
                $reason = 'ADR-0001 transition from the legacy statutory profile';
                // LWF follows the establishment state now; a different legacy LWF state needs its own establishment.
                if ($statute === 'LWF' && $applicable && filled($legacy->lwf_state) && $legacy->lwf_state !== $establishment->state) {
                    $applicable = false;
                    $reason .= "; legacy LWF state {$legacy->lwf_state} differs from the establishment state — create an establishment in {$legacy->lwf_state}";
                }
                $profile = new EstablishmentStatutoryProfile([
                    'establishment_id' => $establishment->getKey(),
                    'statute' => $statute,
                    'applicable' => $applicable,
                    'statutory_registration_id' => $this->forEstablishment($establishment, $registrationType, $from)?->getKey(),
                    'settings' => $statute === 'EPF' ? ['restrict_to_ceiling' => (bool) $legacy->pf_restrict_to_ceiling] : null,
                    'effective_from' => $from,
                    'reason' => $reason,
                ]);
                $profile->withAuditReason('ADR-0001 transition')->save();
                $created++;
            }
        }

        return $created;
    }
}
