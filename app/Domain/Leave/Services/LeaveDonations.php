<?php

namespace App\Domain\Leave\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveDonation;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leave donation (HROne parity HP-1): an employee gives days of an allowed leave type to a colleague. Requests
 * hold nothing; approval re-checks the donor's balance under the ledger lock and posts two adjustments.
 */
final class LeaveDonations
{
    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly LeaveYear $years,
        private readonly SettingsRepository $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function request(Employee $donor, Employee $recipient, LeaveType $type, float $days, string $reason, ?User $requester = null): LeaveDonation
    {
        if ($donor->is($recipient)) {
            throw new RuntimeException('Choose a colleague to give leave to.');
        }
        if (! in_array($type->code, (array) $this->settings->get('leave.donation.allowed_types', ['EL']), true)) {
            throw new RuntimeException("{$type->name} cannot be donated.");
        }
        if ($days <= 0 || fmod($days * 2, 1.0) !== 0.0) {
            throw new RuntimeException('Donate whole or half days.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason for the donation.');
        }
        $period = $this->years->periodFor(now());
        $this->assertWithinLimit($donor, $type, $period, $days);
        if ($this->balances->balance($donor, $type, $period)->available() < $days) {
            throw new RuntimeException("You do not have {$days} day(s) of {$type->name} to give.");
        }

        $donation = new LeaveDonation(['employee_id' => $donor->id, 'recipient_employee_id' => $recipient->id, 'leave_type_id' => $type->id, 'period_year' => $period, 'days' => $days, 'reason' => $reason, 'status' => 'pending', 'requested_by' => ($requester ?? auth()->user())?->id]);
        $donation->withAuditReason($reason)->save();
        LeaveEvent::dispatch('leave.donation_requested', $donor, $donation, ['leave_type' => $type->code, 'days' => $days, 'recipient_employee_id' => $recipient->id]);

        return $donation;
    }

    public function approve(LeaveDonation $donation, ?string $note = null, ?User $actor = null): LeaveDonation
    {
        $actor ??= auth()->user();

        return DB::transaction(function () use ($donation, $note, $actor) {
            $locked = $this->decidable($donation, $actor);
            $donor = $locked->employee;
            $recipient = Employee::query()->withoutGlobalScope(AccessScope::class)->findOrFail($locked->recipient_employee_id);
            $type = $locked->leaveType;
            $days = (float) $locked->days;

            $this->balances->lockEmployee($donor);
            if ($this->balances->balance($donor, $type, $locked->period_year)->available() < $days) {
                throw new RuntimeException("{$donor->display_name} no longer has {$days} day(s) of {$type->name} to give.");
            }

            $this->balances->post($donor, $type, $locked->period_year, 'adjustment', -$days, $locked, "Donated to {$recipient->display_name}", "donation-{$locked->id}-out");
            $this->balances->post($recipient, $type, $locked->period_year, 'adjustment', $days, $locked, "Donated by {$donor->display_name}", "donation-{$locked->id}-in");
            $locked->withAuditReason($note)->update(['status' => 'approved', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Approved, 'leave', $locked, reason: $note, actor: $actor, metadata: ['days' => $days, 'recipient_employee_id' => $recipient->id]);
            LeaveEvent::dispatch('leave.donation_approved', $donor, $locked, ['leave_type' => $type->code, 'days' => $days, 'recipient_employee_id' => $recipient->id]);

            return $locked;
        });
    }

    public function reject(LeaveDonation $donation, string $note, ?User $actor = null): LeaveDonation
    {
        if (trim($note) === '') {
            throw new RuntimeException('Give the reason for rejecting.');
        }
        $actor ??= auth()->user();

        return DB::transaction(function () use ($donation, $note, $actor) {
            $locked = $this->decidable($donation, $actor);
            $locked->withAuditReason($note)->update(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record(AuditAction::Rejected, 'leave', $locked, reason: $note, actor: $actor);
            LeaveEvent::dispatch('leave.donation_rejected', $locked->employee, $locked, ['note' => $note]);

            return $locked;
        });
    }

    public function cancel(LeaveDonation $donation, string $reason): LeaveDonation
    {
        if ($donation->status !== 'pending') {
            throw new RuntimeException('Only pending donations can be withdrawn.');
        }
        $donation->withAuditReason($reason)->update(['status' => 'cancelled']);

        return $donation;
    }

    private function assertWithinLimit(Employee $donor, LeaveType $type, int $period, float $days): void
    {
        $max = (float) $this->settings->get('leave.donation.max_days_per_year', 5);
        if ($max <= 0) {
            return;
        }
        $given = (float) LeaveDonation::query()->where('employee_id', $donor->id)->where('leave_type_id', $type->id)->where('period_year', $period)->whereIn('status', ['pending', 'approved'])->sum('days');
        if ($given + $days > $max) {
            throw new RuntimeException("At most {$max} day(s) of {$type->name} can be donated in a year.");
        }
    }

    private function decidable(LeaveDonation $donation, ?User $actor): LeaveDonation
    {
        if ($actor === null) {
            throw new RuntimeException('A leave donation is decided by a person.');
        }
        $locked = LeaveDonation::query()->whereKey($donation->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw new RuntimeException('This donation has already been decided.');
        }
        $employeeUsers = Employee::query()->withoutGlobalScope(AccessScope::class)->whereIn('id', [$locked->employee_id, $locked->recipient_employee_id])->pluck('user_id')->filter()->all();
        if (in_array($actor->id, $employeeUsers, true)) {
            throw new RuntimeException('You cannot decide a donation you give or receive.');
        }
        $locked->load(['employee', 'leaveType']);

        return $locked;
    }
}
