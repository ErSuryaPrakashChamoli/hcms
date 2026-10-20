<?php

namespace App\Domain\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.7 completion (B-15): the written notice of a price increase to an existing subscriber: when it was sent, the
 * new price version and when it takes effect (at least 30 days later, at a period start or annual renewal). It
 * changes no price by itself: the operator's re-pin applies it, and marks it applied.
 */
#[Fillable(['subscription_id', 'from_price_version_id', 'to_price_version_id', 'notice_date', 'effective_from', 'reference', 'reason', 'status', 'created_by'])]
class PriceChangeNotice extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    protected static function booted(): void
    {
        static::updating(function (self $notice): void {
            if (array_diff(array_keys($notice->getDirty()), ['status', 'updated_at']) !== [] || $notice->getRawOriginal('status') !== self::PENDING || $notice->status !== self::APPLIED) {
                throw new RuntimeException('A price notice is a record of what was sent: it can only be marked applied.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Price notices are never deleted.');
        });
    }

    protected function casts(): array
    {
        return ['notice_date' => 'date', 'effective_from' => 'date'];
    }

    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(PlanPriceVersion::class, 'to_price_version_id');
    }

    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(PlanPriceVersion::class, 'from_price_version_id');
    }
}
