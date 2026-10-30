<?php

namespace App\Domain\Onboarding\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeeBankAccount;
use App\Domain\Employment\Models\EmployeeStatutoryDetail;
use App\Domain\Identity\Scopes\AccessScope;

/**
 * HROne setup parity P13 (task settings → task groups such as profile completion): whether a section of an employee's
 * profile is filled in, for onboarding items of type profile_section (peopleos.onboarding.profile_sections). The check
 * is deliberately simple: personal details need the date of birth, gender and a personal phone or e-mail; the other
 * sections need at least one record (statutory: a PAN).
 */
final class ProfileSections
{
    public static function filled(Employee $employee, string $section): bool
    {
        $person = $employee->person()->first();

        return match ($section) {
            'personal' => $person !== null && $person->date_of_birth !== null && filled($person->gender) && (filled($person->personal_phone) || filled($person->personal_email)),
            'address' => $person !== null && $person->addresses()->exists(),
            'family' => $person !== null && $person->familyMembers()->exists(),
            'emergency_contact' => $person !== null && $person->emergencyContacts()->exists(),
            'education' => $person !== null && $person->qualifications()->exists(),
            'previous_employment' => $person !== null && $person->experiences()->exists(),
            'bank' => EmployeeBankAccount::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->exists(),
            'statutory' => filled(EmployeeStatutoryDetail::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->value('pan')),
            default => false,
        };
    }

    public static function label(string $section): string
    {
        return (string) config("peopleos.onboarding.profile_sections.{$section}", $section);
    }
}
