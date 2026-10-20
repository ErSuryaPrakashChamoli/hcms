<?php

namespace App\Domain\Career\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Organisation\Models\Designation;
use App\Domain\People\Models\Skill;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9: a career goal (separate from performance goals). It may link a target role, skill,
 * competency, course, learning path or development plan, but never creates a promotion, transfer
 * or salary change.
 */
#[Fillable(['tenant_id', 'employee_id', 'title', 'description', 'goal_type', 'target_designation_id', 'skill_id', 'target_level', 'competency_id', 'course_id', 'learning_path_id', 'development_plan_id', 'target_date', 'status', 'closed_at', 'closure_note', 'lock_version', 'created_by', 'updated_by'])]
class CareerGoal extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TRANSITIONS = ['active' => ['achieved', 'paused', 'abandoned'], 'paused' => ['active', 'abandoned'], 'achieved' => [], 'abandoned' => []];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $g) {
            if (! array_key_exists($g->goal_type, config('peopleos.career.goal_types'))) {
                throw new \RuntimeException("Unknown career goal type '{$g->goal_type}'.");
            }
        });
        static::updating(function (self $g) {
            $from = $g->getRawOriginal('status');
            if (in_array($from, ['achieved', 'abandoned'], true)) {
                throw new \RuntimeException('A closed career goal is read-only.');
            }
            if ($g->isDirty('status') && ! in_array($g->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A career goal cannot move from {$from} to {$g->status}.");
            }
            if ($g->isDirty('employee_id')) {
                throw new \RuntimeException('A career goal cannot move to another employee.');
            }
            if (! $g->isDirty('lock_version')) {
                $g->lock_version = (int) $g->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Career goals are closed, never deleted.'));
    }

    protected function casts(): array
    {
        return ['target_level' => 'decimal:2', 'target_date' => 'date', 'closed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'career';
    }

    public function auditLabel(): string
    {
        return 'Career goal: '.$this->title;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function targetDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'target_designation_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
