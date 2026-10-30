<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity CA-9: one value a data-access group reaches — an organisation unit (a dimension of
 * UserAccessScope::DIMENSIONS) or, for a group of specific employees, an employee (dimension `employee`).
 */
#[Fillable(['tenant_id', 'access_scope_group_id', 'dimension', 'scope_id'])]
class AccessScopeGroupRule extends Model
{
    use Auditable, BelongsToTenant;

    /** The dimensions a group rule may use: the organisation dimensions and named employees. */
    public const DIMENSIONS = [...UserAccessScope::DIMENSIONS, 'employee'];

    public function auditModule(): string
    {
        return 'identity';
    }

    public function auditLabel(): string
    {
        return "data access group #{$this->access_scope_group_id} {$this->dimension} #{$this->scope_id}";
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccessScopeGroup::class, 'access_scope_group_id');
    }
}
