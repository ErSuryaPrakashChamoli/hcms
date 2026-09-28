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

/**
 * Phase 7: a recurring employee ↔ manager check-in (weekly, biweekly, monthly or custom): what went
 * well, blockers, support needed, priorities, goal progress, feedback both ways and actions.
 * draft → submitted (employee) → reviewed (manager). A reviewed check-in is read-only.
 */
#[Fillable(['tenant_id', 'employee_id', 'manager_id', 'performance_cycle_id', 'cadence', 'period_date', 'went_well', 'blockers', 'support_needed', 'priorities', 'goal_progress', 'employee_feedback', 'manager_feedback', 'actions', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at'])]
class PerformanceCheckIn extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            if ($c->getRawOriginal('status') === 'reviewed') {
                throw new \RuntimeException('A reviewed check-in is read-only.');
            }
        });
        static::deleting(function (self $c) {
            if ($c->status !== 'draft') {
                throw new \RuntimeException('Only a draft check-in can be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['period_date' => 'date', 'goal_progress' => 'array', 'actions' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Check-in '.$this->period_date?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['went_well', 'blockers', 'support_needed', 'priorities', 'employee_feedback', 'manager_feedback'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
