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
 * employee.address_changed, employee.emergency_contact_changed, employee.family_member_changed;
 * HROne parity MS-3: employee.qualification_changed, employee.experience_changed, employee.profile_skill_changed (the
 * new People actions) and profile.change_requested / _approved / _rejected (self-service profile change requests; the
 * first starts approval workflows). Emitted only by the authoritative actions and ProfileChanges.
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
        // HROne setup parity (Workforce): transfer requests and retirement from the separation policy.
        'employee.transfer_requested', 'employee.retirement_due',
        // HROne parity MS-3: the new profile actions and self-service profile change requests (references only).
        'employee.qualification_changed', 'employee.experience_changed', 'employee.profile_skill_changed',
        'profile.change_requested', 'profile.change_approved', 'profile.change_rejected',
    ];

    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $name, public readonly Employee $employee, public readonly Model $subject, public readonly array $context = [])
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("Unknown employment event [{$name}].");
        }
    }
}
