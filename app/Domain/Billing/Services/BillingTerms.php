<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Billing\Models\NegotiatedPrice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\PriceChangeNotice;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Services\TenantCommercialLock;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Enums\CommercialStatus;
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
 * through a new, audited pin. The subscription itself is only read.
 *
 * SaaS.7 completion (B-3, B-15, approved):
 * - Calendar anchoring: the first terms may start any day (a partial first month); a change of monthly terms
 *   starts on the 1st of a month (the next period); annual terms start on the 1st of a month, while the
 *   subscription is active, with a committed quantity of at least the price's minimum.
 * - Annual terms change only at renewal (a 12-month anniversary of their start): price, plan, interval and
 *   commitment alike. A partial month before an annual term is billed on monthly terms.
 * - A price increase for an existing subscriber (a later version of the same price with a higher unit amount or
 *   minimum) needs a written notice recorded at least 30 days before the new terms start; the re-pin applies it.
 *   A decrease, or a change of plan or interval agreed with the customer, needs no notice.
 *
 * SaaS.7 configuration: precedence is CUSTOMER AGREED TERMS > PLAN PRICE VERSION > NO PRICE. A subscription may pin
 * its own negotiated price version (published by maker-checker, in force on the start date, within the contract);
 * those terms end with the contract. While a deal is in force for the same plan version, market and interval the
 * standard price cannot be pinned. A negotiated price is agreed in the contract, so it needs no B-15 notice. A day
 * with no terms is never billed at a guessed price: the billing run reports it (NO_PRICE_CONFIGURED).
 */
final class BillingTerms
{
    public function __construct(private readonly BillingAudit $audit, private readonly BillingCatalog $catalog, private readonly BillingProfiles $profiles,
        private readonly TenantCommercialLock $lock, private readonly TenantContext $tenants) {}

    public function set(TenantSubscription $subscription, PlanPriceVersion|NegotiatedPriceVersion $priceVersion, string $from, string $reason, User $actor, ?string $reference = null,
        ?int $committedQuantity = null): SubscriptionBillingTerm
    {
        OperatorChange::assert($actor, $reason, 'billing terms');
        $from = $this->day($from);
        if ($from < now()->toDateString()) {
            throw new RuntimeException('Billing terms take effect today or later.');
        }
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->lock->run($tenant, function () use ($tenant, $subscription, $priceVersion, $from, $reason, $actor, $reference, $committedQuantity) {
            $subscription = TenantSubscription::query()->with('periods')->findOrFail($subscription->id);
            $point = $priceVersion instanceof NegotiatedPriceVersion
                ? $this->negotiatedPoint($subscription, $priceVersion, $from)
                : $this->standardPoint($subscription, $priceVersion, $from);
            $state = $subscription->timeline()->stateOn($from);
            if ($state === null || ! $state['status']->entitled() || $state['derived'] || $state['plan_version_id'] !== $point['plan_version_id']) {
                throw new RuntimeException("On {$from} the subscription is not on {$point['plan_label']}: a price applies only to the plan version in force.");
            }
            $profile = $this->profiles->inForce($tenant, $from)
                ?? throw new RuntimeException("{$tenant->name} has no billing profile in force on {$from}.");
            if ($profile->market_id !== $point['market']->id) {
                throw new RuntimeException("{$tenant->name} is billed in {$profile->market->code} on {$from}, not in {$point['market']->code}.");
            }

            $committed = $this->commitment($point['interval'], $point['basis'], $point['minimum'], $committedQuantity);
            $all = SubscriptionBillingTerm::query()->where(['subscription_id' => $subscription->id, 'status' => SubscriptionBillingTerm::ACTIVE])->lockForUpdate()->get();
            $rows = $all->filter(fn (SubscriptionBillingTerm $t) => $t->effective_to === null || $t->effective_to->toDateString() >= $from);
            $inForce = $rows->first(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $from);
            if ($inForce !== null && $inForce->plan_price_version_id === $point['plan_price_version_id'] && $inForce->negotiated_price_version_id === $point['negotiated_price_version_id']
                && $inForce->committed_quantity === $committed && $rows->count() === 1 && $inForce->effective_to?->toDateString() === $point['ends']) {
                return $inForce; // the same terms again
            }
            $dayBefore = Carbon::parse($from)->subDay()->toDateString();
            $previous = $all->first(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $dayBefore
                && ($t->effective_to === null || $t->effective_to->toDateString() >= $dayBefore));
            $this->assertAnchored($previous, $point['interval'], $from, $state['status']);
            $notice = $point['price'] === null ? null : $this->requiredNotice($subscription, $previous, $point['price'], $point['version'], $from);

            $term = SubscriptionBillingTerm::query()->create(['subscription_id' => $subscription->id, 'plan_price_version_id' => $point['plan_price_version_id'],
                'plan_price_id' => $point['price']?->id, 'negotiated_price_version_id' => $point['negotiated_price_version_id'], 'plan_version_id' => $point['plan_version_id'],
                'market_id' => $point['market']->id, 'currency' => $point['version']->currency, 'interval' => $point['interval'], 'basis' => $point['basis'],
                'committed_quantity' => $committed, 'price_notice_id' => $notice?->id, 'effective_from' => $from, 'effective_to' => $point['ends'], 'status' => SubscriptionBillingTerm::ACTIVE,
                'reason' => $reason, 'reference' => $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 100), 'created_by' => $actor->id]);
            if ($notice !== null) {
                $notice->forceFill(['status' => PriceChangeNotice::APPLIED])->save();
                $this->audit->both(AuditAction::PriceNoticeApplied, 'billing', $tenant, $notice, "price notice of subscription #{$subscription->id}",
                    [['field' => 'notice', 'before' => 'pending', 'after' => 'applied']], $reason, $actor,
                    ['subscription_id' => $subscription->id, 'billing_term_id' => $term->id, 'notice_date' => $notice->notice_date->toDateString()], $from);
            }
            foreach ($rows as $row) {
                $starts = $row->effective_from->toDateString();
                $row->forceFill($starts >= $from
                    ? ['status' => SubscriptionBillingTerm::CANCELLED, 'superseded_by' => $term->id, 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason]
                    : ['effective_to' => Carbon::parse($from)->subDay()->toDateString(), 'superseded_by' => $term->id, 'closed_by' => $actor->id, 'closed_at' => now(), 'close_reason' => $reason])->save();
            }
            $before = $inForce === null ? 'none' : $this->describe($inForce->pinnedVersion(), $inForce);
            $this->audit->both(AuditAction::BillingTermsSet, 'billing', $tenant, $term, "billing terms of subscription #{$subscription->id}",
                [['field' => 'price', 'before' => $before, 'after' => $this->describe($point['version'], $term)]], $reason, $actor,
                ['subscription_id' => $subscription->id, 'price_source' => $term->source(), 'plan_price_version_id' => $point['plan_price_version_id'],
                    'negotiated_price_version_id' => $point['negotiated_price_version_id'], 'market' => $point['market']->code, 'reference' => $term->reference,
                    'committed_quantity' => $committed, 'price_notice_id' => $notice?->id, 'replaced' => $rows->pluck('id')->all()], $from);

            return $term;
        });
    }

    /**
     * "What price applied to this subscription on $day?" The pinned terms in force (standard or negotiated) and
     * whether they still match the plan version the subscription was on (a later plan change without a new pin is
     * reported, never re-priced).
     *
     * @return array{term: SubscriptionBillingTerm, version: PlanPriceVersion|NegotiatedPriceVersion, source: string, unit_amount: Money, consistent: bool}|null
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
            $version = $term->pinnedVersion();
            $state = TenantSubscription::query()->with('periods')->findOrFail($subscription->id)->timeline()->stateOn($day);

            return ['term' => $term, 'version' => $version, 'source' => $term->source(), 'unit_amount' => $version->amount(),
                'consistent' => $state !== null && $state['status']->entitled() && $state['plan_version_id'] === $term->plan_version_id];
        });
    }

    /**
     * The price that would apply to the subscription on $day for an interval, by precedence: the customer's agreed
     * (negotiated) price in force, else the standard version on sale in its billing market, else NO_PRICE_CONFIGURED
     * with why. Never another market's price and never a converted one. It pins nothing: set() does.
     *
     * @return array{source: string, version: PlanPriceVersion|NegotiatedPriceVersion|null, reason: string}
     */
    public function priceFor(TenantSubscription $subscription, BillingInterval $interval, string $day): array
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $subscription, $interval, $day) {
            $state = TenantSubscription::query()->with('periods')->findOrFail($subscription->id)->timeline()->stateOn($day);
            if ($state === null || $state['plan_version_id'] === null) {
                return ['source' => 'none', 'version' => null, 'reason' => "NO_PRICE_CONFIGURED: the subscription has no plan version on {$day}."];
            }
            $profile = $this->profiles->inForce($tenant, $day);
            if ($profile === null) {
                return ['source' => 'none', 'version' => null, 'reason' => "NO_PRICE_CONFIGURED: {$tenant->name} has no billing profile (market) on {$day}."];
            }
            $agreed = app(NegotiatedPrices::class)->agreedFor($subscription, $state['plan_version_id'], $profile->market_id, $interval, $day);
            if ($agreed !== null) {
                return ['source' => SubscriptionBillingTerm::NEGOTIATED, 'version' => $agreed, 'reason' => 'The customer\'s agreed price takes precedence.'];
            }
            $price = PlanPrice::query()->where(['plan_version_id' => $state['plan_version_id'], 'market_id' => $profile->market_id, 'interval' => $interval])->first();
            $onSale = $price === null ? null : $this->catalog->versionOnSale($price, $day);

            return $onSale !== null
                ? ['source' => SubscriptionBillingTerm::STANDARD, 'version' => $onSale, 'reason' => "The standard price on sale in {$profile->market->code}."]
                : ['source' => 'none', 'version' => null, 'reason' => "NO_PRICE_CONFIGURED: no agreed price and no standard {$interval->value} price on sale in {$profile->market->code} on {$day}."];
        });
    }

    /** Renewal dates of annual terms starting $start: the 12-month anniversaries. */
    public static function isRenewal(string $start, string $day): bool
    {
        [$s, $d] = [Carbon::parse($start), Carbon::parse($day)];
        $months = ($d->year - $s->year) * 12 + ($d->month - $s->month);

        return $months >= 12 && $months % 12 === 0 && $s->copy()->addMonthsNoOverflow($months)->toDateString() === $day;
    }

    private function commitment(BillingInterval $interval, PricingBasis $basis, int $minimum, ?int $committed): ?int
    {
        if ($interval !== BillingInterval::Year || $basis !== PricingBasis::PerActiveEmployee) {
            if ($committed !== null) {
                throw new RuntimeException('A committed quantity belongs to annual per-employee terms only.');
            }

            return null;
        }
        $floor = max(1, $minimum);
        if ($committed === null || $committed < $floor || $committed > 1000000) {
            throw new RuntimeException("Annual terms are billed in advance on a committed quantity of at least {$floor} employees (the price's minimum).");
        }

        return $committed;
    }

    /**
     * A standard price version on sale on $from. Agreed terms take precedence: while the subscriber has a negotiated
     * price in force for the same plan version, market and interval, the standard price cannot be pinned.
     *
     * @return array<string, mixed>
     */
    private function standardPoint(TenantSubscription $subscription, PlanPriceVersion $priceVersion, string $from): array
    {
        $price = PlanPrice::query()->with('market', 'planVersion.plan')->findOrFail($priceVersion->plan_price_id);
        $version = PlanPriceVersion::query()->sharedLock()->findOrFail($priceVersion->id);
        if ($version->status !== VersionStatus::Published || $this->catalog->versionOnSale($price, $from)?->id !== $version->id) {
            throw new RuntimeException("Price version {$version->version} is not the version of this price on sale on {$from}.");
        }
        $agreed = app(NegotiatedPrices::class)->agreedFor($subscription, $price->plan_version_id, $price->market_id, $price->interval, $from);
        if ($agreed !== null) {
            throw new RuntimeException("Agreed terms take precedence: the subscriber has a negotiated price in force on {$from} (deal v{$agreed->version}); pin it instead of the standard price.");
        }

        return ['price' => $price, 'version' => $version, 'plan_price_version_id' => $version->id, 'negotiated_price_version_id' => null, 'plan_version_id' => $price->plan_version_id,
            'plan_label' => $price->planVersion->label(), 'market' => $price->market, 'interval' => $price->interval, 'basis' => $price->basis,
            'minimum' => $version->minimum_quantity, 'ends' => null];
    }

    /**
     * A customer's negotiated price version: of this subscription, published and in force on $from (within its
     * contract). The terms end with the contract; the next terms are pinned explicitly (never a silent fallback).
     *
     * @return array<string, mixed>
     */
    private function negotiatedPoint(TenantSubscription $subscription, NegotiatedPriceVersion $priceVersion, string $from): array
    {
        // Read inside the subscription's tenant: another tenant's deal is not even found (fail closed).
        $version = NegotiatedPriceVersion::query()->sharedLock()->find($priceVersion->id)
            ?? throw new RuntimeException('This negotiated price belongs to another subscription.');
        $deal = NegotiatedPrice::query()->with('market')->findOrFail($version->negotiated_price_id);
        if ($deal->subscription_id !== $subscription->id) {
            throw new RuntimeException('This negotiated price belongs to another subscription.');
        }
        if ($version->status !== VersionStatus::Published || app(NegotiatedPrices::class)->inForce($deal, $from)?->id !== $version->id) {
            throw new RuntimeException("Negotiated version {$version->version} is not the deal's version in force on {$from}.");
        }

        return ['price' => null, 'version' => $version, 'plan_price_version_id' => null, 'negotiated_price_version_id' => $version->id, 'plan_version_id' => $deal->plan_version_id,
            'plan_label' => $this->catalog->planLabel($deal->plan_version_id), 'market' => $deal->market, 'interval' => $deal->interval, 'basis' => $deal->basis,
            'minimum' => $version->minimum_quantity, 'ends' => $deal->contract_end?->toDateString()];
    }

    /** B-3 calendar anchoring, and annual terms changing only at renewal. */
    private function assertAnchored(?SubscriptionBillingTerm $previous, BillingInterval $interval, string $from, CommercialStatus $status): void
    {
        $first = Carbon::parse($from)->day === 1;
        if ($interval === BillingInterval::Year) {
            if (! $first) {
                throw new RuntimeException('Annual terms start on the 1st of a month: bill the partial month before it on monthly terms.');
            }
            if (! in_array($status, [CommercialStatus::Active, CommercialStatus::Grace], true)) {
                throw new RuntimeException("Annual terms are billed in advance when they start: on {$from} the subscription is {$status->value}, not active.");
            }
        }
        if ($previous === null) {
            return; // first terms (or after a gap): any day for monthly terms
        }
        if ($previous->interval === BillingInterval::Year && ! self::isRenewal($previous->effective_from->toDateString(), $from)) {
            throw new RuntimeException('Annual terms change only at renewal: the next one starts '
                .$this->nextRenewal($previous->effective_from->toDateString(), $from).'.');
        }
        if (! $first) {
            throw new RuntimeException('A change of billing terms starts with a billing period: the 1st of a month.');
        }
    }

    private function nextRenewal(string $start, string $after): string
    {
        $renewal = Carbon::parse($start)->addMonths(12);
        while ($renewal->toDateString() < $after) {
            $renewal->addMonths(12);
        }

        return $renewal->toDateString();
    }

    /** B-15: the pending notice a price increase needs (30 days before $from), or null when none is needed. */
    private function requiredNotice(TenantSubscription $subscription, ?SubscriptionBillingTerm $previous, PlanPrice $price, PlanPriceVersion $version, string $from): ?PriceChangeNotice
    {
        if ($previous === null || $previous->plan_price_id !== $price->id) {
            return null; // first terms, a plan or interval change, or the end of a deal: agreed with the customer
        }
        $old = PlanPriceVersion::query()->findOrFail($previous->plan_price_version_id);
        if ($version->unit_amount_minor <= $old->unit_amount_minor && $version->minimum_quantity <= $old->minimum_quantity) {
            return null; // the same or a lower price
        }
        $notice = PriceChangeNotice::query()->where(['subscription_id' => $subscription->id, 'to_price_version_id' => $version->id, 'status' => PriceChangeNotice::PENDING])
            ->lockForUpdate()->first();
        $days = PriceNotices::noticeDays($notice?->notice_date?->toDateString() ?? now()->toDateString());
        if ($notice === null || $notice->effective_from->toDateString() > $from || Carbon::parse($notice->notice_date)->addDays($days)->toDateString() > $from) {
            throw new RuntimeException("This is a price increase for an existing subscriber: record a written notice at least {$days} days before it takes effect.");
        }

        return $notice;
    }

    private function describe(PlanPriceVersion|NegotiatedPriceVersion $version, SubscriptionBillingTerm $term): string
    {
        $what = $version instanceof NegotiatedPriceVersion
            ? "negotiated version {$version->version}".($version->minimum_quantity > 0 ? ", minimum {$version->minimum_quantity}" : '')
                .($version->discount_percent !== null ? ", less {$version->discount_percent} %" : '')
            : "price version {$version->version}";

        return "{$version->currency->value} {$version->amount()->toDecimal()} {$term->basis->label()}, {$term->interval->label()} ({$what})"
            .($term->committed_quantity !== null ? ", {$term->committed_quantity} committed" : '')
            .($term->effective_to !== null ? ' until '.$term->effective_to->toDateString() : '');
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
