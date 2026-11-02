<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HR decision about one day (HP-1 "change attendance status" / "override working hours"): the status and/or
 * worked minutes the engine must use. Approved full-day leave still wins; everything else yields to it.
 *
 * HROne parity TX-6: the status may instead be given per half (first_half_status / second_half_status, both set or both
 * null). Both null keeps the whole-day behaviour exactly as before.
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'status', 'first_half_status', 'second_half_status', 'worked_minutes', 'reason', 'created_by'])]
class AttendanceOverride extends Model
{
    use Auditable, BelongsToTenant;
    use ScopedByEmployee;

    public const STATUSES = ['present', 'absent', 'half_day', 'weekly_off', 'holiday', 'wfh', 'on_duty', 'field_duty'];

    /** HROne parity TX-6: the statuses a half of the day can take (a "half day" is what two different halves make). */
    public const HALF_STATUSES = ['present', 'absent', 'weekly_off', 'holiday', 'wfh', 'on_duty', 'field_duty'];

    protected function casts(): array
    {
        return ['date' => 'date', 'worked_minutes' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'attendance';
    }

    public function auditLabel(): string
    {
        return 'Attendance override '.$this->date?->toDateString();
    }

    /** HROne parity TX-6: HR gave each half of the day its own status. */
    public function hasHalves(): bool
    {
        return $this->first_half_status !== null && $this->second_half_status !== null;
    }

    /** "Present / Absent" — the two halves for people (null without halves). */
    public function halvesLabel(): ?string
    {
        return $this->hasHalves() ? self::halfLabel((string) $this->first_half_status).' / '.self::halfLabel((string) $this->second_half_status) : null;
    }

    public static function halfLabel(string $status): string
    {
        return (string) config("peopleos.attendance.statuses.{$status}", str_replace('_', ' ', $status));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
