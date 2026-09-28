<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One organisational restriction on a user (Phase 0.2 ABAC). Rows within a dimension are OR-ed,
 * dimensions are AND-ed; a user with no rows is tenant-wide.
 */
#[Fillable(['tenant_id', 'user_id', 'dimension', 'scope_id'])]
class UserAccessScope extends Model
{
    use Auditable, BelongsToTenant;

    public const DIMENSIONS = ['company', 'location', 'business_unit', 'division', 'department', 'team', 'establishment'];

    public function auditModule(): string
    {
        return 'identity';
    }

    public function auditLabel(): string
    {
        return "user #{$this->user_id} {$this->dimension} #{$this->scope_id}";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
