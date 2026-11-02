<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * HROne parity GO-18: one employee's pledge to a fundraiser — the amount they chose (never changed afterwards), paid outside
 * payroll or deducted once from their salary in the payroll month chosen (`one_time_payroll`, only with their consent,
 * recorded with the words they agreed to). Status pledged → deducted (payroll finalized the month that carried it) or paid
 * (HR recorded the outside payment), or cancelled (by the employee or HR, while still pledged). Written only by Fundraisers
 * and, for the deduction, by the payroll input that took it. Never deleted.
 */
#[Fillable(['tenant_id', 'fundraiser_campaign_id', 'employee_id', 'amount', 'currency', 'mode', 'payroll_month', 'consent_given_at', 'consent_text', 'status', 'note',
    'payroll_run_id', 'deducted_at', 'paid_on', 'payment_reference', 'recorded_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'created_by'])]
class FundraiserPledge extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const MODES = ['paid_outside' => 'Paid outside payroll', 'one_time_payroll' => 'Deducted once from salary'];

    public const STATUSES = ['pledged' => 'Pledged', 'deducted' => 'Deducted in payroll', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    /** What the donor chose: fixed once the pledge is made. */
    private const CHOSEN = ['fundraiser_campaign_id', 'employee_id', 'amount', 'currency', 'mode', 'payroll_month', 'consent_given_at', 'consent_text'];

    protected $attributes = ['status' => 'pledged'];

    protected static function booted(): void
    {
        static::saving(function (self $pledge): void {
            if (! array_key_exists((string) $pledge->mode, self::MODES) || ! array_key_exists((string) $pledge->status, self::STATUSES)) {
                throw new RuntimeException('Unknown pledge mode or status.');
            }
            if ($pledge->mode === 'one_time_payroll' && ($pledge->payroll_month === null || $pledge->consent_given_at === null)) {
                throw new RuntimeException('A payroll pledge needs its month and the employee\'s consent.');
            }
            if ((float) $pledge->amount <= 0) {
                throw new RuntimeException('A pledge is a positive amount.');
            }
        });
        static::updating(function (self $pledge): void {
            if (array_intersect(array_keys($pledge->getDirty()), self::CHOSEN) !== []) {
                throw new RuntimeException('A pledge keeps the amount, mode, month and consent it was made with.');
            }
            if ($pledge->isDirty('status') && $pledge->getRawOriginal('status') !== 'pledged') {
                throw new RuntimeException('Only a pledge still to be collected changes status.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Pledges are cancelled, never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'consent_given_at' => 'datetime', 'deducted_at' => 'datetime', 'paid_on' => 'date', 'cancelled_at' => 'datetime'];
    }

    /** The donor's note stays out of the audit trail. */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'note'];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return 'Fundraiser pledge #'.$this->getKey();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FundraiserCampaign::class, 'fundraiser_campaign_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->mode] ?? (string) $this->mode;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }
}
