<?php

namespace App\Domain\Talent\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9: a confidential talent assessment on a pinned model version. draft → final (immutable)
 * → superseded (by a correction). Ratings are the person's judgement per dimension; nothing is
 * combined into a hidden score. Confidential notes are encrypted and hidden.
 */
#[Fillable(['tenant_id', 'employee_id', 'talent_assessment_model_version_id', 'talent_review_item_id', 'ratings', 'rationale', 'confidential_notes', 'assessed_by', 'assessed_at', 'status', 'corrects_assessment_id', 'correction_reason', 'lock_version'])]
class TalentAssessment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['confidential_notes'];

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            $from = $a->getRawOriginal('status');
            if ($from === 'final' && ! ($a->status === 'superseded' && array_diff(array_keys($a->getDirty()), ['status', 'updated_at', 'lock_version']) === [])) {
                throw new \RuntimeException('A final talent assessment is immutable; record a correction instead.');
            }
            if ($from === 'superseded') {
                throw new \RuntimeException('A superseded talent assessment is read-only.');
            }
            if (! $a->isDirty('lock_version')) {
                $a->lock_version = (int) $a->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(function (self $a) {
            if ($a->status !== 'draft') {
                throw new \RuntimeException('Only a draft talent assessment can be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['ratings' => 'array', 'confidential_notes' => 'encrypted', 'assessed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'talent';
    }

    public function auditLabel(): string
    {
        return 'Talent assessment #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['ratings', 'rationale', 'confidential_notes'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(TalentAssessmentModelVersion::class, 'talent_assessment_model_version_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
