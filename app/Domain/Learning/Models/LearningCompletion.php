<?php

namespace App\Domain\Learning\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: the finalized completion of a course version or program version. Append-only: a
 * correction is a new record (sequence + 1, corrects_completion_id, reason) and the original is
 * marked superseded — its values never change. Audited through LEARNING completion events.
 */
#[Fillable(['tenant_id', 'employee_id', 'learning_enrolment_id', 'learning_program_participant_id', 'sequence', 'course_id', 'course_version_id', 'learning_program_version_id', 'completed_at', 'completed_by', 'score', 'grade', 'attendance', 'hours', 'evidence', 'learning_provider_id', 'learning_instructor_id', 'status', 'corrects_completion_id', 'correction_reason'])]
class LearningCompletion extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            // The only change ever allowed: final → superseded when a correction is recorded.
            if (array_keys($c->getDirty()) !== ['status'] || $c->getRawOriginal('status') !== 'final' || $c->status !== 'superseded') {
                throw new \RuntimeException('A finalized completion is immutable; record a correction instead.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Completions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'completed_at' => 'datetime', 'score' => 'decimal:2', 'hours' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LearningEnrolment::class, 'learning_enrolment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function courseVersion(): BelongsTo
    {
        return $this->belongsTo(CourseVersion::class);
    }

    public function programVersion(): BelongsTo
    {
        return $this->belongsTo(LearningProgramVersion::class, 'learning_program_version_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_completion_id');
    }
}
