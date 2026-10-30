<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Concerns\HasCustomFields;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Services\LegalEntities;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Storage;

/**
 * A legal entity / company within a tenant. Group -> Company is the first level of the
 * organisation tree; locations, departments etc. hang off this in Phase 2.
 */
#[UseFactory(CompanyFactory::class)]
#[Fillable(['tenant_id', 'name', 'code', 'legal_name', 'industry', 'phone', 'fax', 'contact_employee_id', 'social_links', 'logo_path', 'status', 'country_code', 'timezone', 'currency', 'effective_from', 'effective_to', 'metadata'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use ScopedByOrganisation;

    public string $accessScopeDimension = 'company';

    use Auditable, BelongsToTenant, HasCustomFields, HasEffectiveDates, HasFactory;

    protected static function booted(): void
    {
        // HROne setup parity CA-2: a new company takes the organisation's industry unless it names its own.
        static::creating(function (Company $company): void {
            if (blank($company->getAttributes()['industry'] ?? null) && app(TenantContext::class)->id() !== null) {
                $company->industry = app(SettingsRepository::class)->get('organisation.industry') ?: null;
            }
        });
        // A replaced logo is removed from storage (only files in this organisation's logo folder).
        static::updated(function (Company $company): void {
            $old = $company->getRawOriginal('logo_path');
            if ($company->wasChanged('logo_path') && filled($old) && str_starts_with((string) $old, self::logoDirectory().'/')) {
                Storage::disk(config('peopleos.documents.disk', 'local'))->delete((string) $old);
            }
        });
        // ADR-0001: every company is an explicit legal entity with a primary establishment.
        static::created(fn (Company $company) => app(LegalEntities::class)->ensureFor($company));
    }

    protected function casts(): array
    {
        return [
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'social_links' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /** Where company logos are kept on the documents disk (private; served through the authorised file route). */
    public static function logoDirectory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/organisation/logos';
    }

    /** HROne setup parity CA-2: the company's contact person. */
    public function contactEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'contact_employee_id');
    }

    public function organisationNode(): MorphOne
    {
        return $this->morphOne(OrganisationNode::class, 'nodeable');
    }

    public function legalEntities(): HasMany
    {
        return $this->hasMany(LegalEntity::class);
    }

    public function primaryLegalEntity(): HasOne
    {
        return $this->hasOne(LegalEntity::class)->where('is_primary', true);
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}
