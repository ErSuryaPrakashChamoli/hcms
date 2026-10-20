<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10: a requested change to a position that configuration says needs a second person's approval
 * (headcount, FTE, organisation, grade… — peopleos.workforce.change_approval). Approved changes are
 * applied as a new position version; decided requests are immutable history.
 */
#[Fillable(['tenant_id', 'position_id', 'changes', 'categories', 'effective_from', 'status', 'reason', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'applied_version_id', 'workflow_instance_id', 'lock_version'])]
class PositionChangeRequest extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->getRawOriginal('status') !== 'pending' && array_diff(array_keys($r->getDirty()), ['applied_version_id', 'lock_version', 'updated_at']) !== []) {
                throw new \RuntimeException('A decided position change request is read-only.');
            }
            if (! $r->isDirty('lock_version')) {
                $r->lock_version = (int) $r->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Position change requests are never deleted.'));
    }

    protected function casts(): array
    {
        return ['changes' => 'array', 'categories' => 'array', 'effective_from' => 'date', 'decided_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
