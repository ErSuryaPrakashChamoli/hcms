<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity SV-7 (Automation → Role, "Select role organization wise"): who holds a role, by a rule over the employee
 * (the policy rule fields: company, department, designation, grade, level, location …, or named employees). The grants
 * it makes are role_user rows tagged source = rule (RoleMembershipSync); manual grants are never touched. A rule that is
 * malformed, inactive or for a role no rule may grant matches nobody.
 */
#[Fillable(['tenant_id', 'role_id', 'name', 'conditions', 'match', 'status', 'description', 'created_by', 'updated_by', 'last_synced_at'])]
class RoleMembershipRule extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['match' => 'all', 'status' => 'active'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'last_synced_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'identity';
    }

    public function auditLabel(): string
    {
        return "role membership rule {$this->name}";
    }

    /** last_synced_at is bookkeeping, not a change of the rule. */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'last_synced_at'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return ($this->getAttributes()['status'] ?? null) === 'active';
    }
}
