<?php

namespace App\Domain\ComplianceReports\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting period: a month, quarter, half-year or year, on the financial-year (April–March) or calendar basis.
 * Keys are stable strings stored on packs: "M:2026-09", "FY:2026:Q2", "FY:2026:H1", "FY:2026", "CY:2026:Q1",
 * "CY:2026:H2", "CY:2026" (FY years are the year the financial year starts in).
 */
final class ReportPeriod
{
    public const FREQUENCIES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'yearly' => 'Yearly'];

    public const BASES = ['financial' => 'Financial year (April–March)', 'calendar' => 'Calendar year (January–December)'];

    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];

    private function __construct(
        public readonly string $frequency,
        public readonly string $basis,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public static function month(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return new self('monthly', 'calendar', $start, $start->endOfMonth()->startOfDay());
    }

    /**
     * The period of a frequency that contains a date. A month has no basis; the others start in April (financial)
     * or January (calendar).
     */
    public static function containing(string $frequency, string $basis, CarbonImmutable|string $date): self
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        if ($frequency === 'monthly') {
            return self::month($date->year, $date->month);
        }
        if (! isset(self::MONTHS[$frequency]) || ! isset(self::BASES[$basis])) {
            throw new InvalidArgumentException("Unknown period {$frequency} / {$basis}.");
        }

        $firstMonth = $basis === 'financial' ? (int) config('peopleos.compliance.financial_year_start_month', 4) : 1;
        $offset = ($date->month - $firstMonth + 12) % 12;
        $length = self::MONTHS[$frequency];
        $start = $date->startOfMonth()->subMonths($offset % $length);

        return new self($frequency, $basis, $start, $start->addMonths($length)->subDay());
    }

    public static function fromKey(string $key): self
    {
        if (preg_match('/^M:(\d{4})-(\d{2})$/', $key, $m)) {
            return self::month((int) $m[1], (int) $m[2]);
        }
        if (! preg_match('/^(FY|CY):(\d{4})(?::(Q[1-4]|H[12]))?$/', $key, $m)) {
            throw new InvalidArgumentException("Unknown period key {$key}.");
        }

        $basis = $m[1] === 'FY' ? 'financial' : 'calendar';
        $firstMonth = $basis === 'financial' ? (int) config('peopleos.compliance.financial_year_start_month', 4) : 1;
        $yearStart = CarbonImmutable::create((int) $m[2], $firstMonth, 1);
        $part = $m[3] ?? '';
        [$frequency, $index] = match ($part[0] ?? '') {
            'Q' => ['quarterly', (int) substr($part, 1)],
            'H' => ['half_yearly', (int) substr($part, 1)],
            default => ['yearly', 1],
        };

        return self::containing($frequency, $basis, $yearStart->addMonths(($index - 1) * self::MONTHS[$frequency]));
    }

    public function key(): string
    {
        if ($this->frequency === 'monthly') {
            return 'M:'.$this->start->format('Y-m');
        }

        $prefix = ($this->basis === 'financial' ? 'FY:' : 'CY:').$this->yearStart()->year;

        return match ($this->frequency) {
            'quarterly' => $prefix.':Q'.$this->index(),
            'half_yearly' => $prefix.':H'.$this->index(),
            default => $prefix,
        };
    }

    public function label(): string
    {
        $span = $this->start->format('M Y').' – '.$this->end->format('M Y');

        return match ($this->frequency) {
            'monthly' => $this->start->format('F Y'),
            'quarterly' => 'Q'.$this->index().' '.$this->yearLabel().' ('.$span.')',
            'half_yearly' => 'H'.$this->index().' '.$this->yearLabel().' ('.$span.')',
            default => $this->yearLabel().' ('.$span.')',
        };
    }

    /** "FY 2026-27" or "Calendar 2026". */
    public function yearLabel(): string
    {
        $year = $this->yearStart()->year;

        return $this->basis === 'financial' ? 'FY '.$year.'-'.substr((string) ($year + 1), -2) : 'Calendar '.$year;
    }

    /** The months of the period, oldest first. */
    public function months(): array
    {
        $months = [];
        for ($month = $this->start; $month->lte($this->end); $month = $month->addMonth()) {
            $months[] = self::month($month->year, $month->month);
        }

        return $months;
    }

    /**
     * The periods of a frequency (and basis) that end inside this period: what falls due when this period is reported.
     * A longer frequency than this period's yields nothing.
     *
     * @return list<self>
     */
    public function periodsEndingWithin(string $frequency, string $basis): array
    {
        $out = [];
        foreach ($this->months() as $month) {
            $candidate = self::containing($frequency, $basis, $month->start);
            if ($candidate->end->isSameDay($month->end)) {
                $out[$candidate->key()] = $candidate;
            }
        }

        return array_values($out);
    }

    public static function rank(string $frequency): int
    {
        return self::MONTHS[$frequency] ?? 0;
    }

    /**
     * Selectable periods of a frequency, newest first, ending with the one containing $today.
     *
     * @return array<string, string> key => label
     */
    public static function options(string $frequency, string $basis, CarbonImmutable|string $today, int $count = 24): array
    {
        $period = self::containing($frequency, $basis, $today);
        $options = [];
        for ($i = 0; $i < $count; $i++) {
            $options[$period->key()] = $period->label();
            $period = self::containing($frequency, $basis, $period->start->subDay());
        }

        return $options;
    }

    public function contains(CarbonImmutable|string $date): bool
    {
        $date = CarbonImmutable::parse($date)->startOfDay();

        return $date->gte($this->start) && $date->lte($this->end);
    }

    /** The financial year this period starts in, e.g. 2026 for "2026-27". */
    public function financialYearStart(): int
    {
        $firstMonth = (int) config('peopleos.compliance.financial_year_start_month', 4);

        return $this->start->month >= $firstMonth ? $this->start->year : $this->start->year - 1;
    }

    /** The previous period of the same frequency and basis. */
    public function previous(): self
    {
        return self::containing($this->frequency, $this->basis, $this->start->subDay());
    }

    private function yearStart(): CarbonImmutable
    {
        return self::containing('yearly', $this->basis, $this->start)->start;
    }

    private function index(): int
    {
        return intdiv((int) round($this->yearStart()->diffInMonths($this->start, true)), self::MONTHS[$this->frequency]) + 1;
    }
}
