<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Support\GratuityDetermination;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\EmployeeEstablishmentAssignment;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Services\EstablishmentAssignments;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Gratuity entitlement (Code on Social Security, 2020 Chapter V; before 21 Nov 2025 the Payment of Gratuity Act, 1972),
 * decided under the GRATUITY rule version that governs the date. Its parameters decide the arithmetic: rate per month
 * of service, minimum_years, fixed_term_minimum_years, ceiling. Versions made under the Code carry
 * fixed_term_minimum_years (the Code's fixed-term provisos to s.53(1)); the Payment of Gratuity Act version does not.
 *
 * At exit: the establishment the employee belonged to on the last working day must be covered (First Schedule,
 * Chapter V, and s.1(8)); the qualifying service is completed years, waived on death; the amount counts a part year
 * only when it is in excess of six months, on the last drawn monthly wages (basic + DA), up to the ceiling. Facts a
 * person must decide (forfeiture after termination, the 240-day rule, fixed-term exits before the term ends, the
 * Code's 50% wage rule) make the result pending determination. A result under a version that is proposed, under an
 * open notice or (while enforcement is on) unverified is legally unverified: its amount is provisional, never paid.
 */
final class GratuityEntitlements
{
    /** First Schedule, Chapter V (a): covered whatever the headcount, as PeopleOS records establishment types and industries. */
    private const COVERED_TYPES = ['factory'];

    private const COVERED_INDUSTRIES = ['mining', 'plantation'];

    /** Death waives the qualifying service; disablement is not an exit type, so "other" asks. */
    private const WAIVED = ['death'];

    private const FORFEITURE_REVIEW = ['termination', 'absconding'];

    public function __construct(private readonly ComplianceRules $rules, private readonly EstablishmentAssignments $assignments, private readonly ReportCatalogue $catalogue) {}

    /** On the last working day, for the reason of exit (peopleos.exit.types). */
    public function onExit(Employee $employee, CarbonInterface|string $lastWorkingDay, string $exitType, ?float $monthlyWages, ?float $monthlyRemuneration = null): GratuityDetermination
    {
        $day = CarbonImmutable::parse($lastWorkingDay)->startOfDay();
        ['establishment' => $establishment, 'source' => $source] = $this->assignments->resolve($employee, $day);
        $coverage = $establishment !== null && in_array($source, ['assignment', 'location'], true) ? $this->coverage($establishment, $day)
            : ['status' => 'missing_configuration', 'reason' => "No establishment is recorded for the employee on {$day->toDateString()}; the company's primary establishment is not assumed.", 'establishment_id' => null];
        $type = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->with('employmentType')->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $day->toDateString())->orderByDesc('effective_from')->first()?->employmentType?->name;

        return $this->determine($employee, $day, $exitType, $monthlyWages, $monthlyRemuneration, $type, $coverage);
    }

    /** Gratuity on service so far (a register): no exit, and coverage is left to the report plan, per establishment. */
    public function accrued(Employee $employee, CarbonInterface|string $on, ?float $monthlyWages, ?string $employmentType, ?float $monthlyRemuneration = null): GratuityDetermination
    {
        return $this->determine($employee, CarbonImmutable::parse($on)->startOfDay(), null, $monthlyWages, $monthlyRemuneration, $employmentType, null);
    }

    /**
     * Whether an establishment is covered on a day: every factory, mine, oilfield, plantation, port and railway company;
     * any other with the catalogue's threshold of employees on any day of the preceding twelve months, and once covered,
     * always (s.1(8)). Counted from the employees PeopleOS holds at the establishment (assignment, else the position's
     * location), whoever is asking; contract labour and others it does not hold are not counted.
     *
     * @return array{status: string, reason: string, establishment_id: ?int, threshold: ?int, headcount_preceding_12_months?: int, headcount_max?: int}
     */
    public function coverage(Establishment $establishment, CarbonInterface|string $on): array
    {
        $day = CarbonImmutable::parse($on)->startOfDay();
        $threshold = $this->catalogue->find('gratuity_register')?->thresholds[0]['min'] ?? null;
        $base = ['establishment_id' => (int) $establishment->getKey(), 'threshold' => $threshold];

        if (in_array($establishment->establishment_type, self::COVERED_TYPES, true) || in_array($establishment->industry, self::COVERED_INDUSTRIES, true)) {
            return $base + ['status' => 'covered', 'reason' => "{$establishment->name} is a ".(in_array($establishment->establishment_type, self::COVERED_TYPES, true) ? 'factory' : config("peopleos.organisation.industries.{$establishment->industry}")).' establishment, covered whatever its size (First Schedule, Chapter V).'];
        }
        if ($threshold === null) {
            return $base + ['status' => 'missing_configuration', 'reason' => 'The compliance catalogue holds no gratuity threshold.'];
        }

        [$preceding, $max] = $this->headcounts($establishment, $day);
        $covered = $max >= $threshold;

        return $base + ['status' => $covered ? 'covered' : 'not_covered', 'headcount_preceding_12_months' => $preceding, 'headcount_max' => $max, 'reason' => $covered
            ? "{$establishment->name}: {$max} employees on its busiest day up to {$day->toDateString()} ({$preceding} in the preceding twelve months); the threshold is {$threshold}, and once covered an establishment stays covered."
            : "{$establishment->name}: at most {$max} employee(s) on any day up to {$day->toDateString()}, below the threshold of {$threshold}. Counted from the employees PeopleOS holds; contract labour and others it does not hold are not counted."];
    }

    private function determine(Employee $employee, CarbonImmutable $day, ?string $exitType, ?float $wages, ?float $remuneration, ?string $employmentType, ?array $coverage): GratuityDetermination
    {
        $accrual = $exitType === null;
        [$rule, $decisive, $notDecisive] = $this->version($day, $accrual);
        $fixedTerm = (bool) preg_match('/fixed/i', (string) $employmentType);
        $result = fn (string $status, string $reason, array $more = []) => new GratuityDetermination(...['status' => $status, 'reason' => $reason, 'accrual' => $accrual,
            'rule' => $rule, 'decisive' => $decisive, 'fixedTerm' => $fixedTerm, 'wages' => $wages] + ($coverage === null ? [] : ['coverage' => $coverage]) + $more);

        if ($rule === null) {
            return $result(GratuityDetermination::LEGALLY_UNVERIFIED, "No GRATUITY rule version applies on {$day->toDateString()}.");
        }
        if ($employee->joining_date === null) {
            return $result(GratuityDetermination::INSUFFICIENT_DATA, 'No date of joining, so no service to count.');
        }
        if (($coverage['status'] ?? null) === 'missing_configuration') {
            return $result(GratuityDetermination::PENDING, $coverage['reason']);
        }
        if (($coverage['status'] ?? null) === 'not_covered') {
            return $result(GratuityDetermination::NOT_COVERED, $coverage['reason']);
        }

        $underCode = array_key_exists('fixed_term_minimum_years', $rule->payload());
        $joined = CarbonImmutable::parse($employee->joining_date)->startOfDay();
        $end = $day->addDay(); // service runs to the end of the last day
        $completed = max(0, (int) floor($joined->diffInYears($end)));
        $anniversary = $joined->addYears($completed);
        $years = $completed + ($anniversary->addMonths(6)->lt($end) ? 1 : 0); // a part year counts only in excess of six months
        $partDays = (int) $anniversary->diffInDays($end);
        $service = "{$completed} y ".(int) floor($anniversary->diffInMonths($end)).' m';
        $waived = in_array($exitType, self::WAIVED, true);
        $minimum = $fixedTerm && $underCode ? $rule->param('fixed_term_minimum_years') : (int) $rule->param('minimum_years', 0);
        $qualifies = $waived || ($minimum !== null && $completed >= (int) $minimum);
        $amount = $wages === null ? null : round($wages * (float) $rule->param('rate') * 12 * $years, 2);
        $ceiling = $rule->param('ceiling');
        $capped = $amount !== null && $ceiling !== null && $amount > (float) $ceiling;
        $amount = $capped ? (float) $ceiling : $amount;
        $section = $underCode ? 'Code on Social Security, 2020 s.54' : 'Payment of Gratuity Act, 1972 s.2A';
        $more = ['serviceText' => $service, 'yearsCounted' => $years, 'minimumYears' => $minimum === null ? null : (int) $minimum, 'amount' => $amount, 'capped' => $capped,
            'paymentDue' => $accrual ? null : 'Within 30 days of becoming payable ('.($underCode ? 'Code on Social Security, 2020 s.56(3)' : 'Payment of Gratuity Act, 1972 s.7(3)').'), with interest if late.'];

        if ($wages === null && ($qualifies || $accrual)) {
            return $result(GratuityDetermination::INSUFFICIENT_DATA, 'No finalized payroll with basic or DA, so no last drawn wages.', $more);
        }
        if ($fixedTerm && $minimum === null && ! $waived) {
            return $result(GratuityDetermination::PENDING, 'Fixed-term employee: the rule version in use does not state the fixed-term qualifying service.', $more);
        }

        $reviews = array_values(array_filter([
            ! $accrual && in_array($exitType, self::FORFEITURE_REVIEW, true) && $qualifies
                ? 'Exit by '.config("peopleos.exit.types.{$exitType}", $exitType).': confirm whether gratuity is forfeited, wholly or in part ('.($underCode ? 'Code on Social Security, 2020 s.53(6)' : 'Payment of Gratuity Act, 1972 s.4(6)').'); forfeiture needs a written order.' : null,
            ! $accrual && $exitType === 'other' && ! $qualifies ? 'Exit recorded as "other": record whether it was death or disablement, which waive the qualifying service.' : null,
            ! $qualifies && $minimum !== null && $completed === (int) $minimum - 1 && $partDays >= 240
                ? "{$completed} years and {$partDays} days: a year in which 240 days were actually worked can count as a year of continuous service ({$section}); check attendance." : null,
            ! $accrual && $fixedTerm && $underCode && $exitType !== 'contract_expiry' ? 'Fixed-term employee leaving before the term ends: whether the fixed-term provision applies needs legal review.' : null,
            $this->wageShareReview($underCode, $wages, $remuneration, $rule, $years, $ceiling),
        ]));

        $counted = "{$years} year(s) counted, ".($waived ? 'qualifying service waived on death' : "{$minimum} needed").($fixedTerm ? ', fixed-term' : '');
        $outcome = $qualifies ? ($accrual ? 'qualifies' : 'eligible') : ($accrual ? 'not yet qualified' : 'not eligible');
        if (! $decisive) {
            return $result(GratuityDetermination::LEGALLY_UNVERIFIED, 'Provisionally '.$outcome." ({$counted}) under {$rule->label()}, which is not decisive: {$notDecisive}", $more + ['reviews' => $reviews]);
        }
        if ($reviews !== []) {
            return $result(GratuityDetermination::PENDING, ucfirst($outcome)." on the service ({$counted}), but a person must decide: ".implode(' ', $reviews), $more + ['reviews' => $reviews]);
        }

        return $result($qualifies ? GratuityDetermination::ELIGIBLE : GratuityDetermination::NOT_ELIGIBLE, ucfirst($counted).'.', $more);
    }

    /**
     * The version that governs the date: a proposed version prepared for a later legal position than the one in force
     * governs it provisionally. Decisive only when in force, with no open notice, and verified (always for registers;
     * at exit, while enforcement is on, as payroll requires).
     *
     * @return array{0: ?ComplianceRule, 1: bool, 2: ?string}
     */
    private function version(CarbonImmutable $day, bool $accrual): array
    {
        ['active' => $active, 'proposed' => $proposed] = $this->rules->resolveWithProposed('GRATUITY', $day);
        $rule = $proposed !== null && ($active === null || $proposed->effective_from->gt($active->effective_from)) ? $proposed : $active;
        if ($rule === null) {
            return [null, false, null];
        }
        $notice = $rule->isProposed() ? null : $this->rules->pendingNotice($rule, $day);
        $why = match (true) {
            $rule->isProposed() => 'it is prepared for review and not in force'.($active ? "; the version in force is {$active->label()}" : '').'.',
            $notice !== null => "an open regulatory notice affects it ({$notice->title}).",
            ($accrual || ComplianceRules::enforced()) && ! $rule->isVerified() => 'it is not verified against an official source.',
            default => null,
        };

        return [$rule, $why === null, $why];
    }

    /**
     * Code on Social Security s.2(88), first proviso: allowances excluded from wages count as wages to the extent they
     * exceed the configured share of total remuneration. Whether and which allowances count is owner decision C6, so
     * the difference is shown for a person to decide, never applied.
     */
    private function wageShareReview(bool $underCode, ?float $wages, ?float $remuneration, ComplianceRule $rule, int $years, mixed $ceiling): ?string
    {
        $share = config('peopleos.payroll.guardrails.wage_share.min_percent');
        if (! $underCode || $share === null || $wages === null || $remuneration === null || $remuneration <= 0 || $wages >= $remuneration * $share / 100) {
            return null;
        }
        $deemed = round($remuneration * $share / 100, 2);
        $alternative = round($deemed * (float) $rule->param('rate') * 12 * $years, 2);
        $alternative = $ceiling !== null ? min($alternative, (float) $ceiling) : $alternative;

        return 'Basic + DA ('.number_format($wages, 2).') is under '.$share.'% of remuneration ('.number_format($remuneration, 2).'): under the Code on Social Security s.2(88) wages would be at least '
            .number_format($deemed, 2).', giving '.number_format($alternative, 2).'. Which allowances count is owner decision C6.';
    }

    /** @return array{0: int, 1: int} the most employees on one day in the twelve months to $day, and on any day up to $day */
    private function headcounts(Establishment $establishment, CarbonImmutable $day): array
    {
        $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->whereNotNull('joining_date')->whereDate('joining_date', '<=', $day->toDateString())
            ->whereNotIn('lifecycle_state', ['pre_employee', 'preboarding'])
            ->whereHas('positions', fn ($q) => $q->withoutGlobalScope(AccessScope::class)->where('company_id', $establishment->company_id))
            ->get(['id', 'joining_date', 'exit_date']);
        $ids = $employees->modelKeys();
        $assignments = EmployeeEstablishmentAssignment::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)->get(['employee_id', 'establishment_id', 'effective_from', 'effective_to'])->groupBy('employee_id');
        $locations = Location::query()->withoutGlobalScope(AccessScope::class)->where('establishment_id', $establishment->getKey())->pluck('id');
        $positions = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $ids)->whereIn('location_id', $locations)->get(['employee_id', 'effective_from', 'effective_to'])->groupBy('employee_id');

        $intervals = [];
        foreach ($employees as $employee) {
            // Explicit assignments decide where an employee was; without any, the position's location does.
            $spans = $assignments->has($employee->id) ? $assignments->get($employee->id)->where('establishment_id', $establishment->getKey()) : $positions->get($employee->id, collect());
            foreach ($spans as $span) {
                $from = max($span->effective_from->toDateString(), $employee->joining_date->toDateString());
                $to = min($span->effective_to?->toDateString() ?? $day->toDateString(), $employee->exit_date?->toDateString() ?? $day->toDateString(), $day->toDateString());
                if ($from <= $to) {
                    $intervals[] = [$from, $to];
                }
            }
        }

        return [$this->peak($intervals, $day->subYear()->addDay()->toDateString()), $this->peak($intervals, null)];
    }

    /** @param  list<array{0: string, 1: string}>  $intervals */
    private function peak(array $intervals, ?string $from): int
    {
        $events = [];
        foreach ($intervals as [$start, $end]) {
            $start = $from !== null ? max($start, $from) : $start;
            if ($start <= $end) {
                $events[] = [$start, 1];
                $events[] = [CarbonImmutable::parse($end)->addDay()->toDateString(), -1];
            }
        }
        usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $current = $max = 0;
        foreach ($events as [, $delta]) {
            $max = max($max, $current += $delta);
        }

        return $max;
    }
}
