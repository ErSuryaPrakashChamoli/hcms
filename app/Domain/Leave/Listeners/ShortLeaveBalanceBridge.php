<?php

namespace App\Domain\Leave\Listeners;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveBalances;
use App\Domain\Leave\Services\LeaveYear;
use RuntimeException;

/**
 * HROne parity CF-02: a short leave beyond the monthly limit, allowed by a policy that takes leave instead, debits
 * the configured leave type when approved and credits it back when the short leave is cancelled. Both entries are
 * keyed to the request, so each happens once.
 */
final class ShortLeaveBalanceBridge
{
    public function __construct(private readonly LeaveBalances $balances, private readonly LeaveYear $years) {}

    public function handle(AttendanceEvent $event): void
    {
        $request = $event->subject;
        if (! $request instanceof TimeOfficeRequest || $request->type !== 'short_leave' || empty($request->effects['deduct'])) {
            return;
        }
        $take = $request->effects['deduct'];
        $type = LeaveType::query()->where('code', $take['leave_code'])->first() ?? throw new RuntimeException("Leave type {$take['leave_code']} for short leave beyond the limit does not exist.");
        $period = $this->years->periodFor($request->from_date);
        $days = (float) $take['days'];

        if ($event->name === 'attendance.timeoffice_approved' && empty($event->context['cancellation_rejected'])) {
            if ($this->balances->balance($request->employee, $type, $period)->available() < $days) {
                throw new RuntimeException("Not enough {$type->name} to cover a short leave beyond the monthly limit.");
            }
            $this->balances->post($request->employee, $type, $period, 'adjustment', -$days, $request, 'Short leave beyond the monthly limit on '.$request->from_date->format('d M Y'), "sl-{$request->id}", $request->from_date->toDateString());
        }

        if ($event->name === 'attendance.timeoffice_cancelled' && ! empty($event->context['was_approved'])) {
            $this->balances->post($request->employee, $type, $period, 'adjustment', $days, $request, 'Short leave cancelled', "sl-{$request->id}-back");
        }
    }
}
