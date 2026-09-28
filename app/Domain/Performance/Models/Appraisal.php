<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's appraisal in a cycle (§34): reviews, computed and calibrated scores, final rating. */
#[Fillable(['tenant_id', 'performance_cycle_id', 'employee_id', 'manager_id', 'status', 'goal_score', 'competency_score', 'self_rating', 'manager_rating', 'peer_rating', 'computed_rating', 'calibrated_rating', 'final_rating', 'final_label', 'calibration_note', 'manager_summary', 'promotion_recommended', 'pip_recommended', 'finalized_by', 'finalized_at', 'acknowledged_at', 'employee_comment', 'performance_template_version_id', 'locked_at', 'lock_version'])]
class Appraisal extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    protected static function booted(): void
    {
        // Phase 7: an appraisal locks when its cycle closes; nothing about it changes afterwards.
        static::updating(function (self $a) {
            if ($a->getRawOriginal('locked_at') !== null) {
                throw new \RuntimeException('This appraisal is locked: its cycle is closed.');
            }
        });
        static::deleting(function (self $a) {
            if ($a->locked_at !== null) {
                throw new \RuntimeException('This appraisal is locked: its cycle is closed.');
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(PerformanceTemplateVersion::class, 'performance_template_version_id');
    }

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime', 'lock_version' => 'integer',
            'goal_score' => 'decimal:2', 'competency_score' => 'decimal:2',
            'self_rating' => 'decimal:2', 'manager_rating' => 'decimal:2', 'peer_rating' => 'decimal:2',
            'computed_rating' => 'decimal:2', 'calibrated_rating' => 'decimal:2', 'final_rating' => 'decimal:2',
            'promotion_recommended' => 'boolean', 'pip_recommended' => 'boolean',
            'finalized_at' => 'datetime', 'acknowledged_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        $cycle = $this->relationLoaded('cycle') ? $this->cycle : $this->cycle()->first();

        return 'Appraisal '.($cycle?->code ?? '#'.$this->id);
    }

    public function auditSensitiveAttributes(): array
    {
        return ['calibration_note', 'manager_summary'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AppraisalReview::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function review(string $type): ?AppraisalReview
    {
        $reviews = $this->relationLoaded('reviews') ? $this->reviews : $this->reviews()->get();

        return $reviews->firstWhere('type', $type);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['finalized', 'acknowledged'], true);
    }

    /** The rating that stands: final, else calibrated, else computed. */
    public function effectiveRating(): ?float
    {
        $value = $this->final_rating ?? $this->calibrated_rating ?? $this->computed_rating;

        return $value === null ? null : (float) $value;
    }
}
