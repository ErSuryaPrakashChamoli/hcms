<?php

namespace App\Domain\Exit\Services;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity (Separation policy → retirement age): an employee's retirement date from the exit policy that
 * applies to them: the birthday at the policy's retirement age, or the end of that month when the policy says so. No
 * retirement age (0 or blank) or no date of birth = no retirement date. Used by the retirement-due reminder and shown
 * read-only on My profile (MS-2).
 */
final class RetirementDates
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /** @return array{date: Carbon, age: int}|null */
    public function for(Employee $employee, ?Carbon $on = null): ?array
    {
        $employee->loadMissing('person');
        $dob = $employee->person?->date_of_birth;
        if ($dob === null) {
            return null;
        }
        $policy = $this->policies->resolve('exit', $employee, $on ?? now()->startOfDay());
        $age = (int) ($policy?->setting('retirement_age') ?? 0);
        if ($age <= 0) {
            return null;
        }
        $retires = Carbon::parse($dob)->addYears($age);
        if ($policy->setting('retire_on') === 'month_end') {
            $retires = $retires->endOfMonth()->startOfDay();
        }

        return ['date' => $retires, 'age' => $age];
    }
}
