<?php

namespace App\Domain\Learning\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: an immutable snapshot of a course as delivered — curriculum, assessment, provider,
 * instructor, validity, pass mark and cost. Enrolments, completions and certificates pin it, so a
 * later catalogue change never rewrites what an employee actually completed.
 */
#[Fillable(['tenant_id', 'course_id', 'version', 'title', 'description', 'delivery_mode', 'duration_minutes', 'learning_provider_id', 'learning_instructor_id', 'curriculum', 'assessment', 'skill_outcomes', 'passing_score', 'attempts_allowed', 'validity_months', 'cost', 'currency', 'checksum', 'status', 'effective_from', 'published_by', 'published_at'])]
class CourseVersion extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (self $v) {
            $v->checksum = hash('sha256', (string) json_encode([$v->title, $v->description, $v->delivery_mode, $v->duration_minutes, $v->learning_provider_id, $v->learning_instructor_id, $v->curriculum, $v->assessment, $v->skill_outcomes, $v->passing_score, $v->attempts_allowed, $v->validity_months, $v->cost, $v->currency]));
        });
        static::updating(function (self $v) {
            if (array_diff(array_keys($v->getDirty()), ['status', 'updated_at']) !== []) {
                throw new \RuntimeException('A published course version is immutable; publish a new version.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Course versions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'curriculum' => 'array', 'assessment' => 'array', 'skill_outcomes' => 'array', 'duration_minutes' => 'integer', 'passing_score' => 'integer', 'attempts_allowed' => 'integer', 'validity_months' => 'integer', 'cost' => 'decimal:2', 'effective_from' => 'date', 'published_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'learning';
    }

    public function auditLabel(): string
    {
        return "{$this->title} v{$this->version}";
    }

    public function auditSensitiveAttributes(): array
    {
        return ['assessment'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LearningProvider::class, 'learning_provider_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(LearningInstructor::class, 'learning_instructor_id');
    }

    public function hours(): ?float
    {
        return $this->duration_minutes === null ? null : round($this->duration_minutes / 60, 2);
    }
}
