<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Concerns\HasRecordVerification;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A registered place of work under a central or state act (ADR-0001). Its state selects the
 * professional tax and labour welfare fund rules; registrations (EPFO, ESIC, PT, LWF) hang off it.
 * `company_id` is denormalised from the legal entity so the company access dimension applies.
 */
#[Fillable(['tenant_id', 'company_id', 'legal_entity_id', 'code', 'name', 'address', 'country', 'state', 'district', 'postal_code', 'establishment_type', 'is_primary', 'status', 'effective_from', 'effective_to', 'metadata'])]
class Establishment extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, HasRecordVerification, ScopedByOrganisation;

    public string $accessScopeDimension = 'establishment';

    protected static function booted(): void
    {
        static::saving(function (Establishment $establishment) {
            $entity = LegalEntity::query()->find($establishment->legal_entity_id);

            if ($entity === null) {
                throw new RuntimeException('An establishment must belong to a legal entity of this tenant.');
            }

            // The company always follows the legal entity; it is never taken from user input.
            $establishment->company_id = $entity->company_id;
            $establishment->country ??= $entity->country;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'is_primary' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'verification_submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function verificationIdentity(): array
    {
        return ['legal_entity_id', 'code', 'name', 'address', 'country', 'state', 'district', 'postal_code', 'establishment_type'];
    }

    public function auditModule(): string
    {
        return 'organisation';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
