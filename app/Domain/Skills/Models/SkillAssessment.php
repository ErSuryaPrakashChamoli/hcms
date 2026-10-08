<?php

namespace App\Domain\Skills\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Skill;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8: a structured skill assessment on a pinned scale version. draft → finalized
 * (immutable) → superseded (only when a correction is finalized). `private_notes` is encrypted,
 * hidden and readable only by the assessor or skills.private_notes.
 */
#[Fillable(['tenant_id', 'employee_id', 'skill_id', 'skill_scale_version_id', 'assessment_type', 'assessor_user_id', 'assessor_employee_id', 'assessed_on', 'level', 'target_level', 'evidence', 'comments', 'private_notes', 'valid_until', 'status', 'finalized_at', 'finalized_by', 'corrects_assessment_id', 'correction_reason', 'lock_version'])]
class SkillAssessment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const TYPES = ['self' => 'Self assessment', 'manager' => 'Manager assessment', 'formal' => 'Formal assessment', 'certification' => 'Certification'];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['private_notes'];

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            if (! array_key_exists($a->assessment_type, self::TYPES)) {
                throw new \RuntimeException("Unknown assessment type '{$a->assessment_type}'.");
            }
        });
        static::updating(function (self $a) {
            $from = $a->getRawOriginal('status');
            if ($from === 'finalized' && ! (array_diff(array_keys($a->getDirty()), ['status', 'updated_at', 'lock_version']) === [] && $a->status === 'superseded')) {
                throw new \RuntimeException('A finalized assessment is immutable; record a correction instead.');
            }
            if ($from === 'superseded') {
                throw new \RuntimeException('A superseded assessment is read-only.');
            }
            if (! $a->isDirty('lock_version')) {
                $a->lock_version = (int) $a->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(function (self $a) {
            if ($a->status !== 'draft') {
                throw new \RuntimeException('Only a draft assessment can be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['assessed_on' => 'date', 'level' => 'decimal:2', 'target_level' => 'decimal:2', 'valid_until' => 'date', 'finalized_at' => 'datetime', 'private_notes' => 'encrypted', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'skills';
    }

    public function auditLabel(): string
    {
        return 'Skill assessment #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['private_notes', 'comments', 'evidence'];
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

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_user_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['finalized', 'superseded'], true);
    }
}
