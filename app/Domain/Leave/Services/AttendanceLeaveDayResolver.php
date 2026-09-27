<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Contracts\LeaveDay;
use App\Domain\Attendance\Contracts\LeaveDayResolver;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/** Leave-side implementation of the Attendance leave contract. */
final class AttendanceLeaveDayResolver implements LeaveDayResolver
{
    public function approvedLeaveOn(Employee $employee, Carbon $date): ?LeaveDay
    {
        // Resolved lazily: Leaves itself depends on the attendance processor (container cycle otherwise).
        $leave = app(Leaves::class)->approvedOn($employee, $date);

        if ($leave === null) {
            return null;
        }

        return new LeaveDay($leave->id, $leave->sessionOn($date->toDateString()) ?? 'full', (bool) $leave->leaveType?->is_paid, $leave->leaveType?->code);
    }
}
