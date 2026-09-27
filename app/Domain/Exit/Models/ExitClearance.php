<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One clearance stage (manager / IT / finance / asset / HR) with its checklist and any recoverable amount. */
#[Fillable(['tenant_id', 'exit_case_id', 'stage', 'name', 'owner_user_id', 'owner_role_id', 'items', 'status', 'remarks', 'recoverable_amount', 'cleared_by', 'cleared_at', 'sort_order'])]
class ExitClearance extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['items' => 'array', 'recoverable_amount' => 'decimal:2', 'cleared_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'exit';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function exitCase(): BelongsTo
    {
        return $this->belongsTo(ExitCase::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function isOwnedBy(User $user): bool
    {
        if ($this->owner_user_id === $user->id) {
            return true;
        }

        return $this->owner_role_id !== null && $user->roles()->where('roles.id', $this->owner_role_id)->exists();
    }
}
