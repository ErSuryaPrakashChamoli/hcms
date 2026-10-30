<?php

namespace App\Domain\Leave\Services;

use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Events\LeaveEvent;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Comp-off credits from approved claims (HROne parity HP-1). The credit is a `comp_off_credit` ledger entry keyed
 * to the claim (posted once), dated the day worked and expiring on the claim's `expires_on`. Expiry lapses what is
 * left of each credit first in, first out: newer credits are assumed used last.
 */
final class CompOffCredits
{
    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly LeaveYear $years,
        private readonly AuditRecorder $audit,
    ) {}

    public function credit(TimeOfficeRequest $claim): ?LeaveLedgerEntry
    {
        $claim->loadMissing('employee');
        $type = $this->type();
        $period = $this->years->periodFor($claim->from_date);
        $note = 'Comp-off for work on '.$claim->from_date->format('d M Y').' (claim #'.$claim->id.')';

        $entry = $this->balances->post($claim->employee, $type, $period, 'comp_off_credit', (float) $claim->days, $claim, $note, "comp-off-{$claim->id}", $claim->from_date->toDateString());
        if ($entry === null) {
            return null; // already credited
        }

        $this->audit->record(AuditAction::LeaveBalanceAdjusted, 'leave', $entry, changes: [['field' => "{$type->code} balance ({$period})", 'before' => null, 'after' => sprintf('%+.2f', (float) $claim->days)]], reason: $note, entityLabel: $claim->employee->auditLabel().' · '.$type->code, metadata: ['employee_id' => $claim->employee_id, 'entry_type' => 'comp_off_credit', 'claim_id' => $claim->id]);
        LeaveEvent::dispatch('leave.comp_off_credited', $claim->employee, $entry, ['days' => (float) $claim->days, 'expires_on' => $claim->expires_on?->toDateString()]);

        return $entry;
    }

    /** Take back a cancelled claim's credit. Refused when the days have been used (or have lapsed) meanwhile. */
    public function reverse(TimeOfficeRequest $claim, string $reason): void
    {
        $claim->loadMissing('employee');
        $type = $this->type();
        $period = $this->years->periodFor($claim->from_date);
        $credited = LeaveLedgerEntry::query()->where('employee_id', $claim->employee_id)->where('leave_type_id', $type->id)->where('accrual_key', "comp-off-{$claim->id}")->exists();
        if (! $credited) {
            return;
        }
        $expired = (float) ($claim->effects['expired_days'] ?? 0);
        $days = (float) $claim->days - $expired;
        if ($days <= 0) {
            return;
        }
        if ($this->balances->balance($claim->employee, $type, $period)->available() + 0.001 < $days) {
            throw new RuntimeException('The comp-off from this claim has already been used, so the claim cannot be cancelled.');
        }

        $this->balances->post($claim->employee, $type, $period, 'adjustment', -$days, $claim, 'Comp-off claim #'.$claim->id.' cancelled: '.$reason, "comp-off-{$claim->id}-reversal");
    }

    /** Lapse unused comp-off whose validity ended before $today. @return int credits lapsed */
    public function expire(Carbon|string|null $today = null): int
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $type = LeaveType::query()->where('category', 'comp_off')->where('status', 'active')->first();
        if ($type === null) {
            return 0;
        }
        $lapsed = 0;

        $due = TimeOfficeRequest::query()->with('employee')->where('type', 'comp_off')->whereIn('status', TimeOfficeRequest::EFFECTIVE)
            ->whereNotNull('expires_on')->whereDate('expires_on', '<', $today->toDateString())->orderBy('expires_on')->orderBy('id')->get()
            ->reject(fn (TimeOfficeRequest $c) => isset($c->effects['expired_on']));

        foreach ($due as $claim) {
            $lapsed += (int) $this->expireOne($claim, $type, $today);
        }

        return $lapsed;
    }

    private function expireOne(TimeOfficeRequest $claim, LeaveType $type, Carbon $today): bool
    {
        $employee = $claim->employee;
        $period = $this->years->periodFor($claim->from_date);
        // Credits still valid after this one are used last: whatever the balance holds beyond them belongs to this credit.
        $stillValid = (float) TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'comp_off')->whereIn('status', TimeOfficeRequest::EFFECTIVE)
            ->whereKeyNot($claim->id)->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today->toDateString()))
            ->get(['id', 'days', 'from_date'])->filter(fn (TimeOfficeRequest $c) => $this->years->periodFor($c->from_date) === $period)->sum('days');
        $available = $this->balances->balance($employee, $type, $period)->available();
        $lapse = round(min((float) $claim->days, max(0.0, $available - $stillValid)), 2);

        if ($lapse > 0) {
            $this->balances->post($employee, $type, $period, 'expiry', -$lapse, $claim, 'Comp-off from '.$claim->from_date->format('d M Y').' expired on '.$claim->expires_on->format('d M Y'), "comp-off-{$claim->id}-expiry");
            $this->audit->record(AuditAction::LeaveExpired, 'leave', $claim, reason: 'Comp-off validity ended', metadata: ['days' => $lapse, 'employee_id' => $employee->id]);
            LeaveEvent::dispatch('leave.comp_off_expired', $employee, $claim, ['days' => $lapse, 'worked_on' => $claim->from_date->toDateString()]);
        }
        $claim->withoutAuditing()->update(['effects' => ($claim->effects ?? []) + ['expired_on' => $today->toDateString(), 'expired_days' => $lapse]]);

        return $lapse > 0;
    }

    private function type(): LeaveType
    {
        return LeaveType::query()->where('category', 'comp_off')->where('status', 'active')->first() ?? throw new RuntimeException('No compensatory-off leave type is configured.');
    }

    /** For callers that only hold an employee: is any comp-off credit of theirs live? */
    public function liveCredits(Employee $employee): float
    {
        return (float) TimeOfficeRequest::query()->where('employee_id', $employee->id)->where('type', 'comp_off')->whereIn('status', TimeOfficeRequest::EFFECTIVE)->sum('days');
    }
}
