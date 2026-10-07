<?php

namespace App\Domain\Billing\Models;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7: one version of a price's amount (draft → published → retired). A published version never changes: its
 * amount, currency and start are history that billing terms and invoices refer to. A later version supersedes it
 * for new terms from its own start; nothing re-prices an existing subscriber.
 */
#[Fillable(['plan_price_id', 'version', 'status', 'currency', 'unit_amount_minor', 'effective_from', 'reason', 'created_by', 'published_by',
    'published_at', 'retired_by', 'retired_at'])]
class PlanPriceVersion extends Model
{
    private const MUTABLE_AFTER_PUBLISH = ['status', 'retired_by', 'retired_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $original = $version->getRawOriginal('status');
            if ($version->isDirty('currency') || ($version->isDirty('plan_price_id'))) {
                throw new RuntimeException('A price version keeps its price and currency.');
            }
            if ($original === VersionStatus::Draft->value) {
                return;
            }
            $backwards = $version->isDirty('status') && ! ($original === VersionStatus::Published->value && $version->status === VersionStatus::Retired);
            if (array_diff(array_keys($version->getDirty()), self::MUTABLE_AFTER_PUBLISH) !== [] || $backwards) {
                throw new RuntimeException('A published price version is immutable: draft a new version.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Price versions are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['status' => VersionStatus::class, 'currency' => Currency::class, 'unit_amount_minor' => 'integer', 'version' => 'integer',
            'effective_from' => 'date', 'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency);
    }
}
