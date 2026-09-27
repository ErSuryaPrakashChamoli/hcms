<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Support\StatutoryContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollPeriod;

/**
 * Resolves Employee → Establishment (on the period end date) → State → profiles. The state never
 * comes from the company's country. The engine caches one resolution per computation.
 */
final class StatutoryContexts
{
    public function __construct(private readonly EstablishmentAssignments $assignments, private readonly StatutoryRegistrations $registrations) {}

    public function for(Employee $employee, PayrollPeriod $period): StatutoryContext
    {
        return $this->resolve($employee, $period);
    }

    private function resolve(Employee $employee, PayrollPeriod $period): StatutoryContext
    {
        $on = $period->end_date->toDateString();
        $legacy = CompanyStatutoryProfile::query()->where('company_id', $period->company_id)->first()
            ?? new CompanyStatutoryProfile(CompanyStatutoryProfile::defaults() + ['company_id' => $period->company_id]);

        ['establishment' => $establishment, 'source' => $source] = $this->assignments->resolve($employee, $on);

        // An establishment of another company never applies to this company's payroll.
        if ($establishment !== null && (int) $establishment->company_id !== (int) $period->company_id) {
            $establishment = null;
            $source = 'foreign_company_ignored';
        }

        $profiles = [];
        $usesProfiles = $establishment !== null && $this->registrations->hasProfiles($establishment);
        if ($usesProfiles) {
            foreach (EstablishmentStatutoryProfile::STATUTES as $statute) {
                $profiles[$statute] = $this->registrations->profile($establishment, $statute, $on);
            }
        }

        $override = $employee->statutoryDetail?->pt_state_code;
        [$ptState, $ptSource] = match (true) {
            $establishment?->state !== null && $source === 'assignment' => [$establishment->state, 'establishment'],
            filled($override) => [$override, 'employee_override'],
            $establishment?->state !== null => [$establishment->state, 'establishment'],
            filled($legacy->pt_state) => [$legacy->pt_state, 'legacy_company_profile'],
            default => [null, null],
        };

        $lwfState = $usesProfiles || $ptSource === 'establishment' ? ($establishment?->state ?? $ptState) : ($legacy->lwf_state ?: $ptState);

        return new StatutoryContext($establishment, $source, $legacy, $profiles, $usesProfiles, $ptState, $ptSource, $lwfState);
    }
}
