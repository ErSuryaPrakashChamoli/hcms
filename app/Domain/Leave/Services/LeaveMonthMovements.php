<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Support\Carbon;

/**
 * HROne parity MS-12 (My space → Leave balance: "Leave month name", "Balance added", "Balance subtracted"): an
 * employee's leave ledger of a leave year as a month table per leave type — days credited (positive entries: opening,
 * accrual, carry forward, credits, reversals, positive adjustments) and days taken off the balance (negative entries:
 * usage, lapse, expiry, encashment, negative adjustments) in each month of the leave year. Read straight from the
 * append-only ledger (the balances' own source), so the table always adds up to the balance movements. Reads only.
 */
final class LeaveMonthMovements
{
    public function __construct(private readonly LeaveYear $years) {}

    /**
     * @return array{year: int, months: array<string, string>, types: list<array{type_id: int, name: string, months: array<string, array{added: float, subtracted: float}>, added: float, subtracted: float}>}
     */
    public function for(Employee $employee, int $periodYear): array
    {
        $start = $this->years->start($periodYear);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $months[$month->format('Y-m')] = $month->format('M Y');
        }
        $first = array_key_first($months);
        $last = array_key_last($months);

        $entries = LeaveLedgerEntry::query()->where('employee_id', $employee->getKey())->where('period_year', $periodYear)
            ->orderBy('entry_date')->orderBy('id')->get(['leave_type_id', 'entry_date', 'days']);
        $names = LeaveType::query()->whereIn('id', $entries->pluck('leave_type_id')->unique()->all())->pluck('name', 'id');

        $types = [];
        foreach ($entries->groupBy('leave_type_id') as $typeId => $rows) {
            $table = array_fill_keys(array_keys($months), ['added' => 0.0, 'subtracted' => 0.0]);
            foreach ($rows as $row) {
                // An entry dated outside the leave year (an opening posted early, a lapse posted late) counts in its nearest month.
                $key = min(max(Carbon::parse($row->entry_date)->format('Y-m'), $first), $last);
                $days = (float) $row->days;
                $table[$key][$days >= 0 ? 'added' : 'subtracted'] += abs($days);
            }
            $types[] = [
                'type_id' => (int) $typeId, 'name' => (string) ($names[$typeId] ?? 'Leave'),
                'months' => array_map(fn (array $m) => ['added' => round($m['added'], 2), 'subtracted' => round($m['subtracted'], 2)], $table),
                'added' => round(array_sum(array_column($table, 'added')), 2), 'subtracted' => round(array_sum(array_column($table, 'subtracted')), 2),
            ];
        }
        usort($types, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return ['year' => $periodYear, 'months' => $months, 'types' => $types];
    }
}
