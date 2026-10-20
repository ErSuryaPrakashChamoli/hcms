<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Subscriptions\Enums\CommercialStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * SaaS.6: one span of a subscription's timeline: a commercial state and the plan version in force, between inclusive
 * business dates (UTC; ends_on null = open-ended). History is append-only: the state, version and start never change;
 * a later change brings ends_on earlier, or voids a row that has not started (it never took effect). Never deleted.
 */
#[Fillable(['tenant_id', 'subscription_id', 'status', 'plan_version_id', 'starts_on', 'ends_on', 'trigger', 'reason', 'reference',
    'created_by', 'closed_by', 'closed_at', 'close_reason', 'voided_at', 'superseded_by'])]
class SubscriptionPeriod extends Model
{
    use BelongsToTenant;

    private const MUTABLE = ['ends_on', 'closed_by', 'closed_at', 'close_reason', 'voided_at', 'superseded_by', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $period): void {
            if (array_diff(array_keys($period->getDirty()), self::MUTABLE) !== []) {
                throw new RuntimeException('A subscription period keeps its state, plan version and start: record a new period instead.');
            }
            $before = $period->getRawOriginal('ends_on');
            if ($period->isDirty('ends_on') && $before !== null && ($period->ends_on === null || $period->ends_on->toDateString() > substr((string) $before, 0, 10))) {
                throw new RuntimeException('A period can only end earlier: a longer span is a new period.');
            }
            if ($period->isDirty('voided_at') && $period->getRawOriginal('voided_at') !== null) {
                throw new RuntimeException('A voided period stays voided.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Subscription periods are never deleted: they are the commercial history.'));
    }

    protected function casts(): array
    {
        return ['status' => CommercialStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return BelongsTo<TenantSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'subscription_id');
    }

    /** @return array{id: int, status: CommercialStatus, plan_version_id: int, from: string, to: ?string} */
    public function toTimelineRow(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'plan_version_id' => (int) $this->plan_version_id,
            'from' => $this->starts_on->toDateString(), 'to' => $this->ends_on?->toDateString()];
    }
}
