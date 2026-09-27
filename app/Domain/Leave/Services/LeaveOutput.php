<?php

namespace App\Domain\Leave\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Payroll-ready leave contract (Phase 3 §32): quantities per leave type for a period — approved
 * paid days, approved unpaid days, and days of approved leave later cancelled (reversed). No money.
 */
final class LeaveOutput
{
    /** @return array<string, array{leave_type: string, is_paid: bool, approved_days: float, paid_days: float, unpaid_days: float, reversed_days: float}> */
    public function forEmployee(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $out = [];

        $requests = LeaveRequest::query()->with('leaveType')
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [...LeaveRequest::TAKEN, 'cancelled'])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)
            ->get();

        foreach ($requests as $request) {
            $code = $request->leaveType->code;
            $paid = (bool) $request->leaveType->is_paid && $request->leaveType->category !== 'unpaid';
            $out[$code] ??= ['leave_type' => $code, 'is_paid' => $paid, 'approved_days' => 0.0, 'paid_days' => 0.0, 'unpaid_days' => 0.0, 'reversed_days' => 0.0];
            // A cancelled request counts as reversed only when it had been approved (approval sets reviewed_at; withdrawing a pending request does not).
            $wasApproved = $request->status !== 'cancelled' || $request->reviewed_at !== null;

            foreach ($request->dates ?? [] as $d) {
                if ($d['date'] < $from || $d['date'] > $to) {
                    continue;
                }
                $days = (float) $d['days'];

                if ($request->status === 'cancelled') {
                    if ($wasApproved) {
                        $out[$code]['reversed_days'] += $days;
                    }

                    continue;
                }

                $out[$code]['approved_days'] += $days;
                $out[$code][$paid ? 'paid_days' : 'unpaid_days'] += $days;
            }
        }

        return array_map(fn ($row) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row), $out);
    }

    /**
     * Unpaid leave quantity per date for approved (or cancel-requested) leave in the window —
     * what a consumer needs to place loss-of-pay days on dates.
     *
     * @return array<string, float> date => days (1 or 0.5)
     */
    public function unpaidDays(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $days = [];

        LeaveRequest::query()->with('leaveType')
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', LeaveRequest::TAKEN)
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)
            ->get()
            ->filter(fn (LeaveRequest $r) => ! $r->leaveType->is_paid || $r->leaveType->category === 'unpaid')
            ->each(function (LeaveRequest $r) use (&$days, $from, $to) {
                foreach ($r->dates ?? [] as $d) {
                    if ($d['date'] >= $from && $d['date'] <= $to) {
                        $days[$d['date']] = round(($days[$d['date']] ?? 0) + (float) $d['days'], 2);
                    }
                }
            });

        ksort($days);

        return $days;
    }
}
