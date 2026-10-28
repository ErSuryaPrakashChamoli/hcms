<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's attendance as a monthly upload marked it, for organisations without punches. An input of the engine,
 * like punches and regularisations: AttendanceProcessor applies it to the day, approved leave still wins, and
 * a day locked by payroll is never recalculated. There is no leave mark: leave comes only from leave requests.
 * Not audited itself: each upload is one audited bulk operation whose rows keep what they marked, and the
 * attendance day the engine recalculates is audited.
 *
 * A mark is for the whole day (status), or for each half (first_half, second_half) with status the day they make.
 */
#[Fillable(['tenant_id', 'employee_id', 'date', 'status', 'first_half', 'second_half', 'source', 'employee_import_id', 'marked_by'])]
class AttendanceMark extends Model
{
    use BelongsToTenant;
    use ScopedByEmployee;

    /** "working" is a blank cell read as present: present on a working day, the schedule's weekly off or holiday otherwise. */
    public const STATUSES = ['present', 'absent', 'half_day', 'weekly_off', 'holiday', 'wfh', 'on_duty', 'working'];

    /** What a half of a day can be. A half day is not one: it is a present half and an absent half. */
    public const HALVES = ['present', 'absent', 'weekly_off', 'holiday', 'wfh', 'on_duty'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(EmployeeImport::class, 'employee_import_id');
    }

    /**
     * The day two halves make: absent with two absent halves, a half day with one, otherwise the half that says the
     * most about the day (present, then work from home, on duty, holiday, weekly off).
     */
    public static function combine(string $first, string $second): string
    {
        $absent = (int) ($first === 'absent') + (int) ($second === 'absent');
        if ($absent > 0) {
            return $absent === 2 ? 'absent' : 'half_day';
        }

        foreach (['present', 'wfh', 'on_duty', 'holiday', 'weekly_off'] as $status) {
            if (in_array($status, [$first, $second], true)) {
                return $status;
            }
        }

        return $first;
    }

    /**
     * The day's status. "working" is resolved against the day's holiday and schedule. On a day of approved half-day
     * leave, only the other half of a split mark counts: the leave is the half the employee is away.
     */
    public function statusOn(bool $holiday, bool $weeklyOff, ?string $leaveSession = null): string
    {
        if ($this->first_half !== null && $this->second_half !== null && in_array($leaveSession, ['first_half', 'second_half'], true)) {
            return $leaveSession === 'first_half' ? $this->second_half : $this->first_half;
        }
        if ($this->status !== 'working') {
            return $this->status;
        }

        return $holiday ? 'holiday' : ($weeklyOff ? 'weekly_off' : 'present');
    }

    /** The mark as written: "present", or "present|absent" for halves. */
    public function label(): string
    {
        return $this->first_half !== null ? "{$this->first_half}|{$this->second_half}" : $this->status;
    }
}
