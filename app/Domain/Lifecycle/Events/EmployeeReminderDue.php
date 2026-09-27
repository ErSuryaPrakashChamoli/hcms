<?php

namespace App\Domain\Lifecycle\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;

/** Time-based lifecycle events: employee.joining_due, employee.probation_ending, employee.probation_overdue. */
final class EmployeeReminderDue
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly Employee $employee, public readonly string $name, public readonly array $context = []) {}
}
