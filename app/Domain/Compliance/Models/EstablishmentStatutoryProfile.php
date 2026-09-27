<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Which statute applies to an establishment for a period (Phase 5 Part D). Holds applicability,
 * the registration used and non-numeric options only; rates always come from verified rule
 * versions. Periods never overlap per establishment and statute.
 */
#[Fillable(['tenant_id', 'company_id', 'establishment_id', 'statute', 'applicable', 'statutory_registration_id', 'settings', 'effective_from', 'effective_to', 'reason', 'created_by'])]
class EstablishmentStatutoryProfile extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates, ScopedByOrganisation;

    public const STATUTES = ['EPF', 'ESI', 'PT', 'LWF', 'TDS'];

    public string $accessScopeDimension = 'statutory_profile';

    protected $table = 'establishment_statutory_profiles';

    protected static function booted(): void
    {
        static::saving(function (EstablishmentStatutoryProfile $profile) {
            if (! in_array($profile->statute, self::STATUTES, true)) {
                throw new RuntimeException("Unknown statute [{$profile->statute}].");
            }

            $establishment = Establishment::query()->find($profile->establishment_id)
                ?? throw new RuntimeException('The establishment is not reachable in this tenant.');
            $profile->company_id = $establishment->company_id;

            $allowed = config("peopleos.compliance.profile_settings.{$profile->statute}", []);
            foreach ((array) $profile->settings as $key => $value) {
                if (! array_key_exists($key, $allowed)) {
                    throw new RuntimeException("[{$key}] is not a {$profile->statute} profile setting. Profiles never carry rates; rates come from verified rule versions.");
                }
                if (! is_bool($value)) {
                    throw new RuntimeException("[{$key}] must be a yes/no option.");
                }
            }

            if ($profile->statutory_registration_id !== null) {
                $registration = StatutoryRegistration::query()->find($profile->statutory_registration_id);
                if ($registration === null || (int) $registration->legal_entity_id !== (int) $establishment->legal_entity_id) {
                    throw new RuntimeException('The registration must belong to the same legal entity as the establishment.');
                }
            }

            $overlap = EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)
                ->where('establishment_id', $profile->establishment_id)
                ->where('statute', $profile->statute)
                ->when($profile->exists, fn ($q) => $q->whereKeyNot($profile->getKey()))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $profile->effective_from))
                ->when($profile->effective_to, fn ($q, $to) => $q->where('effective_from', '<=', $to))
                ->exists();

            if ($overlap) {
                throw new RuntimeException("An overlapping {$profile->statute} profile already exists for this establishment; close it first.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'applicable' => 'boolean',
            'settings' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function setting(string $key): bool
    {
        return (bool) ($this->settings[$key] ?? config("peopleos.compliance.profile_settings.{$this->statute}.{$key}.default", false));
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return "{$this->statute} profile";
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(StatutoryRegistration::class, 'statutory_registration_id');
    }
}
