<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Boundary between Attendance and Leave: Attendance only asks "is there approved leave on this
 * day, and is it paid / half-day". Balances and approvals stay in the Leave domain.
 */
interface LeaveDayResolver
{
    public function approvedLeaveOn(Employee $employee, Carbon $date): ?LeaveDay;
}
