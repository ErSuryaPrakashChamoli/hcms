<?php

namespace App\Domain\Expense\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HROne setup parity EX-D: an expense advance — money requested ahead of work expenses under the employee's expense
 * advance policy, approved, dispensed (the outside payment's reference is recorded), then settled against approved
 * expense reports oldest first, refunded or written off. With EX-E an advance approved for payroll payout is paid by the
 * next payroll, and what is still outstanding after the recovery date (`recovery_due_on`: the settle-by date plus the
 * policy's days, when the policy recovers through payroll) is recovered by payroll or, at exit, in the final settlement
 * (`recovered`). The ledger (entries) is the source of truth; `outstanding` is its cached sum. Cancelling an approved
 * advance may need approval (cancel_requested). EX-F: an advance asked for inside a travel request (`travel_request_id`)
 * is decided and cancelled with the trip and settled within the policy's days after the trip ends.
 *
 * HROne parity XO-5: `filed_on_behalf` when HR (or a manager) requested it for the employee (`requested_by` names who);
 * with partial dispense on (owner decision D-XO8 / EXP-14) it may be paid out in several payouts up to the approved amount
 * (`dispensed_amount` = paid so far, null = the whole approved amount as before); what was never paid out lapses
 * (`remainder_lapsed_at`) once the advance is settled, recovered or written off, its rest is cancelled or its trip is.
 */
#[Fillable([
    'tenant_id', 'number', 'employee_id', 'policy_version_id', 'travel_request_id', 'purpose', 'needed_on', 'currency', 'amount', 'exchange_rate', 'amount_base',
    'approved_amount', 'attachments', 'status', 'disbursement_channel', 'disbursed_on', 'disbursement_reference', 'disbursed_by', 'disbursed_payroll_run_id', 'settle_by',
    'recovery_due_on', 'outstanding', 'requested_by', 'decided_at', 'decided_by', 'decision_note', 'cancel_reason', 'cancel_requested_at', 'cancel_refused_at', 'closed_at',
    // HROne parity XO-5: filed on behalf, partial dispense (D-XO8), the dispense note.
    'filed_on_behalf', 'dispensed_amount', 'disbursement_note', 'remainder_lapsed_at',
])]
class ExpenseAdvance extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const STATUSES = [
        'requested' => 'Requested', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancel_requested' => 'Cancellation requested',
        'cancelled' => 'Cancelled', 'disbursed' => 'Dispensed', 'settled' => 'Settled', 'recovered' => 'Recovered', 'written_off' => 'Written off',
    ];

    /** Advances that still count towards the policy's open-advance and outstanding limits. */
    public const OPEN = ['requested', 'approved', 'cancel_requested', 'disbursed'];

    protected $attributes = ['status' => 'requested', 'disbursement_channel' => 'bank', 'outstanding' => 0, 'exchange_rate' => 1];

    protected function casts(): array
    {
        return [
            'needed_on' => 'date', 'disbursed_on' => 'date', 'settle_by' => 'date', 'recovery_due_on' => 'date', 'decided_at' => 'datetime', 'cancel_requested_at' => 'datetime', 'cancel_refused_at' => 'datetime', 'closed_at' => 'datetime',
            'amount' => 'decimal:2', 'exchange_rate' => 'decimal:8', 'amount_base' => 'decimal:2', 'approved_amount' => 'decimal:2', 'outstanding' => 'decimal:2',
            'filed_on_behalf' => 'boolean', 'dispensed_amount' => 'decimal:2', 'remainder_lapsed_at' => 'datetime',
            'attachments' => 'array',
        ];
    }

    public function auditLabel(): string
    {
        return 'Expense advance '.($this->getAttributes()['number'] ?? '');
    }

    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'attachments'];
    }

    /** The amount the advance is for in the base currency: the approved amount once decided. */
    public function baseAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->amount_base);
    }

    /** HROne parity XO-5: what has been paid out (null until dispensed): the approved amount unless paid in several payouts. */
    public function dispensedAmount(): ?float
    {
        if ($this->disbursed_on === null) {
            return null;
        }

        return round((float) ($this->dispensed_amount ?? $this->approved_amount), 2);
    }

    /** HROne parity XO-5 (D-XO8): what may still be paid out of a partly dispensed advance (0 for every other advance). */
    public function remainingToDispense(): float
    {
        if ($this->status !== 'disbursed' || $this->remainder_lapsed_at !== null || $this->dispensed_amount === null) {
            return 0.0;
        }

        return max(0.0, round((float) $this->approved_amount - (float) $this->dispensed_amount, 2));
    }

    /** HROne parity XO-5 (D-XO8): the part of a partly dispensed advance never paid out, once it lapsed. */
    public function lapsedAmount(): float
    {
        if ($this->remainder_lapsed_at === null || $this->dispensed_amount === null) {
            return 0.0;
        }

        return max(0.0, round((float) $this->approved_amount - (float) $this->dispensed_amount, 2));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** EX-F: the trip the advance was asked for with (decided with it). */
    public function travelRequest(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class);
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ExpenseAdvanceEntry::class)->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
