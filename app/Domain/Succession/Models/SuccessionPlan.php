<?php

namespace App\Domain\Succession\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 9: the succession plan of a critical position. draft → active ⇄ under review → closed.
 * One open plan per position (active_key). Confidential notes are encrypted and hidden.
 * The plan never appoints anyone: succession decisions stay with people and the employment actions.
 */
#[Fillable(['tenant_id', 'critical_position_id', 'status', 'active_key', 'vacancy_risk', 'review_date', 'owner_user_id', 'confidential_notes', 'workflow_instance_id', 'closed_by', 'closed_at', 'closure_reason', 'lock_version', 'created_by'])]
class SuccessionPlan extends Model
{
    use Auditable, BelongsToTenant;

    public const TRANSITIONS = ['draft' => ['active', 'closed'], 'active' => ['under_review', 'closed'], 'under_review' => ['active', 'closed'], 'closed' => []];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['confidential_notes'];

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->active_key = 'plan:'.$p->critical_position_id);
        static::saving(function (self $p) {
            if ($p->vacancy_risk !== null && ! array_key_exists($p->vacancy_risk, config('peopleos.talent.vacancy_risk_levels'))) {
                throw new \RuntimeException("Unknown vacancy risk '{$p->vacancy_risk}'.");
            }
        });
        static::updating(function (self $p) {
            $from = $p->getRawOriginal('status');
            if ($from === 'closed') {
                throw new \RuntimeException('A closed succession plan is read-only.');
            }
            if ($p->isDirty('status') && ! in_array($p->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new \RuntimeException("A succession plan cannot move from {$from} to {$p->status}.");
            }
            if ($p->isDirty('critical_position_id')) {
                throw new \RuntimeException('A succession plan stays with its critical position.');
            }
            if ($p->status === 'closed') {
                $p->active_key = null;
            }
            if (! $p->isDirty('lock_version')) {
                $p->lock_version = (int) $p->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Succession plans are closed, never deleted.'));
    }

    protected function casts(): array
    {
        return ['review_date' => 'date', 'closed_at' => 'datetime', 'confidential_notes' => 'encrypted', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'succession';
    }

    public function auditLabel(): string
    {
        return 'Succession plan #'.$this->id;
    }

    public function auditSensitiveAttributes(): array
    {
        return ['confidential_notes', 'closure_reason'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(CriticalPosition::class, 'critical_position_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(Successor::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'closed';
    }
}
