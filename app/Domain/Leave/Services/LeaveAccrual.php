<?php

namespace App\Domain\Leave\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Support\Carbon;

/**
 * Accrual, carry-forward and lapse (§25). Idempotent through accrual keys, so the daily command
 * can run any number of times and back-fill missed months.
 */
final class LeaveAccrual
{
    public function __construct(
        private readonly LeaveEntitlements $entitlements,
        private readonly LeaveBalances $balances,
        private readonly LeaveYear $years,
        private readonly AuditRecorder $audit,
    ) {}

    /** Credit everything due up to $asOf for this employee. Returns the number of ledger entries written. */
    public function accrue(Employee $employee, Carbon|string|null $asOf = null): int
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();
        $period = $this->years->periodFor($asOf);
        $periodStart = $this->years->start($period);
        $joined = $employee->joining_date?->copy()->startOfDay();
        $written = 0;

        if ($joined === null || $joined->gt($asOf)) {
            return 0;
        }

        foreach ($this->entitlements->for($employee, $asOf) as $code => $rule) {
            $type = LeaveType::query()->where('code', $code)->where('status', 'active')->first();

            if ($type === null || (float) $rule['days'] <= 0) {
                continue;
            }

            $written += match ($rule['accrual_frequency']) {
                'monthly' => $this->periodic($employee, $type, $rule, $period, $periodStart, $joined, $asOf, 12),
                'quarterly' => $this->periodic($employee, $type, $rule, $period, $periodStart, $joined, $asOf, 4),
                default => $this->annual($employee, $type, $rule, $period, $periodStart, $joined),
            };
        }

        return $written;
    }

    /** Close $period for the employee: carry forward up to the limit, lapse the rest. */
    public function closeYear(Employee $employee, int $period): int
    {
        $written = 0;
        $nextStart = $this->years->start($period + 1);

        foreach ($this->entitlements->for($employee, $this->years->end($period)) as $code => $rule) {
            $type = LeaveType::query()->where('code', $code)->first();

            if ($type === null) {
                continue;
            }

            $closing = (float) $this->balances->recompute($employee, $type, $period)->closing;

            if ($closing <= 0) {
                continue;
            }

            $carry = min($closing, (float) $rule['carry_forward_limit']);
            $lapse = $closing - $carry;

            if ($carry > 0 && ($entry = $this->balances->post($employee, $type, $period + 1, 'carry_forward', $carry, null, "Carried forward from {$period}", "cf-{$period}", $nextStart->toDateString()))) {
                $written++;
                $this->audit->record(AuditAction::LeaveCarriedForward, 'leave', $entry, reason: "Year-end {$period}", metadata: ['employee_id' => $employee->id, 'leave_type' => $type->code, 'days' => $carry]);
                LeaveEvent::dispatch('leave.carried_forward', $employee, $entry, ['leave_type' => $type->code, 'days' => $carry, 'period' => $period + 1]);
            }

            if ($lapse > 0 && ($entry = $this->balances->post($employee, $type, $period, 'lapse', -$lapse, null, "Lapsed at end of {$period}", "lapse-{$period}", $this->years->end($period)->toDateString()))) {
                $written++;
                $this->audit->record(AuditAction::LeaveExpired, 'leave', $entry, reason: "Year-end {$period}", metadata: ['employee_id' => $employee->id, 'leave_type' => $type->code, 'days' => $lapse, 'kind' => 'lapse']);
            }
        }

        return $written;
    }

    /**
     * Carried-forward days not used within `carry_forward_expiry_months` of the period start expire
     * (ledger entry "expiry", idempotent per period). Usage is consumed from carried days first.
     */
    public function expireCarryForward(Employee $employee, Carbon|string|null $asOf = null): int
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();
        $period = $this->years->periodFor($asOf);
        $written = 0;

        foreach ($this->entitlements->for($employee, $asOf) as $code => $rule) {
            $months = (int) ($rule['carry_forward_expiry_months'] ?? 0);
            if ($months <= 0) {
                continue;
            }
            $expiresOn = $this->years->start($period)->addMonths($months);
            if ($asOf->lt($expiresOn)) {
                continue;
            }
            $type = LeaveType::query()->where('code', $code)->first();
            if ($type === null) {
                continue;
            }

            $entries = LeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $period);
            $carried = (float) (clone $entries)->where('type', 'carry_forward')->sum('days');
            $usedBefore = -(float) (clone $entries)->whereIn('type', ['usage', 'reversal', 'encashment'])->whereDate('entry_date', '<', $expiresOn->toDateString())->sum('days');
            $expire = round(max(0, $carried - max(0, $usedBefore)), 2);

            if ($expire > 0 && ($entry = $this->balances->post($employee, $type, $period, 'expiry', -$expire, null, "Carried-forward days expired after {$months} month(s)", "cfexp-{$period}", $expiresOn->toDateString()))) {
                $written++;
                $this->audit->record(AuditAction::LeaveExpired, 'leave', $entry, reason: 'Carry-forward expiry', metadata: ['employee_id' => $employee->id, 'leave_type' => $type->code, 'days' => $expire, 'kind' => 'expiry']);
                LeaveEvent::dispatch('leave.expired', $employee, $entry, ['leave_type' => $type->code, 'days' => $expire, 'period' => $period]);
            }
        }

        return $written;
    }

    private function annual(Employee $employee, LeaveType $type, array $rule, int $period, Carbon $periodStart, Carbon $joined): int
    {
        $days = (float) $rule['days'];
        $start = $joined->gt($periodStart) ? $joined : $periodStart;

        $proration = $rule['prorate_on_join'] ? ($rule['proration'] ?? 'monthly') : 'none';

        if ($proration !== 'none' && $joined->gt($periodStart)) {
            if ($proration === 'daily') {
                $periodEnd = $this->years->end($period)->startOfDay();
                $total = (int) $periodStart->diffInDays($periodEnd) + 1;
                $remaining = (int) $joined->diffInDays($periodEnd) + 1;
                $days = round($days * max(0, $remaining) / $total, 2);
            } else {
                // Remaining whole months including the joining month.
                $remainingMonths = 12 - (int) $periodStart->diffInMonths($joined->copy()->startOfMonth());
                $days = round($days * max(0, $remainingMonths) / 12, 2);
            }
        }

        return $this->credit($employee, $type, $period, $days, "annual-{$period}", $start, 'Annual credit');
    }

    private function periodic(Employee $employee, LeaveType $type, array $rule, int $period, Carbon $periodStart, Carbon $joined, Carbon $asOf, int $slices): int
    {
        $perSlice = round((float) $rule['days'] / $slices, 2);
        $months = intdiv(12, $slices);
        $written = 0;

        for ($i = 0; $i < $slices; $i++) {
            $sliceStart = $periodStart->copy()->addMonths($i * $months);

            if ($sliceStart->gt($asOf)) {
                break;
            }

            if ($joined->gt($sliceStart->copy()->addMonths($months)->subDay())) {
                continue; // joined after this slice ended
            }

            $written += $this->credit($employee, $type, $period, $perSlice, "{$slices}-{$period}-{$i}", $sliceStart->max($joined), 'Periodic accrual');
        }

        return $written;
    }

    private function credit(Employee $employee, LeaveType $type, int $period, float $days, string $key, Carbon $date, string $note): int
    {
        if ($days <= 0) {
            return 0;
        }

        $entry = $this->balances->post($employee, $type, $period, 'accrual', $days, null, $note, $key, $date->toDateString());

        if ($entry !== null) {
            LeaveEvent::dispatch('leave.accrued', $employee, $entry, ['leave_type' => $type->code, 'days' => $days, 'period' => $period]);
        }

        return $entry ? 1 : 0;
    }
}
