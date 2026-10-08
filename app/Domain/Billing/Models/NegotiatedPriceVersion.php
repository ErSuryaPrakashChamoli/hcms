<?php

namespace App\Domain\Billing\Models;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7 configuration: one version of a customer's agreed price (draft → published → retired): the unit amount (per
 * employee per month, or a fixed monthly amount), the minimum quantity, an optional discount, and the standard
 * version it was based on, if any. Published from a date by maker-checker; then it never changes.
 */
#[Fillable(['negotiated_price_id', 'version', 'status', 'currency', 'unit_amount_minor', 'minimum_quantity', 'discount_percent', 'based_on_price_version_id',
    'effective_from', 'reason', 'created_by', 'published_by', 'published_at', 'retired_by', 'retired_at'])]
class NegotiatedPriceVersion extends Model
{
    use BelongsToTenant;

    private const MUTABLE_AFTER_PUBLISH = ['status', 'retired_by', 'retired_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->isDirty(['currency', 'negotiated_price_id'])) {
                throw new RuntimeException('A negotiated price version keeps its price and currency.');
            }
            $original = $version->getRawOriginal('status');
            if ($original === VersionStatus::Draft->value) {
                return;
            }
            $backwards = $version->isDirty('status') && ! ($original === VersionStatus::Published->value && $version->status === VersionStatus::Retired);
            if (array_diff(array_keys($version->getDirty()), self::MUTABLE_AFTER_PUBLISH) !== [] || $backwards) {
                throw new RuntimeException('A published negotiated price version is immutable: agree a new version.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Negotiated price versions are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['status' => VersionStatus::class, 'currency' => Currency::class, 'unit_amount_minor' => 'integer', 'minimum_quantity' => 'integer', 'version' => 'integer',
            'effective_from' => 'date', 'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /** The agreed discount as an exact decimal without trailing zeros ("5", "12.5"), whatever the column's scale. */
    protected function discountPercent(): Attribute
    {
        return Attribute::get(fn ($value) => $value === null ? null : (string) BigDecimal::of((string) $value)->strippedOfTrailingZeros());
    }

    public function negotiatedPrice(): BelongsTo
    {
        return $this->belongsTo(NegotiatedPrice::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->unit_amount_minor, $this->currency);
    }

    /** The unit amount after the agreed discount, rounded once with $mode. */
    public function netAmount(RoundingMode $mode = RoundingMode::HalfUp): Money
    {
        if ($this->discount_percent === null || BigDecimal::of((string) $this->discount_percent)->isZero()) {
            return $this->amount();
        }
        $net = BigDecimal::of($this->unit_amount_minor)->multipliedBy(BigDecimal::of(100)->minus((string) $this->discount_percent))->dividedBy(100, 0, $mode);

        return Money::ofMinor($net->toInt(), $this->currency);
    }
}
