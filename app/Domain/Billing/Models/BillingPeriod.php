<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingPeriodKind;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7 completion (B-2, B-3): one calculated billing period of a subscription, with the quantity it measured and
 * the evidence (peak day, employee ids counted, method) frozen when it was calculated. A later HR correction never
 * changes it: a correction is a credit note. Only the draft it produced is linked to it afterwards (once created
 * in the same transaction, or when a discarded draft is replaced from the frozen quantity, never today's data).
 */
#[Fillable(['subscription_id', 'billing_term_id', 'plan_price_version_id', 'plan_version_id', 'market_id', 'kind', 'period_start', 'period_end',
    'days_in_period', 'days_billed', 'currency', 'unit_amount_minor', 'minimum_quantity', 'committed_quantity', 'measured_peak', 'billed_quantity',
    'amount_minor', 'evidence', 'status', 'exception', 'invoice_id'])]
class BillingPeriod extends Model
{
    use BelongsToTenant;

    public const DRAFTED = 'drafted';

    public const NOTHING_DUE = 'nothing_due';

    public const EXCEPTION = 'exception';

    protected static function booted(): void
    {
        static::updating(function (self $period): void {
            if (array_diff(array_keys($period->getDirty()), ['invoice_id', 'updated_at']) !== []) {
                throw new RuntimeException('A billing period is frozen when it is calculated: only a discarded draft can be replaced.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Billing periods are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['kind' => BillingPeriodKind::class, 'currency' => Currency::class, 'period_start' => 'date', 'period_end' => 'date', 'days_in_period' => 'integer',
            'days_billed' => 'integer', 'unit_amount_minor' => 'integer', 'minimum_quantity' => 'integer', 'committed_quantity' => 'integer',
            'measured_peak' => 'integer', 'billed_quantity' => 'integer', 'amount_minor' => 'integer', 'evidence' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function unitAmount(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency);
    }
}
