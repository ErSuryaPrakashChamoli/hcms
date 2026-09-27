<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Business timezone for an employee's attendance day (Phase 2 §9): the work location effective on
 * the date, then the shift's timezone, then the application timezone. Punches are stored in the
 * application timezone (UTC in production) and converted only for the calculation.
 */
final class AttendanceTimezone
{
    public function for(Employee $employee, ?Shift $shift, Carbon $date): string
    {
        $position = $employee->positions()->with('location')->effectiveOn($date)->first();

        return $position?->location?->timezone ?: ($shift?->timezone ?: config('app.timezone', 'UTC'));
    }
}
