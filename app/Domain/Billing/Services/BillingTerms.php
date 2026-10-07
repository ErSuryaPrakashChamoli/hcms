<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
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
 */
final class BillingTerms
{
    public function __construct(private readonly BillingAudit $audit, private readonly BillingCatalog $catalog, private readonly BillingProfiles $profiles,
        private readonly TenantCommercialLock $lock, private readonly TenantContext $tenants) {}

    public function set(TenantSubscription $subscription, PlanPriceVersion $priceVersion, string $from, string $reason, User $actor, ?string $reference = null,
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

            $committed = $this->commitment($price, $version, $committedQuantity);
            $all = SubscriptionBillingTerm::query()->where(['subscription_id' => $subscription->id, 'status' => SubscriptionBillingTerm::ACTIVE])->lockForUpdate()->get();
            $rows = $all->filter(fn (SubscriptionBillingTerm $t) => $t->effective_to === null || $t->effective_to->toDateString() >= $from);
            $inForce = $rows->first(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $from);
            if ($inForce !== null && $inForce->plan_price_version_id === $version->id && $inForce->committed_quantity === $committed && $rows->count() === 1 && $inForce->effective_to === null) {
                return $inForce; // the same terms again
            }
            $dayBefore = Carbon::parse($from)->subDay()->toDateString();
            $previous = $all->first(fn (SubscriptionBillingTerm $t) => $t->effective_from->toDateString() <= $dayBefore
                && ($t->effective_to === null || $t->effective_to->toDateString() >= $dayBefore));
            $this->assertAnchored($previous, $price, $from, $state['status']);
            $notice = $this->requiredNotice($subscription, $previous, $price, $version, $from);

            $term = SubscriptionBillingTerm::query()->create(['subscription_id' => $subscription->id, 'plan_price_version_id' => $version->id, 'plan_price_id' => $price->id,
                'plan_version_id' => $price->plan_version_id, 'market_id' => $price->market_id, 'currency' => $version->currency, 'interval' => $price->interval,
                'basis' => $price->basis, 'committed_quantity' => $committed, 'price_notice_id' => $notice?->id, 'effective_from' => $from, 'status' => SubscriptionBillingTerm::ACTIVE,
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
            $before = $inForce === null ? 'none' : $this->describe(PlanPriceVersion::query()->findOrFail($inForce->plan_price_version_id), $inForce);
            $this->audit->both(AuditAction::BillingTermsSet, 'billing', $tenant, $term, "billing terms of subscription #{$subscription->id}",
                [['field' => 'price', 'before' => $before, 'after' => $this->describe($version, $term)]], $reason, $actor,
                ['subscription_id' => $subscription->id, 'plan_price_version_id' => $version->id, 'market' => $price->market->code, 'reference' => $term->reference,
                    'committed_quantity' => $committed, 'price_notice_id' => $notice?->id, 'replaced' => $rows->pluck('id')->all()], $from);

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

    /** Renewal dates of annual terms starting $start: the 12-month anniversaries. */
    public static function isRenewal(string $start, string $day): bool
    {
        [$s, $d] = [Carbon::parse($start), Carbon::parse($day)];
        $months = ($d->year - $s->year) * 12 + ($d->month - $s->month);

        return $months >= 12 && $months % 12 === 0 && $s->copy()->addMonthsNoOverflow($months)->toDateString() === $day;
    }

    private function commitment(PlanPrice $price, PlanPriceVersion $version, ?int $committed): ?int
    {
        if ($price->interval !== BillingInterval::Year || $price->basis !== PricingBasis::PerActiveEmployee) {
            if ($committed !== null) {
                throw new RuntimeException('A committed quantity belongs to annual per-employee terms only.');
            }

            return null;
        }
        $floor = max(1, $version->minimum_quantity);
        if ($committed === null || $committed < $floor || $committed > 1000000) {
            throw new RuntimeException("Annual terms are billed in advance on a committed quantity of at least {$floor} employees (the price's minimum).");
        }

        return $committed;
    }

    /** B-3 calendar anchoring, and annual terms changing only at renewal. */
    private function assertAnchored(?SubscriptionBillingTerm $previous, PlanPrice $price, string $from, CommercialStatus $status): void
    {
        $first = Carbon::parse($from)->day === 1;
        if ($price->interval === BillingInterval::Year) {
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
            return null; // first terms, or a plan or interval change agreed with the customer
        }
        $old = PlanPriceVersion::query()->findOrFail($previous->plan_price_version_id);
        if ($version->unit_amount_minor <= $old->unit_amount_minor && $version->minimum_quantity <= $old->minimum_quantity) {
            return null; // the same or a lower price
        }
        $notice = PriceChangeNotice::query()->where(['subscription_id' => $subscription->id, 'to_price_version_id' => $version->id, 'status' => PriceChangeNotice::PENDING])
            ->lockForUpdate()->first();
        if ($notice === null || $notice->effective_from->toDateString() > $from || Carbon::parse($notice->notice_date)->addDays(PriceNotices::NOTICE_DAYS)->toDateString() > $from) {
            throw new RuntimeException('This is a price increase for an existing subscriber: record a written notice at least '.PriceNotices::NOTICE_DAYS.' days before it takes effect.');
        }

        return $notice;
    }

    private function describe(PlanPriceVersion $version, SubscriptionBillingTerm $term): string
    {
        return "{$version->currency->value} {$version->amount()->toDecimal()} {$term->basis->label()}, {$term->interval->label()} (price version {$version->version})"
            .($term->committed_quantity !== null ? ", {$term->committed_quantity} committed" : '');
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
