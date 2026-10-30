<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A manager–employee one-on-one (§34): agenda, shared notes, action items. Phase 7: the manager's
 * private notes are encrypted, never serialized, and read only through OneOnOnes::privateNotesFor().
 */
#[Fillable(['tenant_id', 'employee_id', 'manager_id', 'scheduled_at', 'held_at', 'agenda', 'notes', 'private_notes', 'action_items', 'status', 'initiated_by'])]
class OneOnOne extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'scheduled'];

    protected $hidden = ['private_notes'];

    public const STATUSES = ['scheduled' => 'Scheduled', 'held' => 'Held', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'held_at' => 'datetime', 'action_items' => 'array', 'private_notes' => 'encrypted'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'One-on-one '.$this->scheduled_at?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['notes', 'private_notes'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /** HP-8b: who scheduled it (null for one-on-ones scheduled before: the manager / host). */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'initiated_by');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(OneOnOneFeedback::class);
    }

    /** The employee who started the meeting: the recorded initiator, else the host. */
    public function initiatorId(): ?int
    {
        return ($this->getAttributes()['initiated_by'] ?? null) ?? $this->manager_id;
    }

    /** The other side of the meeting. */
    public function participantId(): ?int
    {
        return (int) $this->initiatorId() === (int) $this->employee_id ? $this->manager_id : $this->employee_id;
    }
}
