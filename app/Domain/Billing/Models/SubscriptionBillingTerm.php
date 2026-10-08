<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7: the price version a subscription is billed at from a date (effective-dated, painted like plan
 * assignments). It answers "what price applied to this subscription on that day?". Only its end may move earlier
 * (or a future row be cancelled); the pinned price, market, currency, committed quantity (annual terms) and start
 * never change.
 *
 * SaaS.7 configuration: the price is a standard price version or a customer's negotiated price version (exactly one;
 * agreed terms take precedence over the catalogue, see BillingTerms).
 */
#[Fillable(['subscription_id', 'plan_price_version_id', 'plan_price_id', 'negotiated_price_version_id', 'plan_version_id', 'market_id', 'currency', 'interval', 'basis', 'committed_quantity', 'price_notice_id',
    'effective_from', 'effective_to', 'status', 'reason', 'reference', 'created_by', 'closed_by', 'closed_at', 'close_reason', 'superseded_by'])]
class SubscriptionBillingTerm extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    public const STANDARD = 'standard';

    public const NEGOTIATED = 'negotiated';

    protected static function booted(): void
    {
        static::creating(function (self $term): void {
            if (($term->plan_price_version_id === null) === ($term->negotiated_price_version_id === null)
                || ($term->plan_price_version_id !== null) !== ($term->plan_price_id !== null)) {
                throw new RuntimeException('Billing terms pin exactly one price: a standard price version or a negotiated one.');
            }
        });
        static::updating(function (self $term): void {
            $dirty = array_keys($term->getDirty());
            $earlier = ! $term->isDirty('effective_to') || ($term->effective_to !== null
                && ($term->getRawOriginal('effective_to') === null || $term->effective_to->toDateString() <= substr((string) $term->getRawOriginal('effective_to'), 0, 10)));
            if (array_diff($dirty, ['effective_to', 'status', 'closed_by', 'closed_at', 'close_reason', 'superseded_by', 'updated_at']) !== [] || ! $earlier
                || ($term->isDirty('status') && ! ($term->getRawOriginal('status') === self::ACTIVE && $term->status === self::CANCELLED))) {
                throw new RuntimeException('Billing terms keep their price and start: they can only end earlier or be cancelled before they start.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Billing terms are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'interval' => BillingInterval::class, 'basis' => PricingBasis::class, 'committed_quantity' => 'integer', 'effective_from' => 'date',
            'effective_to' => 'date', 'closed_at' => 'datetime'];
    }

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(PlanPriceVersion::class, 'plan_price_version_id');
    }

    public function negotiatedVersion(): BelongsTo
    {
        return $this->belongsTo(NegotiatedPriceVersion::class, 'negotiated_price_version_id');
    }

    /** standard or negotiated */
    public function source(): string
    {
        return $this->negotiated_price_version_id !== null ? self::NEGOTIATED : self::STANDARD;
    }

    /** The pinned version, whichever its source. */
    public function pinnedVersion(): PlanPriceVersion|NegotiatedPriceVersion
    {
        return $this->negotiated_price_version_id !== null
            ? NegotiatedPriceVersion::query()->findOrFail($this->negotiated_price_version_id)
            : PlanPriceVersion::query()->findOrFail($this->plan_price_version_id);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'subscription_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(BillingMarket::class, 'market_id');
    }
}
