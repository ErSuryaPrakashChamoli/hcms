<?php

namespace App\Domain\Employment\Events;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Employment domain events (architecture contract §7, reserved names): employee.created,
 * employee.transferred, employee.promoted, employee.manager_changed, employee.salary_changed,
 * employee.department_changed, employee.designation_changed, employee.location_changed,
 * employee.company_changed, employee.rehired; Phase 12: employee.bank_account_changed,
 * employee.statutory_identity_changed, employee.statutory_applicability_changed,
 * employee.address_changed, employee.emergency_contact_changed, employee.family_member_changed.
 * Emitted only by the authoritative actions.
 *
 * @property array<string, mixed> $context
 */
final class EmploymentEvent
{
    use Dispatchable;

    public const NAMES = [
        'employee.created', 'employee.transferred', 'employee.promoted', 'employee.manager_changed', 'employee.salary_changed',
        'employee.department_changed', 'employee.designation_changed', 'employee.location_changed', 'employee.company_changed', 'employee.rehired',
        // Phase 12 profile change actions (references only; never the values).
        'employee.bank_account_changed', 'employee.statutory_identity_changed', 'employee.statutory_applicability_changed',
        'employee.address_changed', 'employee.emergency_contact_changed', 'employee.family_member_changed',
    ];

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Employee $employee, public readonly Model $subject, public readonly array $context = [])
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("Unknown employment event [{$name}].");
        }
    }
}
