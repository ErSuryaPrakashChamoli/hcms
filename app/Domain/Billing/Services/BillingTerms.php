<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Services\TenantCommercialLock;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * SaaS.7: which price a subscription is billed at, from a date. An operator pins a published price version that is
 * on sale on the start date, of the plan version the subscription is on that day, in the tenant's billing market.
 * Rows are painted like plan assignments (the row in force ends the day before; later rows are cancelled) under
 * the tenant's commercial lock. Nothing re-prices a subscriber automatically: a new price version reaches it only
 * through a new, audited pin (when to do so is decision B-15). The subscription itself is only read.
 */
final class BillingTerms
{
    public function __construct(private readonly BillingAudit $audit, private readonly BillingCatalog $catalog, private readonly BillingProfiles $profiles,
        private readonly TenantCommercialLock $lock, private readonly TenantContext $tenants) {}

    public function set(TenantSubscription $subscription, PlanPriceVersion $priceVersion, string $from, string $reason, User $actor, ?string $reference = null): SubscriptionBillingTerm
    {
        OperatorChange::assert($actor, $reason, 'billing terms');
        $from = $this->day($from);
        if ($from < now()->toDateString()) {
            throw new RuntimeException('Billing terms take effect today or later.');
        }
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->lock->run($tenant, function () use ($tenant, $subscription, $priceVersion, $from, $reason, $actor, $reference) {
            $subscription = TenantSubscription::query()->with('periods')->findOrFail($subscription->id);
            $price = PlanPrice::query()->with('market', 'planVersion.plan')->findOrFail($priceVersion->plan_price_id);
            $version = PlanPriceVersion::query()->sharedLock()->findOrFail($priceVersion->id);
            if ($version->status !== VersionStatus::Published || $this->catalog->versionOnSale($price, $from)?->id !== $version->id) {
                throw new RuntimeException("Price version {$version->version} is not the version of this price on sale on {$from}.");
            }
            $state = $subscription->timeline()->stateOn($from);
            if ($state === null || ! $state['status']->entitled() || $state['derived'] || $state['plan_version_id'] !== $price->plan_version_id) {
                throw new RuntimeException("On {$from} the subscription is not on {$price->planVersion->label()}: a price applies only to the plan version in force.");
            }
            $profile = $this->profiles->inForce($tenant, $from)
                ?? throw new RuntimeException("{$tenant->name} has no billing profile in force on {$from}.");
            if ($profile->market_id !== $price->market_id) {
                throw new RuntimeException("{$tenant->name} is billed in {$profile->market->code} on {$from}, not in {$price->market->code}.");
            }

            $rows = SubscriptionBillingTerm::query()->where(['subscription_id' => $subscription->id, 'status' => SubscriptionBillingTerm::ACTIVE])->lockForUpdate()->get()
                ->filter(fn (SubscriptionBillingTerm $t) => $t->effective_to === null || $t->effective_to->toDateString() >= $from);
            $inForce = $rows->first(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $from);
            if ($inForce !== null && $inForce->plan_price_version_id === $version->id && $rows->count() === 1 && $inForce->effective_to === null) {
                return $inForce; // the same terms again
            }
            $term = SubscriptionBillingTerm::query()->create(['subscription_id' => $subscription->id, 'plan_price_version_id' => $version->id, 'plan_price_id' => $price->id,
                'plan_version_id' => $price->plan_version_id, 'market_id' => $price->market_id, 'currency' => $version->currency, 'interval' => $price->interval,
                'basis' => $price->basis, 'effective_from' => $from, 'status' => SubscriptionBillingTerm::ACTIVE, 'reason' => $reason,
                'reference' => $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 100), 'created_by' => $actor->id]);
            foreach ($rows as $row) {
                $starts = $row->effective_from->toDateString();
                $row->forceFill($starts >= $from
                    ? ['status' => SubscriptionBillingTerm::CANCELLED, 'superseded_by' => $term->id, 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason]
                    : ['effective_to' => Carbon::parse($from)->subDay()->toDateString(), 'superseded_by' => $term->id, 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason])->save();
            }
            $before = $inForce === null ? 'none' : $this->describe(PlanPriceVersion::query()->findOrFail($inForce->plan_price_version_id), $inForce);
            $this->audit->both(AuditAction::BillingTermsSet, 'billing', $tenant, $term, "billing terms of subscription #{$subscription->id}",
                [['field' => 'price', 'before' => $before, 'after' => $this->describe($version, $term)]], $reason, $actor,
                ['subscription_id' => $subscription->id, 'plan_price_version_id' => $version->id, 'market' => $price->market->code, 'reference' => $term->reference,
                    'replaced' => $rows->pluck('id')->all()], $from);

            return $term;
        });
    }

    /**
     * "What price applied to this subscription on $day?" The pinned terms in force and whether they still match the
     * plan version the subscription was on (a later plan change without a new pin is reported, never re-priced).
     *
     * @return array{term: SubscriptionBillingTerm, version: PlanPriceVersion, unit_amount: Money, consistent: bool}|null
     */
    public function applicableOn(TenantSubscription $subscription, string $day): ?array
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($subscription, $day) {
            $term = SubscriptionBillingTerm::query()->where(['subscription_id' => $subscription->id, 'status' => SubscriptionBillingTerm::ACTIVE])
                ->whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))->first();
            if ($term === null) {
                return null;
            }
            $version = PlanPriceVersion::query()->findOrFail($term->plan_price_version_id);
            $state = TenantSubscription::query()->with('periods')->findOrFail($subscription->id)->timeline()->stateOn($day);

            return ['term' => $term, 'version' => $version, 'unit_amount' => $version->amount(),
                'consistent' => $state !== null && $state['status']->entitled() && $state['plan_version_id'] === $term->plan_version_id];
        });
    }

    private function describe(PlanPriceVersion $version, SubscriptionBillingTerm $term): string
    {
        return "{$version->currency->value} {$version->amount()->toDecimal()} {$term->basis->label()} {$term->interval->label()} (price version {$version->version})";
    }

    private function day(string $day): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
    }
}
