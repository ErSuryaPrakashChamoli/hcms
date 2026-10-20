<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * SaaS.7 configuration: a customer's agreed price for its subscription (tenant-owned, fail-closed): one plan version,
 * market and interval, PEPM or fixed, within a contract window, with the contract reference. Its amounts are
 * versions published by maker-checker, exactly like the standard catalogue, which it never changes. A deal is data:
 * no customer ever needs code. The contract terms recorded here never change; a new deal is a new version.
 */
#[Fillable(['reference', 'subscription_id', 'plan_version_id', 'market_id', 'currency', 'interval', 'basis', 'contract_start', 'contract_end',
    'contract_reference', 'notes', 'reason', 'created_by'])]
class NegotiatedPrice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('A negotiated price keeps its contract terms: agree a new version instead.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Negotiated prices are never deleted: billing terms and invoices refer to them.');
        });
    }

    public function uniqueIds(): array
    {
        return ['reference'];
    }

    protected function casts(): array
    {
        return ['currency' => Currency::class, 'interval' => BillingInterval::class, 'basis' => PricingBasis::class, 'contract_start' => 'date', 'contract_end' => 'date'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(NegotiatedPriceVersion::class)->orderBy('version');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(BillingMarket::class, 'market_id');
    }

    /** Whether $day falls within the contract window. */
    public function covers(string $day): bool
    {
        return $this->contract_start->toDateString() <= $day && ($this->contract_end === null || $this->contract_end->toDateString() >= $day);
    }
}
