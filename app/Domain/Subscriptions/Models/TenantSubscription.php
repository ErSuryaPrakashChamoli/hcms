<?php

namespace App\Domain\Subscriptions\Models;

use App\Domain\Subscriptions\Support\SubscriptionTimeline;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * SaaS.6: a tenant's commercial agreement (tenant-owned, fail-closed, like the plan assignments it drives). Its
 * meaning over time is its effective-dated timeline of periods. Written only by CommercialSubscriptions (platform
 * operators, reasoned, audited on the tenant and platform chains); never deleted.
 */
#[Fillable(['tenant_id', 'reason', 'reference', 'created_by'])]
class TenantSubscription extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('A subscription is changed through its periods, never edited.'));
        static::deleting(fn () => throw new RuntimeException('Subscriptions are never deleted: their history is the commercial record.'));
    }

    /** @return HasMany<SubscriptionPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class, 'subscription_id')->orderBy('starts_on')->orderBy('id');
    }

    /** The timeline of the periods that took (or will take) effect. */
    public function timeline(): SubscriptionTimeline
    {
        return SubscriptionTimeline::of($this->periods->whereNull('voided_at')->values());
    }
}
