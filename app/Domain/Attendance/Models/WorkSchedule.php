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

/**
 * Weekly pattern(s) of shifts (§13, §14). pattern = [['mon' => shiftId|null, ...], ...]:
 * one entry is a plain weekly schedule, several entries rotate week by week.
 */
#[Fillable(['tenant_id', 'name', 'code', 'pattern', 'status', 'effective_from', 'effective_to'])]
class WorkSchedule extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected function casts(): array
    {
        return [
            'pattern' => 'array',
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

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkScheduleAssignment::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(WorkScheduleRule::class);
    }

    /** Shift id for a date, given the rotation anchor (the assignment start). Null = weekly off. */
    public function shiftIdFor(Carbon $date, ?Carbon $anchor = null): ?int
    {
        $weeks = array_values($this->pattern ?? []);

        if ($weeks === []) {
            return null;
        }

        $anchor = ($anchor ?? $this->effective_from ?? $date)->copy()->startOfWeek();
        $index = ((int) floor($anchor->diffInWeeks($date->copy()->startOfWeek(), false))) % count($weeks);
        $week = $weeks[$index < 0 ? $index + count($weeks) : $index];
        $day = strtolower($date->format('D'));

        return isset($week[$day]) && $week[$day] !== null && $week[$day] !== '' ? (int) $week[$day] : null;
    }

    public function isRotational(): bool
    {
        return count($this->pattern ?? []) > 1;
    }
}
