<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne setup parity HP-9f: the identity side of a mood check-in — that the employee checked in for a period, with a
 * date only (no time, no timestamps) and no key shared with the check-in itself (MoodCheckIn). It is what keeps one
 * check-in per person and period. Not Auditable: an automatic audit would stamp the person and the exact time.
 */
#[Fillable(['tenant_id', 'employee_id', 'period_key', 'checked_in_on'])]
class MoodParticipation extends Model
{
    use BelongsToTenant, ScopedByEmployee;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Mood participations are never changed.'));
        static::deleting(fn () => throw new RuntimeException('Mood participations are never deleted.'));
    }

    protected function casts(): array
    {
        return ['checked_in_on' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
