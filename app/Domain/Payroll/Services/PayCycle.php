<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Contracts\PayCycleReader;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The pay cycle (tenant setting payroll.cycle_start_day): which days a payroll month covers, for every company of the
 * tenant. Day 1 is the calendar month; day 25 runs November from 25 October to 24 November, so attendance, loss of pay,
 * leave and proration all read that window.
 *
 * A month's dates are provisional until a payroll run is opened for it (or it closes): until then they follow the cycle
 * as it is now. Once fixed they never move, and the months either side start and end against them, so a change of
 * cycle never pays a day twice or leaves one out: the first month on the new cycle is shorter (1–24 Nov after a
 * calendar October) or longer (25 Oct – 30 Nov when going back to calendar months).
 */
final class PayCycle implements PayCycleReader
{
    public const SETTING = 'payroll.cycle_start_day';

    /** Up to the 28th, so every month has the day before the start. */
    public const MAX_DAY = 28;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function startDay(): int
    {
        return self::normalise($this->settings->get(self::SETTING));
    }

    public static function normalise(mixed $day): int
    {
        $day = is_numeric($day) ? (int) $day : 1;

        return $day >= 1 && $day <= self::MAX_DAY ? $day : 1;
    }

    /** "25th to 24th" or "calendar month (1st to last day)". */
    public static function describe(int $day): string
    {
        return $day === 1 ? 'calendar month (1st to last day)' : self::ordinal($day).' to '.self::ordinal($day - 1);
    }

    public static function ordinal(int $day): string
    {
        return $day.(in_array($day % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th'));
    }

    public function window(int $year, int $month, ?int $startDay = null): array
    {
        $day = $startDay ?? $this->startDay();
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return $day === 1 ? [$first, $first->endOfMonth()->startOfDay()] : [$first->subMonthNoOverflow()->day($day), $first->day($day - 1)];
    }

    public function monthOf(CarbonInterface $date, ?int $startDay = null): array
    {
        $day = $startDay ?? $this->startDay();
        $month = CarbonImmutable::create($date->year, $date->month, 1);
        if ($day > 1 && $date->day >= $day) {
            $month = $month->addMonthNoOverflow();
        }

        return [$month->year, $month->month];
    }

    /**
     * A company's dates for a payroll month: the cycle's window, starting the day after a fixed month before it and
     * ending the day before a fixed month after it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(int $companyId, int $year, int $month, ?int $startDay = null): array
    {
        [$start, $end] = $this->window($year, $month, $startDay);
        $first = CarbonImmutable::create($year, $month, 1);
        $before = $this->fixedPeriod($companyId, $first->subMonthNoOverflow());
        $after = $this->fixedPeriod($companyId, $first->addMonthNoOverflow());
        if ($before !== null) {
            $start = $before->end_date->toImmutable()->startOfDay()->addDay();
        }
        if ($after !== null) {
            $end = $after->start_date->toImmutable()->startOfDay()->subDay();
        }
        if ($start->gt($end)) {
            throw new RuntimeException("{$first->format('M Y')} has no days left between the payroll months either side of it.");
        }

        return [$start, $end];
    }

    /**
     * Change the tenant's cycle (audited as a setting change) and bring every month not yet fixed up to it; months
     * with a payroll run keep their dates.
     */
    public function change(int $startDay, ?string $reason = null): void
    {
        if ($startDay < 1 || $startDay > self::MAX_DAY) {
            throw new RuntimeException('A pay cycle starts on a day from the 1st to the '.self::ordinal(self::MAX_DAY).'.');
        }

        DB::transaction(function () use ($startDay, $reason) {
            $this->settings->set(self::SETTING, $startDay, $reason ?? 'Pay cycle changed to '.self::describe($startDay));
            PayrollPeriod::query()->where('status', 'open')->whereDoesntHave('runs')->orderBy('year')->orderBy('month')->get()
                ->each(fn (PayrollPeriod $p) => $this->period((int) $p->company_id, (int) $p->year, (int) $p->month));
        });
    }

    /** A company's payroll month, created on first use; a month not yet fixed is brought up to the cycle as it is now. */
    public function period(int $companyId, int $year, int $month): PayrollPeriod
    {
        $period = PayrollPeriod::query()->firstOrCreate(['company_id' => $companyId, 'year' => $year, 'month' => $month], function () use ($companyId, $year, $month) {
            [$start, $end] = $this->bounds($companyId, $year, $month);

            return ['start_date' => $start, 'end_date' => $end, 'status' => 'open'];
        });

        if (! $period->wasRecentlyCreated && ! $period->isFixed()) {
            [$start, $end] = $this->bounds($companyId, $year, $month);
            if (! $period->start_date->isSameDay($start) || ! $period->end_date->isSameDay($end)) {
                $period->update(['start_date' => $start, 'end_date' => $end]);
            }
        }

        return $period;
    }

    /**
     * The payroll month a date falls in for a company: the fixed month holding it, else the cycle's month.
     *
     * @return array{0: int, 1: int}
     */
    public function monthContaining(int $companyId, CarbonInterface $date, ?int $startDay = null): array
    {
        $fixed = PayrollPeriod::query()->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())
            ->get()->first(fn (PayrollPeriod $p) => $p->isFixed());

        return $fixed !== null ? [(int) $fixed->year, (int) $fixed->month] : $this->monthOf($date, $startDay);
    }

    /**
     * What the next months would cover for a company under a start day (the current cycle when null): the payroll
     * month holding today and the ones after it, each with its dates and whether they are already fixed.
     *
     * @return list<array{label: string, start: CarbonImmutable, end: CarbonImmutable, days: int, fixed: bool, note: ?string}>
     */
    public function preview(int $companyId, ?int $startDay = null, int $months = 4): array
    {
        $day = $startDay ?? $this->startDay();
        [$year, $month] = $this->monthContaining($companyId, today(), $day);
        $rows = [];
        for ($i = 0, $first = CarbonImmutable::create($year, $month, 1); $i < $months; $i++, $first = $first->addMonthNoOverflow()) {
            $existing = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $first->year)->where('month', $first->month)->first();
            $fixed = $existing?->isFixed() ?? false;
            [$start, $end] = $fixed ? [$existing->start_date->toImmutable(), $existing->end_date->toImmutable()] : $this->bounds($companyId, $first->year, $first->month, $day);
            [$windowStart, $windowEnd] = $this->window($first->year, $first->month, $day);
            $note = match (true) {
                $existing?->status === 'closed' => 'Closed: payroll finalized',
                $fixed => 'Payroll run open: dates stay as they are',
                ! $start->isSameDay($windowStart) || ! $end->isSameDay($windowEnd) => 'Changeover month: '.($start->lt($windowStart) || $end->gt($windowEnd) ? 'longer' : 'shorter').' than usual',
                default => null,
            };
            $rows[] = ['label' => $first->format('M Y'), 'start' => $start, 'end' => $end, 'days' => (int) $start->diffInDays($end) + 1, 'fixed' => $fixed, 'note' => $note];
        }

        return $rows;
    }

    private function fixedPeriod(int $companyId, CarbonImmutable $month): ?PayrollPeriod
    {
        $period = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $month->year)->where('month', $month->month)->first();

        return $period?->isFixed() ? $period : null;
    }
}
