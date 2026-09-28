<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7: a calibration meeting for part of a cycle — facilitator, participants (user ids) and the
 * population (appraisal ids). Confidential: visible only with performance.calibrate. No ranking,
 * forced distribution or automated decision: people record each adjustment with a reason.
 */
#[Fillable(['tenant_id', 'performance_cycle_id', 'name', 'facilitator_id', 'participants', 'population', 'status', 'closed_by', 'closed_at'])]
class CalibrationSession extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'open'];

    protected static function booted(): void
    {
        static::updating(function (self $s) {
            if ($s->getRawOriginal('status') === 'closed') {
                throw new \RuntimeException('A closed calibration session is read-only.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Calibration sessions are never deleted.'));
    }

    protected function casts(): array
    {
        return ['participants' => 'array', 'population' => 'array', 'closed_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PerformanceCycle::class, 'performance_cycle_id');
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CalibrationAdjustment::class)->orderBy('id');
    }

    public function includes(int $appraisalId): bool
    {
        return in_array($appraisalId, array_map('intval', $this->population ?? []), true);
    }
}
