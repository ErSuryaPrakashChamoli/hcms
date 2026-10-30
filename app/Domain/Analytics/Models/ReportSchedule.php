<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** "Attendance report every Monday" (§84). */
#[Fillable(['tenant_id', 'report_id', 'frequency', 'day_of_week', 'day_of_month', 'time', 'recipient_user_ids', 'format', 'last_run_at', 'next_run_at', 'status'])]
class ReportSchedule extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'frequency' => 'weekly', 'format' => 'csv', 'time' => '07:00'];

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            if ($s->next_run_at === null || $s->isDirty(['frequency', 'day_of_week', 'day_of_month', 'time'])) {
                $s->next_run_at = $s->computeNextRun(now());
            }
        });
    }

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'day_of_month' => 'integer', 'recipient_user_ids' => 'array', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];
    }

    public function auditModule(): string
    {
        return 'analytics';
    }

    public function auditLabel(): string
    {
        return ucfirst($this->frequency).' schedule';
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** Next occurrence strictly after $from. */
    public function computeNextRun(Carbon $from): Carbon
    {
        return self::nextOccurrence((string) $this->frequency, $this->day_of_week, $this->day_of_month, $this->time, $from);
    }

    /**
     * The cadence rule shared with the e-mailed dashboard views (HROne parity CA-10): daily at the time; weekly on an ISO
     * day (1 = Monday, the default); monthly on a day 1–28 (default 1); the time defaults to 07:00. Strictly after $from.
     */
    public static function nextOccurrence(string $frequency, ?int $dayOfWeek, ?int $dayOfMonth, ?string $time, Carbon $from): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $time ?: '07:00'));
        $candidate = $from->copy()->setTime($h, $m, 0);

        return match ($frequency) {
            'daily' => $candidate->lte($from) ? $candidate->addDay() : $candidate,
            'monthly' => (function () use ($candidate, $from, $dayOfMonth) {
                $day = max(1, min(28, (int) ($dayOfMonth ?: 1)));
                $c = $candidate->copy()->day($day);

                return $c->lte($from) ? $c->addMonthNoOverflow()->day($day) : $c;
            })(),
            default => (function () use ($candidate, $from, $dayOfWeek) {
                $dow = (int) ($dayOfWeek ?? 1); // 1 = Monday
                $c = $candidate->copy();
                while ($c->dayOfWeekIso !== $dow || $c->lte($from)) {
                    $c->addDay();
                }

                return $c;
            })(),
        };
    }
}
