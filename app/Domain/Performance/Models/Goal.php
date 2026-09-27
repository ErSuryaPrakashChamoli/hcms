<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A goal / objective / KRA / KPI (§34). Cascades company → business → department → team → employee
 * through parent_id; organisation-level goals carry an organisation_node_id instead of an employee.
 */
#[Fillable(['tenant_id', 'level', 'employee_id', 'organisation_node_id', 'parent_id', 'performance_cycle_id', 'kra_id', 'type', 'title', 'description', 'measure_type', 'start_value', 'target_value', 'current_value', 'unit', 'weight', 'progress', 'status', 'start_date', 'due_date', 'is_locked', 'created_by'])]
class Goal extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'start_value' => 'decimal:2', 'target_value' => 'decimal:2', 'current_value' => 'decimal:2',
            'weight' => 'integer', 'progress' => 'decimal:2', 'is_locked' => 'boolean',
            'start_date' => 'date', 'due_date' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function kra(): BelongsTo
    {
        return $this->belongsTo(Kra::class);
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(KeyResult::class)->orderBy('sort_order');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(GoalCheckIn::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'active'], true);
    }

    /** Progress implied by a value between start and target (clamped 0–100). */
    public static function progressFor(string $measureType, float $start, float $target, float $current): float
    {
        if ($measureType === 'boolean') {
            return $current >= 1 ? 100.0 : 0.0;
        }

        if ($target == $start) {
            return $current >= $target ? 100.0 : 0.0;
        }

        return round(max(0, min(100, ($current - $start) / ($target - $start) * 100)), 2);
    }
}
