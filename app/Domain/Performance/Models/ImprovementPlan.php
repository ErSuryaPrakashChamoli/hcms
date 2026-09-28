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

/**
 * A performance improvement plan (§34). Sensitive. Phase 7: status changes follow TRANSITIONS;
 * a closed, cancelled or withdrawn plan is read-only.
 */
#[Fillable(['tenant_id', 'employee_id', 'manager_id', 'appraisal_id', 'start_date', 'end_date', 'reason', 'objectives', 'status', 'outcome', 'closure_reason', 'closed_at', 'created_by', 'lock_version'])]
class ImprovementPlan extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TRANSITIONS = [
        'draft' => ['active', 'cancelled'],
        'active' => ['extended', 'completed', 'unsuccessful', 'cancelled', 'withdrawn'],
        'extended' => ['extended', 'completed', 'unsuccessful', 'cancelled', 'withdrawn'],
        'completed' => ['closed'],
        'unsuccessful' => ['closed'],
        'closed' => [],
        'cancelled' => [],
        'withdrawn' => [],
    ];

    public const FINAL = ['closed', 'cancelled', 'withdrawn'];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            if ($plan->start_date && $plan->end_date && $plan->end_date->lt($plan->start_date)) {
                throw new \RuntimeException('A plan cannot end before it starts.');
            }
        });
        static::updating(function (self $plan) {
            $from = $plan->getRawOriginal('status');
            if (in_array($from, self::FINAL, true)) {
                throw new \RuntimeException('This improvement plan is closed and read-only.');
            }
            if ($plan->isDirty('status') && ! in_array($plan->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("An improvement plan cannot move from {$from} to {$plan->status}.");
            }
            $plan->lock_version = (int) $plan->getRawOriginal('lock_version') + 1;
        });
        static::deleting(function (self $plan) {
            if ($plan->status !== 'draft') {
                throw new \RuntimeException('Only a draft improvement plan can be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'objectives' => 'array', 'closed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Improvement plan '.$this->start_date?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['reason', 'objectives', 'outcome', 'closure_reason'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(Appraisal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(ImprovementPlanCheckpoint::class)->orderBy('due_date');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'active', 'extended'], true);
    }
}
