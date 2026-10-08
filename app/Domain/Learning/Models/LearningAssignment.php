<?php

namespace App\Domain\Learning\Models;

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
 * A rule that enrols people (§37): one course or a path, to one employee or everyone matching
 * rule-engine conditions, due in N days, optionally recurring every N months (compliance refreshers).
 */
#[Fillable(['tenant_id', 'name', 'course_id', 'learning_path_id', 'employee_id', 'target_type', 'target_id', 'priority', 'reason', 'conditions', 'due_days', 'recur_months', 'is_mandatory', 'is_required', 'auto_enrol_new_joiners', 'status', 'operation_id', 'version', 'effective_from', 'effective_to', 'assigned_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_by'])]
class LearningAssignment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TARGETS = ['employee' => 'One employee', 'team' => 'A manager\'s team', 'organisation_unit' => 'An organisation unit', 'population' => 'A rule-defined population'];

    /** Changing any of these makes a new assignment version; enrolments record the version they came from. */
    public const VERSIONED = ['course_id', 'learning_path_id', 'employee_id', 'target_type', 'target_id', 'conditions', 'due_days', 'recur_months', 'is_mandatory', 'is_required'];

    protected $attributes = ['status' => 'active', 'target_type' => 'population', 'priority' => 'normal', 'version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (! array_key_exists($a->target_type, self::TARGETS)) {
                throw new \RuntimeException("Unknown assignment target '{$a->target_type}'.");
            }
            if ($a->target_type === 'employee' && ! $a->employee_id) {
                throw new \RuntimeException('An employee assignment needs the employee.');
            }
            if (in_array($a->target_type, ['team', 'organisation_unit'], true) && ! $a->target_id) {
                throw new \RuntimeException('A team or organisation-unit assignment needs its target.');
            }
            if (! $a->course_id && ! $a->learning_path_id) {
                throw new \RuntimeException('An assignment needs a course or a learning path.');
            }
        });
        static::updating(function (self $a) {
            if ($a->getRawOriginal('status') === 'cancelled') {
                throw new \RuntimeException('A cancelled assignment is read-only.');
            }
            if ($a->isDirty(self::VERSIONED) && ! $a->isDirty('version')) {
                $a->version = (int) $a->getRawOriginal('version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Assignments are cancelled, never deleted.'));
    }

    protected function casts(): array
    {
        return ['conditions' => 'array', 'due_days' => 'integer', 'recur_months' => 'integer', 'is_mandatory' => 'boolean', 'is_required' => 'boolean', 'auto_enrol_new_joiners' => 'boolean', 'version' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'assigned_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(LearningEnrolment::class);
    }
}
