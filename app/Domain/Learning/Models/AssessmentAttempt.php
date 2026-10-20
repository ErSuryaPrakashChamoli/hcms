<?php

namespace App\Domain\Learning\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'assessment_id', 'learning_enrolment_id', 'employee_id', 'answers', 'score', 'passed', 'submitted_at'])]
class AssessmentAttempt extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        // Phase 8: a submitted attempt is a finalized result.
        static::updating(fn () => throw new \RuntimeException('Assessment attempts are immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Assessment attempts are immutable.'));
    }

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'decimal:2', 'passed' => 'boolean', 'submitted_at' => 'datetime'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LearningEnrolment::class, 'learning_enrolment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
