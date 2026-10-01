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
#[Fillable(['tenant_id', 'employee_id', 'course_id', 'course_version_id', 'learning_path_id', 'learning_path_version_id', 'learning_program_participant_id', 'training_session_id', 'learning_assignment_id', 'assignment_version', 'status', 'is_mandatory', 'priority', 'reason', 'completed_module_ids', 'progress', 'score', 'attempts', 'due_on', 'started_at', 'completed_at', 'expires_on', 'enrolled_by', 'requested_by', 'requested_at', 'approved_by', 'approved_at', 'decision_note', 'workflow_instance_id', 'cancelled_at', 'lock_version'])]
class LearningEnrolment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    /** Learning in progress or ready to start. */
    public const OPEN = ['assigned', 'enrolled', 'approved', 'in_progress', 'overdue'];

    /** Waiting for a decision or a seat. */
    public const PENDING = ['requested', 'pending_approval', 'waitlisted'];

    public const FINAL = ['completed', 'failed', 'withdrawn', 'expired', 'cancelled', 'rejected'];

    /** Phase 8 controlled transitions; any other status change is refused. */
    public const TRANSITIONS = [
        'requested' => ['pending_approval', 'approved', 'enrolled', 'rejected', 'cancelled'],
        'pending_approval' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['enrolled', 'in_progress', 'waitlisted', 'withdrawn', 'cancelled'],
        'assigned' => ['in_progress', 'overdue', 'waitlisted', 'completed', 'failed', 'withdrawn', 'cancelled'],
        'enrolled' => ['in_progress', 'overdue', 'waitlisted', 'completed', 'failed', 'withdrawn', 'cancelled'],
        'waitlisted' => ['enrolled', 'withdrawn', 'cancelled'],
        'in_progress' => ['overdue', 'completed', 'failed', 'withdrawn', 'cancelled'],
        'overdue' => ['in_progress', 'completed', 'failed', 'withdrawn', 'cancelled'],
        'completed' => ['expired'],
        'failed' => [], 'withdrawn' => [], 'expired' => [], 'cancelled' => [], 'rejected' => [],
    ];

    protected $attributes = ['status' => 'enrolled'];

    protected static function booted(): void
    {
        static::saving(function (self $e) {
            if ($e->progress !== null && ((float) $e->progress < 0 || (float) $e->progress > 100)) {
                throw new \RuntimeException('Progress must be between 0 and 100.');
            }
        });
        static::updating(function (self $e) {
            $from = $e->getRawOriginal('status');
            if ($e->isDirty('status')) {
                if (! in_array($e->status, self::TRANSITIONS[$from] ?? [], true)) {
                    throw new \RuntimeException("An enrolment cannot move from {$from} to {$e->status}.");
                }
                // Completion is a finalized record, not a status flip: it must exist first.
                if ($e->status === 'completed' && ! LearningCompletion::query()->withoutGlobalScope(\App\Domain\Identity\Scopes\AccessScope::class)->where('learning_enrolment_id', $e->id)->where('status', 'final')->exists()) {
                    throw new \RuntimeException('Record the completion through the completion service.');
                }
            } elseif (in_array($from, ['completed', 'expired', 'failed', 'withdrawn', 'cancelled', 'rejected'], true)
                && array_diff(array_keys($e->getDirty()), ['updated_at', 'lock_version']) !== []) {
                throw new \RuntimeException('A closed enrolment is read-only.');
            }
            if (! $e->isDirty('lock_version')) {
                $e->lock_version = (int) $e->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Enrolments are never deleted; cancel or withdraw them.'));
    }

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean', 'completed_module_ids' => 'array', 'progress' => 'decimal:2', 'score' => 'decimal:2', 'attempts' => 'integer',
            'due_on' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_on' => 'date',
            'requested_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'lock_version' => 'integer', 'assignment_version' => 'integer',
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

    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    public function pathVersion(): BelongsTo
    {
        return $this->belongsTo(LearningPathVersion::class, 'learning_path_version_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(LearningCompletion::class)->orderBy('sequence');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
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
