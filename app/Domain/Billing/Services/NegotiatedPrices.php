<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\NegotiatedPrice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7 configuration: customer-specific commercial terms without code and without touching the standard catalogue.
 * An operator records the deal (subscription, plan version, market, interval, PEPM or fixed, contract window and
 * reference), drafts its amount (unit, minimum, optional discount, optionally based on a standard version) and asks
 * for its publication; another operator approves it (B-13: a price publication). The deal then takes precedence over
 * the standard price for that subscription: billing terms pin it, and the standard price cannot be pinned while it is
 * in force. Its versions never change once published; a renegotiation is a new version. No price is ever derived from
 * another market's or converted: a deal is in its market's currency.
 */
final class NegotiatedPrices
{
    public function __construct(private readonly FinancialApprovals $approvals, private readonly BillingAudit $audit, private readonly BillingCatalog $catalog,
        private readonly TenantContext $tenants) {}

    public function create(TenantSubscription $subscription, int $planVersionId, BillingMarket $market, string $interval, string $basis, string $contractStart,
        ?string $contractEnd, ?string $contractReference, ?string $notes, string $reason, User $actor): NegotiatedPrice
    {
        OperatorChange::assert($actor, $reason, 'negotiated prices');
        $interval = BillingInterval::tryFrom($interval) ?? throw new RuntimeException('The interval is month or year.');
        $basis = PricingBasis::tryFrom($basis) ?? throw new RuntimeException('The basis is per_active_employee (PEPM) or flat (a fixed monthly amount).');
        [$start, $end] = [$this->day($contractStart), blank($contractEnd) ? null : $this->day((string) $contractEnd)];
        if ($end !== null && $end < $start) {
            throw new RuntimeException('The contract ends on or after it starts.');
        }
        $planLabel = $this->catalog->publishedPlanLabel($planVersionId);
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $subscription, $planVersionId, $planLabel, $market, $interval, $basis, $start, $end, $contractReference, $notes, $reason, $actor) {
            try {
                return DB::transaction(function () use ($tenant, $subscription, $planVersionId, $planLabel, $market, $interval, $basis, $start, $end, $contractReference, $notes, $reason, $actor) {
                    TenantSubscription::query()->lockForUpdate()->findOrFail($subscription->id); // serialises deals of one subscription
                    $overlap = NegotiatedPrice::query()->where(['subscription_id' => $subscription->id, 'plan_version_id' => $planVersionId, 'market_id' => $market->id, 'interval' => $interval])
                        ->where(fn ($q) => $q->whereNull('contract_end')->orWhereDate('contract_end', '>=', $start))
                        ->when($end !== null, fn ($q) => $q->whereDate('contract_start', '<=', $end))->first();
                    if ($overlap !== null) {
                        throw new RuntimeException('This subscription already has a deal for that plan version, market and interval in that window ('
                            .$overlap->contract_start->toDateString().' to '.($overlap->contract_end?->toDateString() ?? 'open').'): agree a new version of it.');
                    }
                    $price = NegotiatedPrice::query()->create(['subscription_id' => $subscription->id, 'plan_version_id' => $planVersionId, 'market_id' => $market->id,
                        'currency' => $market->currency, 'interval' => $interval, 'basis' => $basis, 'contract_start' => $start, 'contract_end' => $end,
                        'contract_reference' => blank($contractReference) ? null : mb_substr(trim((string) $contractReference), 0, 100),
                        'notes' => blank($notes) ? null : mb_substr(trim((string) $notes), 0, 2000), 'reason' => trim($reason), 'created_by' => $actor->id]);
                    $this->audit->both(AuditAction::NegotiatedPriceCreated, 'billing', $tenant, $price, "negotiated price of subscription #{$subscription->id}",
                        [['field' => 'deal', 'before' => 'none', 'after' => "{$planLabel} · {$market->code} · {$basis->label()}, {$interval->label()} · {$start} to ".($end ?? 'open')]],
                        $reason, $actor, ['negotiated_price_reference' => $price->reference, 'contract_reference' => $price->contract_reference], $start);

                    return $price;
                });
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('This subscription already has a deal for that plan version, market and interval: agree a new version of it.');
            }
        });
    }

    /** $amount in the market currency's major unit; $discountPercent an exact decimal (e.g. "12.5"); $basedOn a standard version it was negotiated from. */
    public function draftVersion(NegotiatedPrice $price, string $amount, int $minimumQuantity, ?string $discountPercent, ?PlanPriceVersion $basedOn, string $reason, User $actor): NegotiatedPriceVersion
    {
        OperatorChange::assert($actor, $reason, 'negotiated prices');
        $tenant = Tenant::query()->findOrFail($price->tenant_id);
        try {
            $money = Money::parse($amount, $price->currency);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        if ($money->isNegative()) {
            throw new RuntimeException('A negotiated price is zero or more.');
        }
        if ($minimumQuantity < 0 || $minimumQuantity > 1000000 || ($minimumQuantity > 0 && $price->basis !== PricingBasis::PerActiveEmployee)) {
            throw new RuntimeException('A minimum quantity (0 to 1,000,000 employees) applies only to a per-employee deal.');
        }
        $discount = blank($discountPercent) ? null : trim((string) $discountPercent);
        if ($discount !== null && (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $discount) !== 1 || BigDecimal::of($discount)->isGreaterThanOrEqualTo(100))) {
            throw new RuntimeException('The discount is a percentage below 100 with at most four decimals.');
        }
        if ($basedOn !== null && $basedOn->currency !== $price->currency) {
            throw new RuntimeException('A deal is based on a standard price of its own market and currency (never converted).');
        }

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $price, $money, $minimumQuantity, $discount, $basedOn, $reason, $actor) {
            $locked = NegotiatedPrice::query()->lockForUpdate()->findOrFail($price->id);
            if (NegotiatedPriceVersion::query()->where(['negotiated_price_id' => $locked->id, 'status' => VersionStatus::Draft])->exists()) {
                throw new RuntimeException('This deal already has a draft version: publish or retire it first.');
            }
            $version = NegotiatedPriceVersion::query()->create(['negotiated_price_id' => $locked->id,
                'version' => (int) NegotiatedPriceVersion::query()->where('negotiated_price_id', $locked->id)->max('version') + 1, 'status' => VersionStatus::Draft,
                'currency' => $money->currency, 'unit_amount_minor' => $money->minor, 'minimum_quantity' => $minimumQuantity, 'discount_percent' => $discount,
                'based_on_price_version_id' => $basedOn?->id, 'reason' => trim($reason), 'created_by' => $actor->id]);
            $this->audit->both(AuditAction::NegotiatedPriceVersionDrafted, 'billing', $tenant, $version, "negotiated price v{$version->version}",
                [['field' => 'price', 'before' => 'none', 'after' => $this->describe($version)]], $reason, $actor,
                ['negotiated_price_reference' => $locked->reference, 'unit_amount_minor' => $money->minor, 'minimum_quantity' => $minimumQuantity, 'discount_percent' => $discount]);

            return $version;
        }));
    }

    /** Asks for a draft to apply from $effectiveFrom (B-13: published only when another operator approves). */
    public function requestPublication(NegotiatedPriceVersion $version, string $effectiveFrom, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'negotiated prices');
        $from = $this->day($effectiveFrom);
        $tenant = Tenant::query()->findOrFail($version->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $version, $from, $reason, $maker) {
            $version = NegotiatedPriceVersion::query()->with('negotiatedPrice')->findOrFail($version->id);
            $this->assertPublishable($version, $from);
            $current = $this->inForce($version->negotiatedPrice, $from);

            return $this->approvals->request(ApprovalAction::NegotiatedPricePublication, $version, $tenant, [
                'tenant' => $tenant->name, 'negotiated_price_reference' => $version->negotiatedPrice->reference, 'version' => $version->version,
                'price' => $this->describe($version), 'effective_from' => $from, 'contract_reference' => $version->negotiatedPrice->contract_reference,
            ], ['in_force' => $current === null ? 'none' : $this->describe($current)], ['in_force' => $this->describe($version)." from {$from}"],
                "{$version->negotiatedPrice->reference}:v{$version->version}:{$from}", $reason, $maker);
        });
    }

    /** Executes an approved publication (called by the approval desk, inside its transaction). */
    public function executePublication(FinancialApproval $approval): NegotiatedPriceVersion
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::NegotiatedPricePublication);
        $tenant = Tenant::query()->findOrFail($approval->subject_tenant_id);
        $checker = User::query()->findOrFail($approval->checker_id);
        $from = (string) $approval->payload['effective_from'];

        return $this->tenants->runAs($tenant, function () use ($tenant, $approval, $checker, $from) {
            $locked = NegotiatedPriceVersion::query()->with('negotiatedPrice')->lockForUpdate()->findOrFail($approval->subject_id);
            $this->assertPublishable($locked, $from);
            $locked->forceFill(['status' => VersionStatus::Published, 'effective_from' => $from, 'published_by' => $checker->id, 'published_at' => now()])->save();
            $this->audit->both(AuditAction::NegotiatedPriceVersionPublished, 'billing', $tenant, $locked, "negotiated price v{$locked->version}",
                [['field' => 'status', 'before' => 'draft', 'after' => 'published'], ['field' => 'price', 'before' => null, 'after' => $this->describe($locked)." from {$from}"]],
                (string) $approval->checker_reason, $checker, ['negotiated_price_reference' => $locked->negotiatedPrice->reference, 'approval' => $approval->reference,
                    'maker_id' => $approval->maker_id, 'checker_id' => $checker->id, 'correlation_id' => $approval->correlation_key], $from);
            $this->approvals->executed($approval, "Negotiated price v{$locked->version} from {$from}");

            return $locked;
        });
    }

    public function retireVersion(NegotiatedPriceVersion $version, string $reason, User $actor): NegotiatedPriceVersion
    {
        OperatorChange::assert($actor, $reason, 'negotiated prices');
        $tenant = Tenant::query()->findOrFail($version->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $version, $reason, $actor) {
            $locked = NegotiatedPriceVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status === VersionStatus::Retired) {
                return $locked;
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => VersionStatus::Retired, 'retired_by' => $actor->id, 'retired_at' => now()])->save();
            $this->audit->both(AuditAction::NegotiatedPriceVersionRetired, 'billing', $tenant, $locked, "negotiated price v{$locked->version}",
                [['field' => 'status', 'before' => $before, 'after' => 'retired']], $reason, $actor, ['negotiated_price_version_id' => $locked->id]);

            return $locked;
        }));
    }

    /** The published version of a deal in force on $day (latest started, not retired), within the contract window. */
    public function inForce(NegotiatedPrice $price, string $day): ?NegotiatedPriceVersion
    {
        if (! $price->covers($day)) {
            return null;
        }
        $version = NegotiatedPriceVersion::query()->where('negotiated_price_id', $price->id)->where('status', '<>', VersionStatus::Draft->value)
            ->whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('version')->first();

        return $version?->status === VersionStatus::Published ? $version : null;
    }

    /** A deal of the subscription in force on $day for the plan version, market and interval (agreed terms take precedence). */
    public function agreedFor(TenantSubscription $subscription, int $planVersionId, int $marketId, BillingInterval $interval, string $day): ?NegotiatedPriceVersion
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($subscription, $planVersionId, $marketId, $interval, $day) {
            $price = NegotiatedPrice::query()->where(['subscription_id' => $subscription->id, 'plan_version_id' => $planVersionId, 'market_id' => $marketId, 'interval' => $interval])
                ->whereDate('contract_start', '<=', $day)->where(fn ($q) => $q->whereNull('contract_end')->orWhereDate('contract_end', '>=', $day))->first();

            return $price === null ? null : $this->inForce($price, $day);
        });
    }

    /** @return Collection<int, NegotiatedPrice> a tenant's deals with their versions */
    public function forTenant(Tenant $tenant): Collection
    {
        return $this->tenants->runAs($tenant, fn () => NegotiatedPrice::query()->with('versions', 'market')->orderByDesc('id')->get()
            ->each(fn (NegotiatedPrice $deal) => $deal->versions->each->setRelation('negotiatedPrice', $deal)));
    }

    /** DRAFT, PENDING_APPROVAL, SCHEDULED, CURRENT, SUPERSEDED, EXPIRED (contract ended) or RETIRED on $day. */
    public function state(NegotiatedPriceVersion $version, ?string $day = null, bool $pendingApproval = false): string
    {
        $day ??= now()->toDateString();
        $price = $this->dealOf($version);

        return match (true) {
            $version->status === VersionStatus::Draft => $pendingApproval ? 'PENDING_APPROVAL' : 'DRAFT',
            $version->status === VersionStatus::Retired => 'RETIRED',
            $version->effective_from->toDateString() > $day => 'SCHEDULED',
            $price->contract_end !== null && $price->contract_end->toDateString() < $day => 'EXPIRED',
            $this->inForceAmong($price, $day)?->id === $version->id => 'CURRENT',
            default => 'SUPERSEDED',
        };
    }

    public function describe(NegotiatedPriceVersion $version): string
    {
        $price = $this->dealOf($version);

        return "{$version->currency->value} {$version->amount()->toDecimal()} {$price->basis->label()}"
            .($version->minimum_quantity > 0 ? ", minimum {$version->minimum_quantity}" : '')
            .($version->discount_percent !== null ? ", less {$version->discount_percent} %" : '')." (deal v{$version->version})";
    }

    /** inForce() from the deal's loaded versions when they are loaded (a page listing deals), else from the database. */
    private function inForceAmong(NegotiatedPrice $price, string $day): ?NegotiatedPriceVersion
    {
        if (! $price->relationLoaded('versions')) {
            return $this->tenants->runAs(Tenant::query()->findOrFail($price->tenant_id), fn () => $this->inForce($price, $day));
        }
        if (! $price->covers($day)) {
            return null;
        }
        $latest = $price->versions->filter(fn (NegotiatedPriceVersion $v) => $v->status !== VersionStatus::Draft && $v->effective_from !== null && $v->effective_from->toDateString() <= $day)
            ->sortByDesc(fn (NegotiatedPriceVersion $v) => [$v->effective_from->toDateString(), $v->version])->first();

        return $latest?->status === VersionStatus::Published ? $latest : null;
    }

    /** The deal of a version, read in the version's own tenant. */
    private function dealOf(NegotiatedPriceVersion $version): NegotiatedPrice
    {
        if ($version->relationLoaded('negotiatedPrice') && $version->negotiatedPrice !== null) {
            return $version->negotiatedPrice;
        }

        return $this->tenants->runAs(Tenant::query()->findOrFail($version->tenant_id), fn () => NegotiatedPrice::query()->findOrFail($version->negotiated_price_id));
    }

    private function assertPublishable(NegotiatedPriceVersion $version, string $from): void
    {
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A negotiated price takes effect today or later: past invoices keep the price they were issued with.');
        }
        if ($version->status !== VersionStatus::Draft) {
            throw new RuntimeException("Version {$version->version} is {$version->status->value}: only a draft can be published.");
        }
        $price = $version->negotiatedPrice;
        if (! $price->covers($from)) {
            throw new RuntimeException('A negotiated price starts within its contract window ('.$price->contract_start->toDateString().' to '.($price->contract_end?->toDateString() ?? 'open').').');
        }
        $latest = NegotiatedPriceVersion::query()->where('negotiated_price_id', $price->id)->where('status', '<>', VersionStatus::Draft->value)->max('effective_from');
        if ($latest !== null && $from <= substr((string) $latest, 0, 10)) {
            throw new RuntimeException('A new negotiated version starts after the previous one ('.substr((string) $latest, 0, 10).').');
        }
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
