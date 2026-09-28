<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
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
#[Fillable(['tenant_id', 'level', 'employee_id', 'organisation_node_id', 'parent_id', 'performance_cycle_id', 'kra_id', 'type', 'title', 'description', 'measure_type', 'start_value', 'target_value', 'current_value', 'unit', 'weight', 'progress', 'status', 'start_date', 'due_date', 'is_locked', 'created_by', 'source', 'measurement', 'lock_version'])]
class Goal extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft'];

    /** Alignment order: a goal aligns to a goal at its own level or above. */
    public const LEVEL_RANK = ['company' => 0, 'business' => 1, 'department' => 2, 'team' => 3, 'employee' => 4];

    /** What a goal locked for a review can no longer change. */
    public const LOCKED_FIELDS = ['title', 'measure_type', 'start_value', 'target_value', 'weight', 'parent_id', 'performance_cycle_id', 'employee_id', 'level'];

    protected static function booted(): void
    {
        // Phase 7 structural rules, enforced for every write path (service, Filament, API).
        static::saving(function (self $goal) {
            if ($goal->weight !== null && $goal->weight < 0) {
                throw new \RuntimeException('A goal weight cannot be negative.');
            }
            if ($goal->progress !== null && ((float) $goal->progress < 0 || (float) $goal->progress > 100)) {
                throw new \RuntimeException('Progress must be between 0 and 100.');
            }
            if ($goal->start_date && $goal->due_date && $goal->due_date->lt($goal->start_date)) {
                throw new \RuntimeException('The due date cannot be before the start date.');
            }
            if ($goal->isDirty('parent_id') && $goal->parent_id) {
                $goal->assertAlignment();
            }
            if ($goal->exists && $goal->getRawOriginal('is_locked') && ! $goal->isDirty('is_locked')) {
                foreach (self::LOCKED_FIELDS as $field) {
                    if ($goal->isDirty($field)) {
                        throw new \RuntimeException('This goal is locked for review; its definition cannot change.');
                    }
                }
            }
        });
        static::updating(function (self $goal) {
            if (! $goal->isDirty('lock_version')) {
                $goal->lock_version = (int) $goal->getRawOriginal('lock_version') + 1;
            }
        });
    }

    /** No self-alignment, no cycles, no aligning upward to a lower level. */
    public function assertAlignment(): void
    {
        $parent = self::query()->withoutGlobalScope(AccessScope::class)->find($this->parent_id);
        if ($parent === null) {
            throw new \RuntimeException('The goal to align to does not exist.');
        }
        if ((self::LEVEL_RANK[$parent->level] ?? 4) > (self::LEVEL_RANK[$this->level] ?? 4)) {
            throw new \RuntimeException('A goal can only align to a goal at its own level or above.');
        }
        $seen = [];
        for ($node = $parent; $node !== null; $node = $node->parent_id ? self::query()->withoutGlobalScope(AccessScope::class)->find($node->parent_id) : null) {
            if (($this->exists && $node->id === $this->id) || isset($seen[$node->id])) {
                throw new \RuntimeException('This alignment would create a circular goal chain.');
            }
            $seen[$node->id] = true;
        }
    }

    protected function casts(): array
    {
        return [
            'start_value' => 'decimal:2', 'target_value' => 'decimal:2', 'current_value' => 'decimal:2',
            'weight' => 'integer', 'progress' => 'decimal:2', 'is_locked' => 'boolean',
            'start_date' => 'date', 'due_date' => 'date', 'lock_version' => 'integer',
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
