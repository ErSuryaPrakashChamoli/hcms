<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
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

        $get = fn (string $t) => (float) ($sums[$t] ?? 0);

        $pending = (float) LeaveRequest::query()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'pending')
            ->get(['id', 'dates'])
            ->sum(fn (LeaveRequest $r) => $this->daysInPeriod($r, $period));

        $opening = $get('carry_forward') + $get('opening');
        $accrued = $get('accrual') + $get('comp_off_credit');
        $adjusted = $get('adjustment');
        $used = -($get('usage') + $get('reversal'));
        $encashed = -$get('encashment');
        $lapsed = -($get('lapse') + $get('expiry'));
        $closing = $opening + $accrued + $adjusted - $used - $encashed - $lapsed;

        $balance = LeaveBalance::query()->firstOrNew(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => $period]);
        $balance->fill([
            'opening' => $opening, 'accrued' => $accrued, 'adjusted' => $adjusted, 'used' => $used, 'pending' => $pending,
            'encashed' => $encashed, 'lapsed' => $lapsed, 'closing' => $closing, 'computed_at' => now(),
        ])->save();

        return $balance;
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
