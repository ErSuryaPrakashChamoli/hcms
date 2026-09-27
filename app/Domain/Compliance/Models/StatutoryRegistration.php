<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A registration with a statutory authority (Phase 5 Part B): EPFO establishment code and
 * exemptions, ESIC employer code, TAN, PT and LWF registrations, and any future type declared in
 * `peopleos.compliance.registration_types`. The number is encrypted, hashed for uniqueness and
 * shown masked. `establishment_id` null means an entity-level registration (PAN, TAN).
 */
#[Fillable(['tenant_id', 'company_id', 'legal_entity_id', 'establishment_id', 'statutory_authority', 'registration_type', 'registration_number', 'registration_name', 'jurisdiction', 'state_code', 'effective_from', 'effective_to', 'status', 'metadata', 'verification_status', 'verified_at', 'verified_by', 'source_reference', 'notes', 'created_by'])]
#[Hidden(['registration_number', 'registration_number_hash'])]
class StatutoryRegistration extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, ScopedByOrganisation;

    public string $accessScopeDimension = 'statutory_registration';

    protected static function booted(): void
    {
        static::saving(function (StatutoryRegistration $registration) {
            $type = config("peopleos.compliance.registration_types.{$registration->registration_type}");

            if ($type === null) {
                throw new RuntimeException("Unknown statutory registration type [{$registration->registration_type}].");
            }

            if ($registration->establishment_id !== null) {
                $establishment = Establishment::query()->find($registration->establishment_id)
                    ?? throw new RuntimeException('The establishment is not reachable in this tenant.');
                $registration->legal_entity_id = $establishment->legal_entity_id;
                $registration->state_code ??= $establishment->state;
            } elseif (($type['level'] ?? 'establishment') === 'establishment') {
                throw new RuntimeException("A {$type['label']} registration belongs to an establishment.");
            }

            $entity = LegalEntity::query()->find($registration->legal_entity_id)
                ?? throw new RuntimeException('The legal entity is not reachable in this tenant.');
            $registration->company_id = $entity->company_id;
            $registration->statutory_authority = $type['authority'];

            if (($type['state_required'] ?? false) && blank($registration->state_code)) {
                throw new RuntimeException("A {$type['label']} registration needs a state.");
            }

            if ($registration->isDirty('registration_number')) {
                $number = self::normalise((string) $registration->registration_number);
                if ($number === '') {
                    throw new RuntimeException('A registration number is required.');
                }
                $registration->registration_number = $number;
                $registration->registration_number_hash = self::hashNumber($number);
                $registration->registration_number_last4 = substr($number, -4);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'registration_number' => 'encrypted',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public static function normalise(string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($number)) ?? '');
    }

    public static function hashNumber(string $number): string
    {
        return hash_hmac('sha256', self::normalise($number), (string) config('app.key'));
    }

    public function maskedNumber(): string
    {
        return '••••'.($this->registration_number_last4 ?? '');
    }

    public function typeLabel(): string
    {
        return config("peopleos.compliance.registration_types.{$this->registration_type}.label", $this->registration_type);
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return $this->typeLabel().' '.$this->maskedNumber();
    }

    public function auditSensitiveAttributes(): array
    {
        return [...config('peopleos.audit.sensitive_attributes', []), 'registration_number', 'registration_number_hash'];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
