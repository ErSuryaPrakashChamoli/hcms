<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity CA-9 (Access rights → Data access): a module data-access group. Its members see, in the group's module,
 * only the employees the group reaches (plus themselves): an extra constraint inside AccessScopes, on top of the member's
 * own organisation scope, so a group can only narrow what a member saw before.
 *
 * - module: a key of peopleos.access.modules. A Workforce group narrows everything a member sees about people (the
 *   employee master and every employee-linked record); any other module narrows only that module's employee-linked records.
 * - basis: organisation (rules by organisation unit: dimensions AND-ed, values within a dimension OR-ed; no rule = the
 *   whole organisation) or employees (named employees only).
 * - reportees: none, direct (people who report to the member today) or all (every level below the member).
 * - include_sub_departments: a department rule also reaches the departments below it.
 *
 * Several active groups of the same module add up (their people together); inactive groups do nothing. A group that is
 * malformed (unknown module, basis, reportees or status; a rule of an unknown dimension or a bad id) reaches nobody, and a
 * member of one sees nothing at all (AccessScopes, fail closed).
 */
#[Fillable(['tenant_id', 'name', 'module', 'basis', 'reportees', 'include_sub_departments', 'status', 'description'])]
class AccessScopeGroup extends Model
{
    use Auditable, BelongsToTenant;

    public const BASES = ['organisation' => 'By organisation unit', 'employees' => 'Specific employees'];

    public const REPORTEES = ['none' => 'Nobody beyond the rules', 'direct' => 'Their direct reports', 'all' => 'Everyone below them (all levels)'];

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $attributes = ['basis' => 'organisation', 'reportees' => 'none', 'include_sub_departments' => false, 'status' => 'active'];

    protected function casts(): array
    {
        return ['include_sub_departments' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'identity';
    }

    public function auditLabel(): string
    {
        return "data access group {$this->name}";
    }

    public function members(): HasMany
    {
        return $this->hasMany(AccessScopeGroupMember::class, 'access_scope_group_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AccessScopeGroupRule::class, 'access_scope_group_id');
    }

    public function isActive(): bool
    {
        return ($this->getAttributes()['status'] ?? null) === 'active';
    }
}
