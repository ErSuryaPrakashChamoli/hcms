<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A quiz: questions [{question, options[], answer (index), marks}]. Answers are never exposed to learners. */
#[Fillable(['tenant_id', 'course_id', 'title', 'questions', 'passing_score', 'time_limit_minutes', 'shuffle'])]
class Assessment extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['questions' => 'array', 'passing_score' => 'integer', 'time_limit_minutes' => 'integer', 'shuffle' => 'boolean'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['questions'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    /** Questions without their answers, for presenting to a learner. */
    public function questionsForLearner(): array
    {
        return collect($this->questions ?? [])->values()->map(fn ($q, $i) => ['index' => $i, 'question' => $q['question'], 'options' => array_values($q['options'] ?? []), 'marks' => (int) ($q['marks'] ?? 1)])->all();
    }

    public function totalMarks(): int
    {
        return (int) collect($this->questions ?? [])->sum(fn ($q) => (int) ($q['marks'] ?? 1));
    }
}
