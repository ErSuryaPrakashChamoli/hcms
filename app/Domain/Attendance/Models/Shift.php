<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A shift definition (§13). type: fixed (timed) | flexible (hours only). */
#[Fillable(['tenant_id', 'name', 'code', 'type', 'start_time', 'end_time', 'crosses_midnight', 'timezone', 'break_minutes', 'full_day_minutes', 'half_day_minutes', 'grace_in_minutes', 'grace_out_minutes', 'overtime_eligible', 'min_overtime_minutes', 'status', 'effective_from', 'effective_to'])]
class Shift extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'overtime_eligible' => 'boolean',
            'break_minutes' => 'integer',
            'full_day_minutes' => 'integer',
            'half_day_minutes' => 'integer',
            'grace_in_minutes' => 'integer',
            'grace_out_minutes' => 'integer',
            'min_overtime_minutes' => 'integer',
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function isFlexible(): bool
    {
        return $this->type === 'flexible' || $this->start_time === null;
    }

    /** Scheduled start on a given work date, computed in the business timezone and returned in the app timezone. */
    public function startsAt(Carbon $date, ?string $timezone = null): ?Carbon
    {
        if ($this->start_time === null) {
            return null;
        }

        return Carbon::parse($date->toDateString().' '.$this->start_time, $timezone ?: $this->timezone ?: config('app.timezone'))->setTimezone(config('app.timezone'));
    }

    public function endsAt(Carbon $date, ?string $timezone = null): ?Carbon
    {
        if ($this->end_time === null) {
            return null;
        }

        $end = Carbon::parse($date->toDateString().' '.$this->end_time, $timezone ?: $this->timezone ?: config('app.timezone'));

        return ($this->crosses_midnight ? $end->addDay() : $end)->setTimezone(config('app.timezone'));
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(ShiftBreak::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Unpaid break minutes: configured breaks when present, otherwise the legacy single break_minutes. */
    public function unpaidBreakMinutes(): int
    {
        $breaks = $this->relationLoaded('breaks') ? $this->breaks : $this->breaks()->get();

        return $breaks->isEmpty() ? (int) $this->break_minutes : (int) $breaks->where('is_paid', false)->sum('duration_minutes');
    }

    public function scheduledMinutes(): int
    {
        return $this->full_day_minutes;
    }

    public function timingLabel(): string
    {
        return $this->isFlexible()
            ? 'Flexible · '.round($this->full_day_minutes / 60, 1).' h'
            : substr((string) $this->start_time, 0, 5).' – '.substr((string) $this->end_time, 0, 5).($this->crosses_midnight ? ' (+1)' : '');
    }
}
