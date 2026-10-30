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
use RuntimeException;

/**
 * HROne setup parity HP-8d: an employee's goal sheet of a cycle submitted for approval (goal sheet policy
 * plan_approval). While pending, its goals wait in `pending_approval`; approving activates them, sending it back returns
 * them to draft. Decided once (GoalPlans), by a person or by a workflow on `performance.goal_plan.submitted`.
 */
#[Fillable(['tenant_id', 'employee_id', 'performance_cycle_id', 'approval', 'status', 'goals', 'total_weight', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at', 'decision_note'])]
class GoalPlan extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'pending', 'approval' => 'manager'];

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->getRawOriginal('status') !== 'pending' && $plan->isDirty('status')) {
                throw new RuntimeException('This goal plan was already decided.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Goal plans are kept as history.');
        });
    }

    protected function casts(): array
    {
        return ['goals' => 'integer', 'total_weight' => 'decimal:2', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'Goal plan '.($this->submitted_at?->toDateString() ?? '');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
