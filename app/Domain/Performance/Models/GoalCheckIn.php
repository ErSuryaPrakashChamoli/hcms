<?php

namespace App\Domain\Performance\Models;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A progress update on a goal or key result (continuous check-in). Append-only (Phase 7): each row
 * keeps the previous and new value and progress, the measurement, source, author and time. HROne parity GO-4: the goal
 * health chosen at check-in (it becomes the goal's health) and the period the update covers (HROne "Task period").
 */
#[Fillable(['tenant_id', 'goal_id', 'key_result_id', 'previous_value', 'value', 'previous_progress', 'progress', 'confidence', 'source', 'idempotency_key', 'measurement', 'note', 'created_by', 'health', 'period_start', 'period_end'])]
class GoalCheckIn extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Goal progress history is immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Goal progress history is immutable.'));
    }

    public const CONFIDENCE = ['on_track' => 'On track', 'at_risk' => 'At risk', 'off_track' => 'Off track'];

    protected function casts(): array
    {
        return ['previous_value' => 'decimal:2', 'value' => 'decimal:2', 'previous_progress' => 'decimal:2', 'progress' => 'decimal:2', 'period_start' => 'date', 'period_end' => 'date'];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(KeyResult::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** GO-4: "01 Oct 2026 – 31 Oct 2026", one date, or null when no period was given. */
    public function periodLabel(): ?string
    {
        $from = $this->period_start?->format('d M Y');
        $to = $this->period_end?->format('d M Y');

        return $from === null && $to === null ? null : ($from === $to || $to === null ? $from : ($from === null ? $to : "{$from} – {$to}"));
    }

    public function healthLabel(): ?string
    {
        return $this->health === null ? null : (string) config("peopleos.performance.goal_health.{$this->health}", $this->health);
    }
}
