<?php

namespace App\Domain\Skills\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\People\Models\Skill;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: one entry in an employee's skill history — level on a pinned scale version, its source
 * (self, manager, assessment, certification, learning, imported, system) and whether it is
 * verified. A self-declared level is never verified. A newer entry supersedes the previous one;
 * values of an entry never change.
 */
#[Fillable(['tenant_id', 'employee_id', 'skill_id', 'skill_scale_version_id', 'current_level', 'target_level', 'source', 'is_verified', 'evidence', 'skill_assessment_id', 'learning_completion_id', 'assessed_by', 'assessed_on', 'valid_from', 'valid_to', 'confidence', 'status', 'superseded_at', 'created_by'])]
class EmployeeSkill extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const SOURCES = ['self' => 'Self-declared', 'manager' => 'Manager', 'assessment' => 'Assessment', 'certification' => 'Certification', 'learning' => 'Learning completion', 'imported' => 'Imported', 'system' => 'System'];

    /** Sources that may be marked verified. */
    public const VERIFIABLE = ['assessment', 'certification', 'learning'];

    protected $attributes = ['status' => 'current'];

    protected static function booted(): void
    {
        static::saving(function (self $s) {
            if (! array_key_exists($s->source, self::SOURCES)) {
                throw new \RuntimeException("Unknown skill source '{$s->source}'.");
            }
            if ($s->is_verified && ! in_array($s->source, self::VERIFIABLE, true)) {
                throw new \RuntimeException('Only an assessment, certification or learning completion verifies a skill level.');
            }
            if ($s->valid_to && $s->valid_from && $s->valid_to->lt($s->valid_from)) {
                throw new \RuntimeException('A skill level cannot end before it starts.');
            }
        });
        static::updating(function (self $s) {
            if (array_diff(array_keys($s->getDirty()), ['status', 'superseded_at', 'valid_to', 'updated_at']) !== [] || $s->getRawOriginal('status') !== 'current') {
                throw new \RuntimeException('Skill history is immutable; record a new level instead.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Skill history is never deleted.'));
    }

    protected function casts(): array
    {
        return ['current_level' => 'decimal:2', 'target_level' => 'decimal:2', 'is_verified' => 'boolean', 'assessed_on' => 'date', 'valid_from' => 'date', 'valid_to' => 'date', 'superseded_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'skills';
    }

    public function auditLabel(): string
    {
        return 'Skill level '.($this->relationLoaded('skill') ? $this->skill?->name : '#'.$this->skill_id);
    }

    public function auditSensitiveAttributes(): array
    {
        return ['evidence'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function scaleVersion(): BelongsTo
    {
        return $this->belongsTo(SkillScaleVersion::class, 'skill_scale_version_id');
    }

    /** Gap on the pinned scale version (target − current), never negative; null without a target. */
    public function gap(): ?float
    {
        if ($this->target_level === null) {
            return null;
        }

        return max(0.0, (float) $this->target_level - (float) ($this->current_level ?? 0));
    }
}
