<?php

namespace App\Domain\Succession\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Designation;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9: a readiness label recorded by an authorised person for an employee and a target
 * (critical position or role) — with reason, evidence and effective dates. Immutable; a newer
 * assessment for the same target supersedes it. A label, never a prediction.
 */
#[Fillable(['tenant_id', 'employee_id', 'critical_position_id', 'designation_id', 'successor_id', 'target_key', 'readiness_level', 'reason', 'evidence', 'assessed_by', 'assessed_at', 'effective_from', 'effective_to', 'status', 'superseded_at'])]
class ReadinessAssessment extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'current'];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            if (! array_key_exists($r->readiness_level, config('peopleos.talent.readiness_levels'))) {
                throw new \RuntimeException("Unknown readiness level '{$r->readiness_level}'.");
            }
            if (trim((string) $r->reason) === '') {
                throw new \RuntimeException('A readiness assessment needs a reason.');
            }
        });
        static::updating(function (self $r) {
            if ($r->getRawOriginal('status') !== 'current' || array_diff(array_keys($r->getDirty()), ['status', 'superseded_at', 'effective_to', 'updated_at']) !== []) {
                throw new \RuntimeException('Readiness assessments are immutable; record a new one.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Readiness assessments are never deleted.'));
    }

    protected function casts(): array
    {
        return ['assessed_at' => 'datetime', 'effective_from' => 'date', 'effective_to' => 'date', 'superseded_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'succession';
    }

    public function auditLabel(): string
    {
        return 'Readiness assessment #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['readiness_level', 'reason', 'evidence'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(CriticalPosition::class, 'critical_position_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public static function targetKey(?int $criticalPositionId, ?int $designationId): string
    {
        return $criticalPositionId ? 'position:'.$criticalPositionId : 'role:'.$designationId;
    }
}
