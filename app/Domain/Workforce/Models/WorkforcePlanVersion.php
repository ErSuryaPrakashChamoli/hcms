<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 10: one version of a workforce plan — period, scenario, currency and lines. Draft is the only
 * editable state; from submission the content is locked and from approval it is checksummed. A
 * correction is a new version. One active version per plan (nullable unique active_key). Planning
 * only: nothing here changes live positions or employees.
 */
#[Fillable(['tenant_id', 'workforce_plan_id', 'version', 'workforce_scenario_id', 'period_type', 'period_start', 'period_end', 'currency', 'status', 'active_key', 'effective_from', 'effective_to', 'notes', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'decision_note', 'supersedes_version_id', 'workflow_instance_id', 'checksum', 'created_by', 'lock_version'])]
class WorkforcePlanVersion extends Model
{
    use Auditable, BelongsToTenant;

    public const TRANSITIONS = [
        'draft' => ['submitted', 'archived'],
        'submitted' => ['under_review', 'draft', 'rejected'],
        'under_review' => ['approved', 'rejected', 'draft'],
        'approved' => ['active', 'archived'],
        'active' => ['superseded', 'archived'],
        'superseded' => ['archived'],
        'rejected' => ['archived'],
        'archived' => [],
    ];

    /** Fields that never change once the version leaves draft. */
    public const CONTENT = ['workforce_scenario_id', 'period_type', 'period_start', 'period_end', 'currency', 'notes', 'version', 'workforce_plan_id'];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(function (self $v) {
            if (! array_key_exists($v->period_type, config('peopleos.workforce.period_types'))) {
                throw new \RuntimeException("Unknown planning period type '{$v->period_type}'.");
            }
            if ($v->period_end !== null && $v->period_start !== null && $v->period_end->lt($v->period_start)) {
                throw new \RuntimeException('The planning period ends before it starts.');
            }
        });
        static::updating(function (self $v) {
            $from = $v->getRawOriginal('status');
            if ($v->isDirty('status') && ! in_array($v->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A workforce plan version cannot move from {$from} to {$v->status}.");
            }
            if ($from !== 'draft' && array_intersect(array_keys($v->getDirty()), self::CONTENT) !== []) {
                throw new \RuntimeException('A submitted or approved plan version is locked; create a new version to change it.');
            }
            if (! $v->isDirty('lock_version')) {
                $v->lock_version = (int) $v->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Workforce plan versions are archived, never deleted.'));
    }

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'effective_from' => 'date', 'effective_to' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'lock_version' => 'integer', 'version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    public function auditLabel(): string
    {
        return "Workforce plan version {$this->version}";
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkforcePlan::class, 'workforce_plan_id');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(WorkforceScenario::class, 'workforce_scenario_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkforcePlanLine::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(WorkforceBudget::class);
    }
}
