<?php

namespace App\Domain\Expense\Support;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Platform\Services\SettingsRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * HROne setup parity EX-C: the organisation-wide expense settings receipts and reports read (Setup → Expense), with
 * their meaning in one place:
 *
 * - expiry (expense.expiry.*): an unreported receipt expires a period after its expense date, the end of its month or
 *   the end of its year (counted from expense.year_start_month); 0 = never;
 * - submission windows (expense.report_window.*): reports are submitted only between the cycle's From and To days of
 *   the month (one or two cycles; To is cut to the month's length); HR filing on behalf is not held to them;
 * - workflow groups (expense.workflow.*): one approval for the whole report, or one per category or expense group
 *   (every one, or only those listed); exceptional expenses always form a group of their own;
 * - EX-F travel workflow groups (expense.travel.workflow.*): the same split for a trip's legs.
 */
final class ExpenseSettings
{
    public const EXCEPTIONAL = 'exceptional';

    public const ALL = 'all';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings->get($key, $default);
    }

    /** The day an unreported receipt of this expense date expires, or null when receipts never expire. */
    public function expiresOn(CarbonInterface|string $expenseDate): ?CarbonImmutable
    {
        $period = (int) $this->settings->get('expense.expiry.period', 0);
        if ($period <= 0) {
            return null;
        }
        $date = CarbonImmutable::parse($expenseDate)->startOfDay();
        $base = match ($this->settings->get('expense.expiry.based_on', 'expense_date')) {
            'month_end' => $date->endOfMonth()->startOfDay(),
            'financial_year_end' => ExpensePeriods::window('year', $date, (int) $this->settings->get('expense.year_start_month', 4))[1],
            default => $date,
        };

        return $this->settings->get('expense.expiry.unit', 'days') === 'months' ? $base->addMonthsNoOverflow($period) : $base->addDays($period);
    }

    /**
     * The submission cycle open on a day: 1 or 2, null when windows are on and none is open, 0 when windows are off.
     */
    public function windowCycle(CarbonInterface $day): ?int
    {
        if (! (bool) $this->settings->get('expense.report_window.enabled', false)) {
            return 0;
        }
        foreach ($this->windows($day) as $cycle => [$from, $to]) {
            if ($day->day >= $from && $day->day <= $to) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * The open windows of the day's month, as day numbers.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function windows(CarbonInterface $day): array
    {
        $days = $day->daysInMonth;
        $clamp = fn (mixed $value, int $default) => min($days, max(1, is_numeric($value) ? (int) $value : $default));
        $windows = [1 => [$clamp($this->settings->get('expense.report_window.first_from', 1), 1), $clamp($this->settings->get('expense.report_window.first_to', 31), 31)]];
        if ((bool) $this->settings->get('expense.report_window.second_enabled', false)) {
            $windows[2] = [$clamp($this->settings->get('expense.report_window.second_from', 16), 16), $clamp($this->settings->get('expense.report_window.second_to', 31), 31)];
        }

        return $windows;
    }

    /** "1–10 and 16–25 of each month", for refusals. */
    public function describeWindows(CarbonInterface $day): string
    {
        return collect($this->windows($day))->map(fn (array $w) => $w[0].'–'.$w[1])->implode(' and ').' of the month';
    }

    /**
     * The workflow group a receipt of this category goes to: `exceptional`, `all`, `category:<code>` or `group:<code>`.
     */
    public function workflowGroup(ExpenseCategory $category, bool $exceptional): string
    {
        if ($exceptional) {
            return self::EXCEPTIONAL;
        }
        $split = (string) $this->settings->get('expense.workflow.split_by', 'none');
        $all = $this->settings->get('expense.workflow.split_scope', 'listed') === 'all';

        return match ($split) {
            'category' => $all || in_array((int) $category->id, $this->ids('expense.workflow.exclusive_categories'), true) ? 'category:'.$category->code : self::ALL,
            'group' => $category->expense_group_id !== null && ($all || in_array((int) $category->expense_group_id, $this->ids('expense.workflow.exclusive_groups'), true))
                ? 'group:'.$category->loadMissing('group')->group?->code : self::ALL,
            default => self::ALL,
        };
    }

    /**
     * EX-F: the workflow group a trip leg of this category goes to (`all`, `category:<code>` or `group:<code>`), by the
     * travel settings (expense.travel.workflow.*), as workflowGroup() does for reports.
     */
    public function travelWorkflowGroup(ExpenseCategory $category): string
    {
        $split = (string) $this->settings->get('expense.travel.workflow.split_by', 'none');
        $all = $this->settings->get('expense.travel.workflow.split_scope', 'listed') === 'all';

        return match ($split) {
            'category' => $all || in_array((int) $category->id, $this->ids('expense.travel.workflow.exclusive_categories'), true) ? 'category:'.$category->code : self::ALL,
            'group' => $category->expense_group_id !== null && ($all || in_array((int) $category->expense_group_id, $this->ids('expense.travel.workflow.exclusive_groups'), true))
                ? 'group:'.$category->loadMissing('group')->group?->code : self::ALL,
            default => self::ALL,
        };
    }

    /** @return list<int> */
    private function ids(string $key): array
    {
        return array_values(array_map('intval', array_filter((array) ($this->settings->get($key) ?? []), fn ($v) => is_numeric($v))));
    }
}
