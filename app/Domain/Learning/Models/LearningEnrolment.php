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

/** One learner on one course (§37): progress by module, assessment score, due date, completion, expiry. */
#[Fillable(['tenant_id', 'employee_id', 'course_id', 'learning_path_id', 'learning_assignment_id', 'status', 'is_mandatory', 'completed_module_ids', 'progress', 'score', 'attempts', 'due_on', 'started_at', 'completed_at', 'expires_on', 'enrolled_by'])]
class LearningEnrolment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const OPEN = ['enrolled', 'in_progress', 'overdue'];

    protected $attributes = ['status' => 'enrolled'];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean', 'completed_module_ids' => 'array', 'progress' => 'decimal:2', 'score' => 'decimal:2', 'attempts' => 'integer',
            'due_on' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_on' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        $course = $this->relationLoaded('course') ? $this->course : $this->course()->first();

        return 'Enrolment: '.($course?->title ?? '#'.$this->course_id);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class, 'learning_path_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LearningAssignment::class, 'learning_assignment_id');
    }

    public function attemptsMade(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    public function enroller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasCompletedModule(int $moduleId): bool
    {
        return in_array($moduleId, $this->completed_module_ids ?? [], true);
    }
}
