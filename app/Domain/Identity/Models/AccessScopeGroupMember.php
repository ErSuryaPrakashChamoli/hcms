<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HROne parity CA-9: a user in a data-access group (AccessScopeGroup). */
#[Fillable(['tenant_id', 'access_scope_group_id', 'user_id'])]
class AccessScopeGroupMember extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'access_scope_group_users';

    public function auditModule(): string
    {
        return 'identity';
    }

    public function auditLabel(): string
    {
        return "user #{$this->user_id} in data access group #{$this->access_scope_group_id}";
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccessScopeGroup::class, 'access_scope_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
