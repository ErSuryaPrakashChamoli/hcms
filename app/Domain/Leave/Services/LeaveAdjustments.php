<?php

namespace App\Domain\Leave\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Manual adjustments, comp-off credits and encashment (§25). */
final class LeaveAdjustments
{
    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly LeaveEntitlements $entitlements,
        private readonly LeaveYear $years,
        private readonly AuditRecorder $audit,
    ) {}

    public function adjust(Employee $employee, LeaveType $type, float $days, string $note, ?int $period = null, string $entryType = 'adjustment'): LeaveLedgerEntry
    {
        if ($days === 0.0) {
            throw new RuntimeException('Adjustment must not be zero.');
        }

        $period ??= $this->years->periodFor(now());
        $entry = $this->balances->post($employee, $type, $period, $entryType, $days, null, $note);

        $this->audit->record(AuditAction::Update, 'leave', $employee, changes: [['field' => "{$type->code} balance ({$period})", 'before' => null, 'after' => sprintf('%+.2f', $days)]], reason: $note, entityLabel: $employee->auditLabel());
        LeaveEvent::dispatch('leave.balance_adjusted', $employee, $entry, ['leave_type' => $type->code, 'days' => $days, 'note' => $note]);

        return $entry;
    }

    public function creditCompOff(Employee $employee, float $days, string $note): LeaveLedgerEntry
    {
        $type = LeaveType::query()->where('category', 'comp_off')->where('status', 'active')->first() ?? throw new RuntimeException('No compensatory-off leave type is configured.');

        return $this->adjust($employee, $type, $days, $note, null, 'comp_off_credit');
    }

    public function requestEncashment(Employee $employee, LeaveType $type, float $days, ?string $reason = null, ?int $period = null): LeaveEncashment
    {
        $period ??= $this->years->periodFor(now());
        $rule = $this->entitlements->forType($employee, $type) ?? throw new RuntimeException("No leave policy grants {$type->name}.");

        if (! $type->is_encashable || ! $rule['encashment_allowed']) {
            throw new RuntimeException("{$type->name} cannot be encashed.");
        }

        $alreadyRequested = (float) LeaveEncashment::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('period_year', $period)->whereIn('status', ['pending', 'approved', 'paid'])->sum('days');

        if ((int) $rule['max_encash_days'] > 0 && $alreadyRequested + $days > (int) $rule['max_encash_days']) {
            throw new RuntimeException("At most {$rule['max_encash_days']} day(s) may be encashed per year.");
        }

        if ($days <= 0 || $days > $this->balances->balance($employee, $type, $period)->available()) {
            throw new RuntimeException('Not enough balance to encash.');
        }

        $encashment = new LeaveEncashment(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_year' => $period, 'days' => $days, 'status' => 'pending', 'reason' => $reason, 'requested_by' => auth()->id()]);
        $encashment->withAuditReason($reason)->save();
        LeaveEvent::dispatch('leave.encashment_requested', $employee, $encashment, ['leave_type' => $type->code, 'days' => $days]);

        return $encashment;
    }

    public function approveEncashment(LeaveEncashment $encashment, ?string $note = null): LeaveEncashment
    {
        if ($encashment->status !== 'pending') {
            throw new RuntimeException('This encashment has already been decided.');
        }

        $encashment->loadMissing(['employee', 'leaveType']);

        return DB::transaction(function () use ($encashment, $note) {
            $encashment->withAuditReason($note)->update(['status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $note]);
            $this->balances->post($encashment->employee, $encashment->leaveType, $encashment->period_year, 'encashment', -(float) $encashment->days, $encashment, $note ?? 'Encashment approved');
            $this->audit->record(AuditAction::Approved, 'leave', $encashment, reason: $note);
            LeaveEvent::dispatch('leave.encashment_approved', $encashment->employee, $encashment, ['leave_type' => $encashment->leaveType->code, 'days' => (float) $encashment->days]);

            return $encashment;
        });
    }

    public function rejectEncashment(LeaveEncashment $encashment, string $note): LeaveEncashment
    {
        if ($encashment->status !== 'pending') {
            throw new RuntimeException('This encashment has already been decided.');
        }

        $encashment->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => $note]);
        $this->audit->record(AuditAction::Rejected, 'leave', $encashment, reason: $note);

        return $encashment;
    }
}
