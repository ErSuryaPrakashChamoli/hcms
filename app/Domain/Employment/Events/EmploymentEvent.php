<?php

namespace App\Domain\Employment\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Employment domain events (architecture contract §7, reserved names): employee.created,
 * employee.transferred, employee.promoted, employee.manager_changed, employee.salary_changed,
 * employee.department_changed, employee.designation_changed, employee.location_changed,
 * employee.company_changed, employee.rehired. Emitted only by the authoritative actions.
 *
 * @property array<string, mixed> $context
 */
final class EmploymentEvent
{
    use Dispatchable;

    public const NAMES = [
        'employee.created', 'employee.transferred', 'employee.promoted', 'employee.manager_changed', 'employee.salary_changed',
        'employee.department_changed', 'employee.designation_changed', 'employee.location_changed', 'employee.company_changed', 'employee.rehired',
    ];

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Employee $employee, public readonly Model $subject, public readonly array $context = [])
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("Unknown employment event [{$name}].");
        }
    }
}
