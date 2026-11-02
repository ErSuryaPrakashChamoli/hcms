<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne parity GO-15: one occurrence of a company event (an event held once has one; a recurring event has one per day or
 * week of its rule, generated when the event is saved). Starts are stored in UTC and shown in the event's time zone.
 */
#[Fillable(['tenant_id', 'event_id', 'sequence', 'starts_at', 'duration_minutes'])]
class CompanyEventSession extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'sequence' => 'integer', 'duration_minutes' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return 'Event session #'.$this->sequence;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'event_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(CompanyEventAttendance::class, 'session_id');
    }

    public function endsAt(): CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes((int) $this->duration_minutes);
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->lte(now());
    }

    /** "Tue 12 Nov 2026, 10:00–11:30 (Asia/Kolkata)" in the event's time zone. */
    public function label(?string $timezone = null): string
    {
        $zone = $timezone ?: 'UTC';
        $start = $this->starts_at->copy()->setTimezone($zone);
        $end = $this->endsAt()->copy()->setTimezone($zone);

        return $start->format('D j M Y, H:i').'–'.($end->isSameDay($start) ? $end->format('H:i') : $end->format('j M H:i')).' ('.$zone.')';
    }
}
