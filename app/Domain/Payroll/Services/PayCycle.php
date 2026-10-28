<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Contracts\PayCycleReader;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The pay cycle (tenant setting payroll.pay_cycle): which days a payroll month covers, for every company of the tenant.
 *
 * The setting is a list of dated changes, each the first day of the first month on a cycle: {from: 2026-11-25} runs
 * December from 25 November to 24 December and every month after it from the 25th to the 24th. Before the first
 * change, months are calendar months. A month is named after the month it ends in. The month before a change ends the
 * day before it starts (the changeover month: 1–24 Nov after a calendar October, 25 Oct – 30 Nov when going back to
 * calendar months), so no day is paid twice or left out.
 *
 * A month's dates are provisional until a payroll run is opened for it (or it closes): until then they follow the
 * cycle as it is now. Once fixed they never move: a change must start after the last fixed month, and the months
 * either side of a fixed month start and end against it.
 */
final class PayCycle implements PayCycleReader
{
    public const SETTING = 'payroll.pay_cycle';

    /** Up to the 28th, so every month has the day before the start. */
    public const MAX_DAY = 28;

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The tenant's cycle changes, oldest first; first = the first month on the change (year * 12 + month - 1).
     *
     * @return list<array{from: CarbonImmutable, day: int, first: int}>
     */
    public function changes(): array
    {
        $stored = $this->settings->get(self::SETTING);

        return self::normalise(is_array($stored) ? $stored : []);
    }

    /**
     * The changes with one more starting on $from: it replaces every change whose first month is not before its own.
     *
     * @return list<array{from: CarbonImmutable, day: int, first: int}>
     */
    public function withChange(CarbonInterface $from): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $first = self::firstMonth($from);
        $kept = array_filter($this->changes(), fn (array $c) => $c['first'] < $first);

        return self::normalise([...array_map(fn (array $c) => ['from' => $c['from']->toDateString()], $kept), ['from' => $from->toDateString()]]);
    }

    /**
     * Start a new cycle on $from (audited as a setting change) and bring every month not yet fixed up to it. Returns
     * false when the months would not change.
     */
    public function change(CarbonInterface $from, ?string $reason = null): bool
    {
        $from = CarbonImmutable::parse($from->toDateString());
        if ($from->day > self::MAX_DAY) {
            throw new RuntimeException('A pay cycle starts on a day from the 1st to the '.self::ordinal(self::MAX_DAY).', so every month has the day before it.');
        }
        $fixedUntil = $this->fixedUntil();
        if ($fixedUntil !== null && $from->lte($fixedUntil)) {
            throw new RuntimeException("Choose a date after {$fixedUntil->format('j M Y')}: the payroll months up to then already have a payroll run, and their dates stay as they are.");
        }

        $changes = $this->withChange($from);
        if (self::stored($changes) === self::stored($this->changes())) {
            return false;
        }
        $this->save($changes, $reason ?? 'Pay cycle from '.$from->format('j M Y').': '.self::describe($from->day));

        return true;
    }

    /** The changes that have not started yet (later than today and than every fixed month). */
    public function scheduled(): array
    {
        $after = collect([today()->toImmutable(), $this->fixedUntil()])->filter()->max();

        return array_values(array_filter($this->changes(), fn (array $c) => $c['from']->gt($after)));
    }

    /** Cancel the changes that have not started yet; returns how many. */
    public function cancelScheduled(?string $reason = null): int
    {
        $scheduled = $this->scheduled();
        if ($scheduled === []) {
            return 0;
        }
        $cancelled = array_map(fn (array $c) => $c['from']->toDateString(), $scheduled);
        $keep = array_filter($this->changes(), fn (array $c) => ! in_array($c['from']->toDateString(), $cancelled, true));
        $this->save(array_values($keep), $reason ?? 'Scheduled pay cycle change cancelled');

        return count($scheduled);
    }

    /** The day months start on at a date (today when null): 1 for calendar months. */
    public function startDay(?CarbonInterface $on = null): int
    {
        return $this->inForce($on)['day'] ?? 1;
    }

    /** The change in force on a date (today when null), or null while months are calendar months from the start. */
    public function inForce(?CarbonInterface $on = null): ?array
    {
        $on ??= today();

        return collect($this->changes())->filter(fn (array $c) => $c['from']->lte($on))->last();
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

    /** The days a month starting on $day covers, ignoring changes: day 25 gives November 25 Oct – 24 Nov. */
    public static function cycleWindow(int $year, int $month, int $day): array
    {
        $first = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return $day === 1 ? [$first, $first->endOfMonth()->startOfDay()] : [$first->subMonthNoOverflow()->day($day), $first->day($day - 1)];
    }

    public function window(int $year, int $month, ?array $changes = null): array
    {
        $changes ??= $this->changes();
        $at = self::monthIndex($year, $month);
        $day = 1;
        $next = null;
        foreach ($changes as $change) {
            if ($change['first'] > $at) {
                $next = $change;
                break;
            }
            $day = $change['day'];
        }
        [$start, $end] = self::cycleWindow($year, $month, $day);

        // The changeover month ends the day before the next cycle starts.
        return [$start, $next !== null && $next['first'] === $at + 1 ? $next['from']->subDay() : $end];
    }

    public function monthOf(CarbonInterface $date, ?array $changes = null): array
    {
        $changes ??= $this->changes();
        $date = CarbonImmutable::parse($date->toDateString());
        $day = collect($changes)->filter(fn (array $c) => $c['from']->lte($date))->last()['day'] ?? 1;
        $at = self::monthIndex($date->year, $date->month) + ($day > 1 && $date->day >= $day ? 1 : 0);

        // Around a change the month holding the date can be the one either side.
        foreach ([$at, $at - 1, $at + 1] as $candidate) {
            [$start, $end] = $this->window(intdiv($candidate, 12), $candidate % 12 + 1, $changes);
            if ($date->betweenIncluded($start, $end)) {
                return [intdiv($candidate, 12), $candidate % 12 + 1];
            }
        }

        return [intdiv($at, 12), $at % 12 + 1];
    }

    /**
     * A company's dates for a payroll month: the cycle's window, starting the day after a fixed month before it and
     * ending the day before a fixed month after it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(int $companyId, int $year, int $month, ?array $changes = null): array
    {
        [$start, $end] = $this->window($year, $month, $changes);
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
    public function monthContaining(int $companyId, CarbonInterface $date, ?array $changes = null): array
    {
        $fixed = PayrollPeriod::query()->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())
            ->get()->first(fn (PayrollPeriod $p) => $p->isFixed());

        return $fixed !== null ? [(int) $fixed->year, (int) $fixed->month] : $this->monthOf($date, $changes);
    }

    /** The last day of the latest payroll month of the tenant with a run (or closed); a new cycle starts after it. */
    public function fixedUntil(): ?CarbonImmutable
    {
        $end = PayrollPeriod::query()->where(fn ($q) => $q->where('status', 'closed')->orWhereHas('runs'))->max('end_date');

        return $end !== null ? CarbonImmutable::parse($end)->startOfDay() : null;
    }

    /**
     * What a company's months cover from a month on, under the given changes (the saved ones when null), each with
     * its dates and what is special about it.
     *
     * @return list<array{label: string, start: CarbonImmutable, end: CarbonImmutable, days: int, state: ?string, note: ?string}>
     */
    public function preview(int $companyId, int $year, int $month, ?array $changes = null, int $months = 5): array
    {
        $changes ??= $this->changes();
        $firsts = array_column($changes, 'first');
        $rows = [];
        $dayOf = fn (CarbonImmutable $m) => collect($changes)->filter(fn (array $c) => $c['first'] <= self::monthIndex($m->year, $m->month))->last()['day'] ?? 1;
        for ($i = 0, $first = CarbonImmutable::create($year, $month, 1); $i < $months; $i++, $first = $first->addMonthNoOverflow()) {
            $existing = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $first->year)->where('month', $first->month)->first();
            $fixed = $existing?->isFixed() ?? false;
            [$start, $end] = $fixed ? [$existing->start_date->toImmutable(), $existing->end_date->toImmutable()] : $this->bounds($companyId, $first->year, $first->month, $changes);
            [$cycleStart, $cycleEnd] = self::cycleWindow($first->year, $first->month, $dayOf($first));
            $days = (int) $start->diffInDays($end) + 1;
            [$state, $note] = match (true) {
                $existing?->status === 'closed' => ['closed', 'Payroll finalized'],
                $fixed => ['fixed', 'Payroll run open: dates stay as they are'],
                in_array(self::monthIndex($first->year, $first->month), $firsts, true) => ['first', 'First month on the new cycle: '.self::describe($dayOf($first))],
                ! $start->isSameDay($cycleStart) || ! $end->isSameDay($cycleEnd) => ['changeover', ($days > $cycleStart->diffInDays($cycleEnd) + 1 ? 'Longer' : 'Shorter').' month: the changeover to the next cycle'],
                default => [null, null],
            };
            $rows[] = ['label' => $first->format('M Y'), 'start' => $start, 'end' => $end, 'days' => $days, 'state' => $state, 'note' => $note];
        }

        return $rows;
    }

    /** The first day of the first month on a cycle starting on $from: the month whose window starts on it. */
    public static function firstMonth(CarbonInterface $from): int
    {
        return self::monthIndex($from->year, $from->month) + ($from->day > 1 ? 1 : 0);
    }

    public static function monthIndex(int $year, int $month): int
    {
        return $year * 12 + $month - 1;
    }

    /**
     * Valid changes, oldest first, each a later first month than the one before; a change to the day already in force
     * changes nothing and is dropped.
     *
     * @param  array<int, mixed>  $stored
     * @return list<array{from: CarbonImmutable, day: int, first: int}>
     */
    private static function normalise(array $stored): array
    {
        $changes = [];
        foreach ($stored as $row) {
            try {
                $from = is_array($row) && is_string($row['from'] ?? null) ? CarbonImmutable::parse($row['from'])->startOfDay() : null;
            } catch (Throwable) {
                $from = null;
            }
            if ($from !== null && $from->day <= self::MAX_DAY) {
                $changes[] = ['from' => $from, 'day' => $from->day, 'first' => self::firstMonth($from)];
            }
        }
        usort($changes, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        $kept = [];
        foreach ($changes as $change) {
            while ($kept !== [] && $kept[array_key_last($kept)]['first'] >= $change['first']) {
                array_pop($kept);
            }
            $dayBefore = $kept === [] ? 1 : $kept[array_key_last($kept)]['day'];
            if ($dayBefore !== $change['day']) {
                $kept[] = $change;
            }
        }

        return array_values($kept);
    }

    /** @return list<array{from: string}> */
    private static function stored(array $changes): array
    {
        return array_map(fn (array $c) => ['from' => $c['from']->toDateString()], $changes);
    }

    private function save(array $changes, string $reason): void
    {
        DB::transaction(function () use ($changes, $reason) {
            $this->settings->set(self::SETTING, self::stored($changes), $reason);
            PayrollPeriod::query()->where('status', 'open')->whereDoesntHave('runs')->get()
                ->each(fn (PayrollPeriod $p) => $this->period((int) $p->company_id, (int) $p->year, (int) $p->month));
        });
    }

    private function fixedPeriod(int $companyId, CarbonImmutable $month): ?PayrollPeriod
    {
        $period = PayrollPeriod::query()->where('company_id', $companyId)->where('year', $month->year)->where('month', $month->month)->first();

        return $period?->isFixed() ? $period : null;
    }
}
