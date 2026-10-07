<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Entitlements\Models\PlanVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * SaaS.7: the price of one published plan version in one market for one interval (platform catalogue). Its
 * amounts are versioned separately (PlanPriceVersion), so each market's price changes on its own. Never read by
 * the entitlement engine (ADR-0037).
 */
#[Fillable(['plan_version_id', 'market_id', 'interval', 'basis', 'reason', 'created_by'])]
class PlanPrice extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('A price keeps its plan version, market, interval and basis: amounts change through price versions.');
        });
        static::deleting(function (): void {
            throw new RuntimeException('Prices are never deleted: billing terms and invoices refer to them.');
        });
    }

    protected function casts(): array
    {
        return ['interval' => BillingInterval::class, 'basis' => PricingBasis::class];
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(BillingMarket::class, 'market_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanPriceVersion::class)->orderBy('version');
    }
}
