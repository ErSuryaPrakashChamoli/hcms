<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne setup parity HP-8j: an initiative an employee takes on — of an initiative type (performance masters), optionally
 * for one of their goals — with dates, progress (0–100) and status. planned → in progress → completed; planned or in
 * progress → cancelled. A completed or cancelled initiative is read-only. Changed only through Initiatives.
 */
#[Fillable(['tenant_id', 'employee_id', 'initiative_type_id', 'goal_id', 'owner_id', 'title', 'description', 'start_date', 'due_date', 'status', 'progress', 'outcome', 'completed_at', 'cancel_reason', 'created_by', 'lock_version'])]
class Initiative extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TRANSITIONS = [
        'planned' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public const OPEN = ['planned', 'in_progress'];

    protected $attributes = ['status' => 'planned', 'progress' => 0, 'lock_version' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $initiative): void {
            if ($initiative->start_date && $initiative->due_date && $initiative->due_date->lt($initiative->start_date)) {
                throw new RuntimeException('An initiative cannot be due before it starts.');
            }
            if ((int) $initiative->progress < 0 || (int) $initiative->progress > 100) {
                throw new RuntimeException('Progress is between 0 and 100.');
            }
        });
        static::updating(function (self $initiative): void {
            $from = (string) $initiative->getRawOriginal('status');
            if (! in_array($from, self::OPEN, true)) {
                throw new RuntimeException('This initiative is '.($from === 'completed' ? 'completed' : 'cancelled').' and read-only.');
            }
            if ($initiative->isDirty('status') && ! in_array($initiative->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new RuntimeException("An initiative cannot move from {$from} to {$initiative->status}.");
            }
            $initiative->lock_version = (int) $initiative->getRawOriginal('lock_version') + 1;
        });
        static::deleting(function (): void {
            throw new RuntimeException('Initiatives are cancelled, never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime', 'progress' => 'integer', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Initiative: '.$this->title;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PerformanceLookup::class, 'initiative_type_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(InitiativeFeedback::class)->orderBy('id');
    }
}
