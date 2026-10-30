<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * HROne setup parity (Automation → Role): a role is active or inactive. An inactive role grants no permissions
 * (User::permissionKeys) and is given no new workflow tasks (ApproverResolver). System roles stay active, as they cannot
 * be deleted either. The reliever takes the role's new approvals while nobody active holds it.
 */
#[UseFactory(RoleFactory::class)]
#[Fillable(['tenant_id', 'name', 'slug', 'description', 'is_system', 'status', 'reliever_user_id', 'myspace_hidden_items'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $role): void {
            if ($role->is_system && $role->isInactive()) {
                throw ValidationException::withMessages(['status' => 'A system role cannot be switched off. Remove its members instead.']);
            }
            $reliever = $role->getAttributes()['reliever_user_id'] ?? null;
            if ($reliever !== null && $role->isDirty('reliever_user_id')
                && ! User::query()->whereKey($reliever)->where('tenant_id', $role->tenant_id)->exists()) {
                throw ValidationException::withMessages(['reliever_user_id' => 'The reliever must be a user of this organisation.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'template_keys' => 'array',
            'status' => ActiveStatus::class,
            // HROne parity CA-9 (page access per My space item): the items left out of My space for this role's holders.
            'myspace_hidden_items' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'identity';
    }

    /** SaaS.12: the template bookkeeping is not an access change; changes of the permissions themselves are audited. */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'template_keys'];
    }

    public function isInactive(): bool
    {
        return ($this->getAttributes()['status'] ?? ActiveStatus::Active->value) === ActiveStatus::Inactive->value;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('company_id', 'source', 'membership_rule_id')->withTimestamps();
    }

    /** HROne parity SV-7: the rules that decide who else holds this role. */
    public function membershipRules(): HasMany
    {
        return $this->hasMany(RoleMembershipRule::class);
    }

    public function reliever(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reliever_user_id');
    }
}
