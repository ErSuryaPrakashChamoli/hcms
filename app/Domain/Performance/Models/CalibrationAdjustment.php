<?php

namespace App\Domain\Performance\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 7: one calibration decision — original, previous and adjusted rating, reason, actor, time. Append-only. */
#[Fillable(['tenant_id', 'calibration_session_id', 'appraisal_id', 'original_rating', 'previous_rating', 'adjusted_rating', 'reason', 'actor_id'])]
class CalibrationAdjustment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Calibration history is immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Calibration history is immutable.'));
    }

    protected function casts(): array
    {
        return ['original_rating' => 'decimal:2', 'previous_rating' => 'decimal:2', 'adjusted_rating' => 'decimal:2'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(Appraisal::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
