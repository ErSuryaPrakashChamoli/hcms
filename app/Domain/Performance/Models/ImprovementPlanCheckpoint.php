<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 7: a dated review point in an improvement plan. Reviewed checkpoints are read-only. */
#[Fillable(['tenant_id', 'improvement_plan_id', 'title', 'due_date', 'notes', 'outcome', 'status', 'reviewed_by', 'reviewed_at'])]
class ImprovementPlanCheckpoint extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::updating(function (self $c) {
            if ($c->getRawOriginal('status') !== 'pending') {
                throw new \RuntimeException('A reviewed checkpoint is read-only.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Checkpoints are never deleted.'));
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return 'PIP checkpoint '.$this->due_date?->toDateString();
    }

    public function auditSensitiveAttributes(): array
    {
        return ['notes', 'outcome'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ImprovementPlan::class, 'improvement_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
