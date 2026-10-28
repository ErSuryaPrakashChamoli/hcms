<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Support\StatutoryContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use App\Domain\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Collection;

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

    /**
     * Whether each statute applies at an establishment on each date, decided as for an employee there: from the
     * establishment's profiles when it has any, otherwise from the company statutory settings. Compliance reports
     * use it so a pack decides as payroll did. Pass $profiles to decide for a proposed set instead of the stored one.
     *
     * @param  list<string>  $dates
     * @param  Collection<int, EstablishmentStatutoryProfile>|null  $profiles  all of the establishment's profiles
     * @return array<string, array<string, bool>> date => statute => applies
     */
    public function applicability(Establishment $establishment, array $dates, ?Collection $profiles = null): array
    {
        $profiles ??= EstablishmentStatutoryProfile::query()->withoutGlobalScope(AccessScope::class)->where('establishment_id', $establishment->getKey())->get();
        $settings = $this->companySettings((int) $establishment->company_id);
        $out = [];

        foreach ($dates as $on) {
            $effective = [];
            foreach (EstablishmentStatutoryProfile::STATUTES as $statute) {
                $effective[$statute] = $profiles->filter(fn (EstablishmentStatutoryProfile $p) => $p->statute === $statute && $p->isEffectiveOn($on))
                    ->sortByDesc(fn (EstablishmentStatutoryProfile $p) => $p->effective_from)->first();
            }
            $context = new StatutoryContext($establishment, null, $settings, $effective, $profiles->isNotEmpty(), null, null, null);
            $out[$on] = array_combine(EstablishmentStatutoryProfile::STATUTES, array_map($context->applies(...), EstablishmentStatutoryProfile::STATUTES));
        }

        return $out;
    }

    /** The company statutory settings payroll falls back to for an establishment without profiles (defaults if none saved). */
    public function companySettings(int $companyId): CompanyStatutoryProfile
    {
        return CompanyStatutoryProfile::query()->where('company_id', $companyId)->first()
            ?? new CompanyStatutoryProfile(CompanyStatutoryProfile::defaults() + ['company_id' => $companyId]);
    }

    private function resolve(Employee $employee, PayrollPeriod $period): StatutoryContext
    {
        $on = $period->end_date->toDateString();
        $legacy = $this->companySettings((int) $period->company_id);

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
