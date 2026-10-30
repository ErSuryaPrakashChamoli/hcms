<?php

namespace App\Domain\Attendance\Listeners;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Attendance\Support\RequestRules;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Identity\Scopes\AccessScope;
use RuntimeException;

/**
 * HROne setup parity (TO-4): comp-off generated from work on a weekly off or holiday, without the employee claiming
 * it, when the time-office policy says so. The generated claim goes through the normal comp-off checks (minutes worked,
 * no overtime approved for the day) and is approved at once when the policy needs no review; a day that already has a
 * comp-off claim is left alone.
 */
final class CompOffGenerator
{
    public function handle(AttendanceEvent $event): void
    {
        if ($event->name !== 'attendance.processed' || ! in_array('holiday_work', $event->subject->exceptions ?? [], true)) {
            return;
        }
        $employee = $event->employee;
        $date = $event->subject->date;
        $policy = app(PolicyResolver::class)->resolve('time_office', $employee, $date);
        if (! RequestRules::isYes($policy?->setting('comp_off_auto'))) {
            return;
        }
        $claimed = TimeOfficeRequest::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->id)->where('type', 'comp_off')
            ->whereNotIn('status', ['rejected', 'cancelled'])->whereDate('from_date', $date->toDateString())->exists();
        if ($claimed) {
            return;
        }

        try {
            $requests = app(TimeOfficeRequests::class);
            $request = $requests->request($employee, 'comp_off', ['from_date' => $date->toDateString()], 'Generated for work on '.$date->format('d M Y'));
            if ($request->status === 'pending' && RequestRules::isYes($policy->setting('comp_off_auto_approve'))) {
                $requests->approve($request, 'Approved automatically: the time-office policy generates comp-off', null, auto: true);
            }
        } catch (RuntimeException) {
            // Not enough time worked, overtime already approved, or outside the window: no comp-off for the day.
        }
    }
}
