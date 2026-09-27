<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A manager–employee check-in (§34): agenda, notes, action items. */
#[Fillable(['tenant_id', 'employee_id', 'manager_id', 'scheduled_at', 'held_at', 'agenda', 'notes', 'action_items', 'status'])]
class OneOnOne extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'scheduled'];

    public const STATUSES = ['scheduled' => 'Scheduled', 'held' => 'Held', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'held_at' => 'datetime', 'action_items' => 'array'];
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
        return ['notes'];
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
