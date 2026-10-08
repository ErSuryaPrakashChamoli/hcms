<?php

namespace App\Domain\Platform\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Platform\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(TenantFactory::class)]
#[Fillable(['name', 'slug', 'status', 'country_code', 'timezone', 'locale', 'currency', 'tier', 'region', 'trial_ends_at', 'metadata'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'platform';
    }

    /** SaaS.2: changes to a tenant's own record belong to that tenant's audit chain, never to the one bound. */
    public function auditTenantId(): ?int
    {
        return $this->getKey();
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'session_epoch'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(TenantFeature::class);
    }

    public function isAccessible(): bool
    {
        return $this->status->allowsAccess();
    }
}
