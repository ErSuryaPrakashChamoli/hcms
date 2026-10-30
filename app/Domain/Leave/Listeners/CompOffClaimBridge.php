<?php

namespace App\Domain\Leave\Listeners;

use App\Domain\Attendance\Events\AttendanceEvent;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Leave\Services\CompOffCredits;

/**
 * HP-1: an approved comp-off claim credits the comp-off leave type; cancelling an approved claim takes the
 * credit back (refused, rolling the cancellation back, when the days were used). Runs inside the attendance
 * transaction that decided the claim.
 */
final class CompOffClaimBridge
{
    public function __construct(private readonly CompOffCredits $credits) {}

    public function handle(AttendanceEvent $event): void
    {
        $claim = $event->subject;
        if (! $claim instanceof TimeOfficeRequest || $claim->type !== 'comp_off') {
            return;
        }

        if ($event->name === 'attendance.timeoffice_approved' && empty($event->context['cancellation_rejected'])) {
            $entry = $this->credits->credit($claim);
            if ($entry !== null) {
                $claim->withoutAuditing()->update(['effects' => ($claim->effects ?? []) + ['ledger_entry_id' => $entry->id]]);
            }
        }

        if ($event->name === 'attendance.timeoffice_cancelled' && ! empty($event->context['was_approved'])) {
            $this->credits->reverse($claim, (string) ($event->context['reason'] ?? 'Claim cancelled'));
        }
    }
}
