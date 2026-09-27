<?php

namespace App\Domain\Attendance\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** attendance.regularisation_requested / _approved / _rejected, attendance.overtime_recorded, attendance.exception */
final class AttendanceEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Employee $employee, public readonly Model $subject, public readonly array $context = []) {}
}
