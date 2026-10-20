<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingPeriodKind;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7 completion (B-2, B-3): one calculated billing period of a subscription, with the quantity it measured and
 * the evidence (peak day, employee ids counted, method) frozen when it was calculated. A later HR correction never
 * changes it: a correction is a credit note. Only the draft it produced is linked to it afterwards (once created
 * in the same transaction, or when a discarded draft is replaced from the frozen quantity, never today's data).
 * SaaS.7 configuration: it also freezes where its price came from (standard, negotiated, or none for a
 * NO_PRICE_CONFIGURED exception), the agreed discount and the net unit billed.
 */
#[Fillable(['subscription_id', 'billing_term_id', 'plan_price_version_id', 'negotiated_price_version_id', 'price_source', 'plan_version_id', 'market_id', 'kind',
    'period_start', 'period_end', 'days_in_period', 'days_billed', 'currency', 'unit_amount_minor', 'discount_percent', 'net_unit_amount_minor', 'minimum_quantity', 'committed_quantity', 'measured_peak', 'billed_quantity',
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
            'measured_peak' => 'integer', 'billed_quantity' => 'integer', 'amount_minor' => 'integer', 'net_unit_amount_minor' => 'integer', 'evidence' => 'array'];
    }

    /** The agreed discount as an exact decimal without trailing zeros ("5", "12.5"), whatever the column's scale. */
    protected function discountPercent(): Attribute
    {
        return Attribute::get(fn ($value) => $value === null ? null : (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros());
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

    /** The unit billed: the list unit less an agreed discount (periods before SaaS.7 configuration: the list unit). */
    public function netUnitAmount(): Money
    {
        return Money::ofMinor($this->net_unit_amount_minor ?? $this->unit_amount_minor, $this->currency);
    }
}
