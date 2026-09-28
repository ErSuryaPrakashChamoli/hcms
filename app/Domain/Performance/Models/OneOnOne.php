<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manager–employee one-on-one (§34): agenda, shared notes, action items. Phase 7: the manager's
 * private notes are encrypted, never serialized, and read only through OneOnOnes::privateNotesFor().
 */
#[Fillable(['tenant_id', 'employee_id', 'manager_id', 'scheduled_at', 'held_at', 'agenda', 'notes', 'private_notes', 'action_items', 'status'])]
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
}
