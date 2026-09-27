<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** Part L: the deductor behind TDS statements and certificates — one per legal entity (TAN, responsible person). */
#[Fillable(['tenant_id', 'company_id', 'legal_entity_id', 'tan_registration_id', 'pan_registration_id', 'deductor_category', 'responsible_person_name', 'responsible_person_designation', 'responsible_person_pan', 'responsible_person_pan_last4', 'address', 'email', 'phone'])]
#[Hidden(['responsible_person_pan'])]
class TdsProfile extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisation;

    public string $accessScopeDimension = 'tds_profile';

    protected static function booted(): void
    {
        static::saving(function (TdsProfile $profile) {
            $entity = LegalEntity::query()->find($profile->legal_entity_id) ?? throw new RuntimeException('The legal entity is not reachable in this tenant.');
            $profile->company_id = $entity->company_id;

            foreach (['tan_registration_id' => 'tan', 'pan_registration_id' => 'pan'] as $column => $type) {
                if ($profile->{$column} !== null) {
                    $registration = StatutoryRegistration::query()->find($profile->{$column});
                    if ($registration === null || $registration->registration_type !== $type || (int) $registration->legal_entity_id !== (int) $entity->getKey()) {
                        throw new RuntimeException("The {$type} registration must belong to this legal entity.");
                    }
                }
            }
            if ($profile->isDirty('responsible_person_pan')) {
                $profile->responsible_person_pan_last4 = $profile->responsible_person_pan ? substr((string) $profile->responsible_person_pan, -4) : null;
            }
        });
    }

    protected function casts(): array
    {
        return ['responsible_person_pan' => 'encrypted'];
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return 'TDS profile';
    }

    public function auditSensitiveAttributes(): array
    {
        return [...config('peopleos.audit.sensitive_attributes', []), 'responsible_person_pan'];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function tan(): BelongsTo
    {
        return $this->belongsTo(StatutoryRegistration::class, 'tan_registration_id');
    }
}
