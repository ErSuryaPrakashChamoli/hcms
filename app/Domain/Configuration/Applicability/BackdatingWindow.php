<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Attendance\Contracts\AttendanceCycle;
use App\Domain\Attendance\Contracts\AttendancePeriodState;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Carbon\CarbonImmutable;

/**
 * How far back a configuration change may start for some companies (owner, 2026-11-26: "by default today's date, but the
 * calendar can pick a previous date … don't keep it open for any back dated days, it must meet the compliances"). Used by
 * "Applies to…" (EntityApplicability::earliestStart()) and "Company values…" (ConfigurationOverrides::earliestStart()),
 * which add their own history rule on top.
 *
 * Best practice for effective-dated configuration in payroll systems, chosen here, per company:
 * - at most back to the start of the previous payroll month (the company's own pay cycle: calendar months, or e.g. the
 *   25th to the 24th), so a change can still correct the month that just ended before its payroll is run;
 * - never into a month whose payroll is finalized or paid, or whose attendance is closed: payslips, statutory returns and
 *   closed attendance never change after the event (then the current month at the earliest, or today when the current
 *   month is already closed).
 * The latest of these across the companies wins.
 */
final class BackdatingWindow
{
    /**
     * @param  list<int>  $companyIds
     * @return array{date: string, reason: string} the earliest day (Y-m-d, today at the latest) and what sets it
     */
    public function for(array $companyIds): array
    {
        $today = CarbonImmutable::today();
        $earliest = null;
        $reason = 'it cannot be back-dated';
        $cycle = app(AttendanceCycle::class);
        $months = app(AttendancePeriodState::class);
        $ids = array_values(array_unique(array_map('intval', $companyIds)));
        $names = $ids === [] ? [] : Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($ids)->pluck('name', 'id')->all();

        foreach ($ids as $companyId) {
            $name = $names[$companyId] ?? 'a company';
            try {
                [$year, $month] = $cycle->monthOf($companyId, $today);
                $current = CarbonImmutable::create($year, $month, 1);
                $previous = $current->subMonthNoOverflow();
                $currentStart = $cycle->window($companyId, $current->year, $current->month)[0];
                $previousStart = $cycle->window($companyId, $previous->year, $previous->month)[0];
            } catch (\RuntimeException) {
                // A pay cycle that cannot say: calendar months.
                $current = $today->startOfMonth();
                $previous = $current->subMonthNoOverflow();
                [$currentStart, $previousStart] = [$current, $previous];
            }
            $closed = function (CarbonImmutable $month) use ($cycle, $months, $companyId, $name): ?string {
                if ($cycle->isFinal($companyId, $month->year, $month->month)) {
                    return 'payroll for '.$month->format('M Y').' is finalized for '.$name;
                }

                return ($months->monthState($companyId, $month->year, $month->month)['status'] ?? null) === 'closed'
                    ? 'attendance for '.$month->format('M Y').' is closed for '.$name : null;
            };
            [$date, $why] = match (true) {
                ($why = $closed($current)) !== null => [$today, $why],
                ($why = $closed($previous)) !== null => [$currentStart, $why],
                default => [$previousStart, 'a change can reach back to the start of the previous payroll month ('.$previous->format('M Y').') at most'],
            };
            $date = $date->gt($today) ? $today : $date;
            if ($earliest === null || $date->gt($earliest)) {
                [$earliest, $reason] = [$date, $why];
            }
        }

        return ['date' => ($earliest ?? $today)->toDateString(), 'reason' => $reason];
    }

    /**
     * The later of two limits (the window and a history rule), with what sets it.
     *
     * @param  array{date: string, reason: string}  $a
     * @param  array{date: string, reason: string}|null  $b
     * @return array{date: string, reason: string}
     */
    public static function later(array $a, ?array $b): array
    {
        if ($b === null) {
            return $a;
        }
        $today = CarbonImmutable::today()->toDateString();
        $b['date'] = min($b['date'], $today);

        return $b['date'] > $a['date'] ? $b : $a;
    }
}
