<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/** Ledger writes and balance recomputation. Every credit/debit goes through post(). */
final class LeaveBalances
{
    public function post(Employee $employee, LeaveType $type, int $period, string $entryType, float $days, ?Model $reference = null, ?string $note = null, ?string $accrualKey = null, ?string $entryDate = null): ?LeaveLedgerEntry
    {
        return DB::transaction(fn () => $this->write($employee, $type, $period, $entryType, $days, $reference, $note, $accrualKey, $entryDate));
    }

    private function write(Employee $employee, LeaveType $type, int $period, string $entryType, float $days, ?Model $reference, ?string $note, ?string $accrualKey, ?string $entryDate): ?LeaveLedgerEntry
    {
        if (! in_array($entryType, LeaveLedgerEntry::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown leave ledger entry type [{$entryType}].");
        }

        $this->lockEmployee($employee);

        if ($accrualKey !== null && LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('accrual_key', $accrualKey)->exists()) {
            return null;
        }

        $entry = LeaveLedgerEntry::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'period_year' => $period,
            'entry_date' => $entryDate ?? now()->toDateString(),
            'type' => $entryType,
            'days' => $days,
            'accrual_key' => $accrualKey,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'created_by' => auth()->id(),
            'operation_id' => Context::get('audit.operation_id'),
        ]);

        $balance = $this->recompute($employee, $type, $period);
        LeaveEvent::dispatch('leave.balance_changed', $employee, $entry, ['leave_type' => $type->code, 'period' => $period, 'entry_type' => $entryType, 'days' => $days, 'available' => $balance->available()]);

        return $entry;
    }

    public function recompute(Employee $employee, LeaveType $type, int $period): LeaveBalance
    {
        $sums = LeaveLedgerEntry::query()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $period)
            ->selectRaw('type, SUM(days) as total')->groupBy('type')->pluck('total', 'type');

        $pending = (float) LeaveRequest::query()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'pending')
            ->get(['id', 'dates'])
            ->sum(fn (LeaveRequest $r) => $this->daysInPeriod($r, $period));

        $figures = self::figures($sums->all());

        $balance = LeaveBalance::query()->firstOrNew(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => $period]);
        $balance->fill([
            'opening' => $figures['opening'], 'accrued' => $figures['accrued'], 'adjusted' => $figures['adjusted'], 'used' => $figures['used'], 'pending' => $pending,
            'encashed' => $figures['encashed'], 'lapsed' => $figures['lapsed'], 'closing' => $figures['closing'], 'computed_at' => now(),
        ])->save();

        return $balance;
    }

    /**
     * A balance's figures from the period's ledger totals by entry type (the one formula recompute() saves and the
     * HROne parity TX-8a year-end preview reads).
     *
     * @param  array<string, mixed>  $sums  ledger entry type => total days
     * @return array{opening: float, accrued: float, adjusted: float, used: float, encashed: float, lapsed: float, closing: float}
     */
    public static function figures(array $sums): array
    {
        $get = fn (string $t) => (float) ($sums[$t] ?? 0);

        $opening = $get('carry_forward') + $get('opening');
        $accrued = $get('accrual') + $get('comp_off_credit');
        $adjusted = $get('adjustment');
        $used = -($get('usage') + $get('reversal'));
        $encashed = -$get('encashment');
        $lapsed = -($get('lapse') + $get('expiry'));
        $closing = $opening + $accrued + $adjusted - $used - $encashed - $lapsed;

        return ['opening' => $opening, 'accrued' => $accrued, 'adjusted' => $adjusted, 'used' => $used, 'encashed' => $encashed, 'lapsed' => $lapsed, 'closing' => $closing];
    }

    /**
     * HROne parity TX-8a: closing balances of many employees for a period, read in one query and worked out with the
     * formula recompute() uses — without writing anything (the year-end preview).
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<int, float>> employee id => leave type id => closing balance
     */
    public function closings(array $employeeIds, int $period): array
    {
        $sums = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $employeeIds))), 500) as $chunk) {
            LeaveLedgerEntry::query()->whereIn('employee_id', $chunk)->where('period_year', $period)
                ->selectRaw('employee_id, leave_type_id, type, SUM(days) as total')->groupBy('employee_id', 'leave_type_id', 'type')
                ->toBase()->get()
                ->each(function (object $row) use (&$sums) {
                    $sums[(int) $row->employee_id][(int) $row->leave_type_id][(string) $row->type] = $row->total;
                });
        }

        $closings = [];
        foreach ($sums as $employeeId => $types) {
            foreach ($types as $typeId => $totals) {
                $closings[$employeeId][$typeId] = self::asStored(self::figures($totals)['closing']);
            }
        }

        return $closings;
    }

    /** A figure as a saved balance gives it back (the decimal:2 cast: two places, half up). */
    public static function asStored(float $days): float
    {
        return (float) (string) BigDecimal::of((string) $days)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Serialise every balance-changing operation of one employee (requests, approvals, cancellations,
     * adjustments, accrual, period close) on the employee row. Must be called inside a transaction.
     */
    public function lockEmployee(Employee $employee): void
    {
        if (DB::transactionLevel() > 0) {
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->value('id');
        }
    }

    public function balance(Employee $employee, LeaveType $type, int $period): LeaveBalance
    {
        return LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $period)->first()
            ?? $this->recompute($employee, $type, $period);
    }

    private function daysInPeriod(LeaveRequest $request, int $period): float
    {
        $year = app(LeaveYear::class);

        return round(array_sum(array_map(fn ($d) => $year->periodFor($d['date']) === $period ? (float) $d['days'] : 0.0, $request->dates ?? [])), 2);
    }
}
