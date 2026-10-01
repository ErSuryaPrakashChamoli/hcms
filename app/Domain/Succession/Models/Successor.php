<?php

namespace App\Domain\Succession\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9: a potential successor on a succession plan (a succession term — never a recruitment
 * candidate). One active entry per plan and employee; removed with a reason, never deleted.
 * Strengths, gaps and notes are confidential; the employee does not see their own candidacy unless
 * granted succession.own_candidacy.
 */
#[Fillable(['tenant_id', 'succession_plan_id', 'employee_id', 'status', 'active_key', 'strengths', 'development_gaps', 'confidential_notes', 'added_by', 'added_at', 'removed_by', 'removed_at', 'removal_reason', 'lock_version'])]
class Successor extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected $attributes = ['status' => 'active'];

    protected $hidden = ['confidential_notes'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->active_key = $s->succession_plan_id.':'.$s->employee_id);
        static::updating(function (self $s) {
            if ($s->getRawOriginal('status') === 'removed') {
                throw new \RuntimeException('A removed successor entry is read-only history.');
            }
            if ($s->isDirty(['succession_plan_id', 'employee_id'])) {
                throw new \RuntimeException('A successor entry keeps its plan and employee.');
            }
            if ($s->status === 'removed') {
                $s->active_key = null;
            }
            if (! $s->isDirty('lock_version')) {
                $s->lock_version = (int) $s->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Successor entries are removed with a reason, never deleted.'));
    }

    protected function casts(): array
    {
        return ['added_at' => 'datetime', 'removed_at' => 'datetime', 'confidential_notes' => 'encrypted', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'succession';
    }

    public function auditLabel(): string
    {
        return 'Successor entry #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['strengths', 'development_gaps', 'confidential_notes', 'removal_reason'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SuccessionPlan::class, 'succession_plan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function readiness(): HasMany
    {
        return $this->hasMany(ReadinessAssessment::class)->orderByDesc('id');
    }

    /** Adds `current_readiness`: the current readiness label for this successor's critical position, in one correlated subquery (no per-row lookups). */
    #[Scope]
    protected function withCurrentReadiness(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('successors.*');
        }

        return $query->addSelect(['current_readiness' => ReadinessAssessment::query()->withoutGlobalScope(AccessScope::class)
            ->select('readiness_assessments.readiness_level')
            ->join('succession_plans as readiness_plan', 'readiness_plan.critical_position_id', '=', 'readiness_assessments.critical_position_id')
            ->whereColumn('readiness_plan.id', 'successors.succession_plan_id')
            ->whereColumn('readiness_assessments.employee_id', 'successors.employee_id')
            ->where('readiness_assessments.status', 'current')
            ->orderByDesc('readiness_assessments.id')->limit(1)]);
    }
}
