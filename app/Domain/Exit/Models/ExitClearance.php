<?php

namespace App\Domain\Exit\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Exit\Support\ClearanceOwners;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clearance stage (manager / IT / finance / asset / HR) with its checklist and any recoverable amount.
 *
 * HROne parity WO-11: a due date (the last working day less the stage offset), the owner's expected date, why it was
 * closed without being cleared (`withdrawn`, `cancelled`) and one evidence file (documents disk).
 */
#[Fillable(['tenant_id', 'exit_case_id', 'stage', 'name', 'owner_user_id', 'owner_role_id', 'items', 'status', 'remarks', 'recoverable_amount', 'cleared_by', 'cleared_at', 'sort_order',
    'due_on', 'expected_on', 'closed_reason', 'evidence_path', 'evidence_name', 'evidence_by', 'evidence_at'])]
class ExitClearance extends Model
{
    use Auditable, BelongsToTenant;

    /** Why a stage was closed without being cleared (HROne parity WO-11). */
    public const CLOSED_REASONS = ['withdrawn' => 'Not applicable — exit withdrawn', 'cancelled' => 'Cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['items' => 'array', 'recoverable_amount' => 'decimal:2', 'cleared_at' => 'datetime', 'sort_order' => 'integer',
            'due_on' => 'date', 'expected_on' => 'date', 'evidence_at' => 'datetime'];
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

    /**
     * The stage's owner: its owner user, a holder of its owner role, or (HROne parity WO-11, P4) the delegate of either
     * while an out-of-office delegation covering exit clearance runs — never on the delegate's own exit.
     */
    public function isOwnedBy(User $user): bool
    {
        if ($this->owner_user_id === $user->id) {
            return true;
        }
        if ($this->owner_role_id !== null && $user->roles()->where('roles.id', $this->owner_role_id)->exists()) {
            return true;
        }

        return ClearanceOwners::delegatorFor($this, $user) !== null;
    }

    /** Open: still to be cleared (pending or blocked). */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'blocked'], true);
    }

    public function isOverdue(): bool
    {
        return ($this->getAttributes()['due_on'] ?? null) !== null && $this->isOpen() && $this->due_on->lt(today());
    }
}
