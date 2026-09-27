<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * Identity is platform-level: users are looked up before a tenant is known (login), so this model
 * is deliberately NOT globally tenant-scoped. Tenant-facing listings must use forCurrentTenant().
 */
#[UseFactory(UserFactory::class)]
#[Fillable(['tenant_id', 'name', 'email', 'password', 'is_platform_admin', 'status', 'timezone', 'locale', 'external_id', 'sso_subject', 'sso_connection_id', 'password_changed_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /** @var Collection<int, string>|null */
    private ?Collection $permissionKeyCache = null;

    protected function casts(): array
    {
        return [
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'password_changed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'status' => UserStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
            }
        });
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }

    public function hasMfaEnabled(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function auditModule(): string
    {
        return 'identity';
    }

    public function auditExcludedAttributes(): array
    {
        return ['app_authentication_secret', 'app_authentication_recovery_codes', ...config('peopleos.audit.ignored_attributes', []), 'last_login_at', 'email_verified_at'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accessScopes(): HasMany
    {
        return $this->hasMany(UserAccessScope::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('company_id')->withTimestamps();
    }

    #[Scope]
    protected function forCurrentTenant(Builder $query): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId === null ? $query->whereRaw('1 = 0') : $query->where('tenant_id', $tenantId);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true && $this->tenant_id === null;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Exact-key permission check. Wildcards are expanded when roles are provisioned, so the
     * stored graph is always concrete.
     */
    public function hasPermission(string $key, ?int $companyId = null): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return $this->permissionKeys($companyId)->contains($key);
    }

    /** @return Collection<int, string> */
    public function permissionKeys(?int $companyId = null): Collection
    {
        $this->permissionKeyCache ??= $this->roles()
            ->with('permissions:id,key')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->map(fn (Permission $p) => [
                'key' => $p->key,
                'company_id' => $role->pivot->company_id,
            ]))
            ->values();

        return $this->permissionKeyCache
            ->filter(fn (array $grant) => $grant['company_id'] === null || $companyId === null || (int) $grant['company_id'] === $companyId)
            ->pluck('key')
            ->unique()
            ->values();
    }

    public function flushPermissionCache(): void
    {
        $this->permissionKeyCache = null;
    }

    /** @return list<string> */
    public function roleSlugs(): array
    {
        if ($this->isPlatformAdmin()) {
            return ['platform-super-admin'];
        }

        return $this->relationLoaded('roles')
            ? $this->roles->pluck('slug')->all()
            : $this->roles()->pluck('slug')->all();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->isPlatformAdmin()) {
            return true;
        }

        return $this->tenant !== null && $this->tenant->isAccessible();
    }
}
