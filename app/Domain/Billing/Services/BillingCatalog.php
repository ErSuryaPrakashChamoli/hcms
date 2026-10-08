<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\PricingBasis;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Models\User;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use NumberFormatter;
use RuntimeException;

/**
 * SaaS.7: markets and prices (platform catalogue). A price belongs to one published plan version, one market and
 * one interval; its amounts are versions (draft → published → retired), each published from a date (today or
 * later, after the previous one) and never changed. Markets and prices are independent: each market has its own
 * prices in its own currency, set by the business, never derived from another market's price by an exchange rate.
 * A new price version never re-prices an existing subscriber (billing terms pin a version; B-15).
 *
 * SaaS.7 completion: a per-employee unit amount is per employee per month (B-1) with an optional minimum quantity;
 * publication is maker-checker (B-13): requestPublication() by one operator, executed only when another approves.
 * No market or price is created by SaaS.7; the amounts are a business decision (B-4, pending).
 */
final class BillingCatalog
{
    public function __construct(private readonly BillingAudit $audit, private readonly FinancialApprovals $approvals) {}

    /** @param  list<string>  $countries */
    public function createMarket(string $code, string $name, string $currency, array $countries, string $supplierEntity, string $locale, string $reason, User $actor): BillingMarket
    {
        OperatorChange::assert($actor, $reason, 'billing markets');
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z0-9_-]{2,16}$/', $code) !== 1) {
            throw new RuntimeException('A market code is 2 to 16 capital letters, digits, dash or underscore.');
        }
        $currency = $this->currency($currency);
        [$countries, $supplierEntity, $locale, $name] = $this->marketDetails($countries, $supplierEntity, $locale, $name);
        if (BillingMarket::query()->where('code', $code)->exists()) {
            throw new RuntimeException("The market {$code} already exists.");
        }
        $market = BillingMarket::query()->create(['code' => $code, 'name' => $name, 'currency' => $currency, 'countries' => $countries,
            'supplier_entity' => $supplierEntity, 'locale' => $locale, 'status' => 'active', 'reason' => $reason, 'created_by' => $actor->id]);
        $this->audit->platform(AuditAction::BillingMarketCreated, 'billing', $market, "Market {$market->label()}",
            [['field' => 'market', 'before' => 'none', 'after' => $market->label()]], $reason, $actor,
            ['market_id' => $market->id, 'currency' => $currency->value, 'countries' => $countries, 'supplier_entity' => $supplierEntity, 'locale' => $locale]);

        return $market;
    }

    /** @param  list<string>  $countries */
    public function updateMarket(BillingMarket $market, string $name, array $countries, string $supplierEntity, string $locale, string $reason, User $actor): BillingMarket
    {
        OperatorChange::assert($actor, $reason, 'billing markets');
        [$countries, $supplierEntity, $locale, $name] = $this->marketDetails($countries, $supplierEntity, $locale, $name);
        $before = $market->only(['name', 'countries', 'supplier_entity', 'locale']);
        $market->forceFill(['name' => $name, 'countries' => $countries, 'supplier_entity' => $supplierEntity, 'locale' => $locale])->save();
        $changes = [];
        foreach (['name' => $name, 'countries' => $countries, 'supplier_entity' => $supplierEntity, 'locale' => $locale] as $field => $after) {
            if ($before[$field] !== $after) {
                $changes[] = ['field' => $field, 'before' => is_array($before[$field]) ? implode(', ', $before[$field]) : $before[$field], 'after' => is_array($after) ? implode(', ', $after) : $after];
            }
        }
        $this->audit->platform(AuditAction::BillingMarketUpdated, 'billing', $market, "Market {$market->label()}", $changes, $reason, $actor, ['market_id' => $market->id]);

        return $market;
    }

    public function createPrice(PlanVersion $planVersion, BillingMarket $market, string $interval, string $basis, string $reason, User $actor): PlanPrice
    {
        OperatorChange::assert($actor, $reason, 'prices');
        $interval = BillingInterval::tryFrom($interval) ?? throw new RuntimeException('The interval is month or year.');
        $basis = PricingBasis::tryFrom($basis) ?? throw new RuntimeException('The basis is flat or per_active_employee.');
        $planVersion = PlanVersion::query()->with('plan')->findOrFail($planVersion->id);
        if ($planVersion->status !== VersionStatus::Published) {
            throw new RuntimeException("{$planVersion->label()} is {$planVersion->status->value}: only a published plan version can be priced.");
        }
        if (PlanPrice::query()->where(['plan_version_id' => $planVersion->id, 'market_id' => $market->id, 'interval' => $interval])->exists()) {
            throw new RuntimeException("{$planVersion->label()} already has a {$interval->value} price in {$market->code}: draft a new version of it.");
        }
        $price = PlanPrice::query()->create(['plan_version_id' => $planVersion->id, 'market_id' => $market->id, 'interval' => $interval, 'basis' => $basis,
            'reason' => $reason, 'created_by' => $actor->id]);
        $this->audit->platform(AuditAction::PriceCreated, 'billing', $price, $this->priceLabel($price),
            [['field' => 'price', 'before' => 'none', 'after' => "{$basis->label()}, {$interval->label()}"]], $reason, $actor,
            ['plan_price_id' => $price->id, 'plan_version_id' => $planVersion->id, 'market_id' => $market->id, 'interval' => $interval->value, 'basis' => $basis->value]);

        return $price;
    }

    /**
     * $amount in the market currency's major unit ("1499.00", "1250" JPY); exact to its minor units. For a
     * per-employee price it is per employee per month; $minimumQuantity is the optional floor of billable employees.
     */
    public function draftPriceVersion(PlanPrice $price, string $amount, string $reason, User $actor, int $minimumQuantity = 0): PlanPriceVersion
    {
        OperatorChange::assert($actor, $reason, 'prices');
        if ($minimumQuantity < 0 || $minimumQuantity > 1000000) {
            throw new RuntimeException('The minimum quantity is 0 (none) to 1,000,000 employees.');
        }
        if ($minimumQuantity > 0 && $price->basis !== PricingBasis::PerActiveEmployee) {
            throw new RuntimeException('A minimum quantity applies only to a per-employee price.');
        }

        return DB::transaction(function () use ($price, $amount, $reason, $actor, $minimumQuantity) {
            $price = PlanPrice::query()->with('market')->lockForUpdate()->findOrFail($price->id);
            if (PlanPriceVersion::query()->where(['plan_price_id' => $price->id, 'status' => VersionStatus::Draft])->exists()) {
                throw new RuntimeException('This price already has a draft version: publish or retire it first.');
            }
            $money = $this->amount($amount, $price->market->currency);
            $version = PlanPriceVersion::query()->create(['plan_price_id' => $price->id, 'version' => (int) PlanPriceVersion::query()->where('plan_price_id', $price->id)->max('version') + 1,
                'status' => VersionStatus::Draft, 'currency' => $money->currency, 'unit_amount_minor' => $money->minor, 'minimum_quantity' => $minimumQuantity,
                'reason' => $reason, 'created_by' => $actor->id]);
            $this->audit->platform(AuditAction::PriceVersionDrafted, 'billing', $version, "{$this->priceLabel($price)} v{$version->version}",
                [['field' => 'unit_amount', 'before' => 'none', 'after' => "{$money->currency->value} {$money->toDecimal()}"],
                    ['field' => 'minimum_quantity', 'before' => null, 'after' => $minimumQuantity]], $reason, $actor,
                ['plan_price_id' => $price->id, 'unit_amount_minor' => $money->minor, 'currency' => $money->currency->value, 'minimum_quantity' => $minimumQuantity]);

            return $version;
        });
    }

    /** Asks for a draft to go on sale from $effectiveFrom (B-13: published only when another operator approves). */
    public function requestPublication(PlanPriceVersion $version, string $effectiveFrom, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'prices');
        $from = $this->day($effectiveFrom);
        $version = PlanPriceVersion::query()->findOrFail($version->id);
        $price = PlanPrice::query()->with('planVersion.plan', 'market')->findOrFail($version->plan_price_id);
        $this->assertPublishable($price, $version, $from);
        $amount = "{$version->currency->value} {$version->amount()->toDecimal()}";

        return $this->approvals->request(ApprovalAction::PricePublication, $version, null,
            ['plan_price_version_id' => $version->id, 'price' => $this->priceLabel($price), 'version' => $version->version, 'unit_amount' => $amount,
                'minimum_quantity' => $version->minimum_quantity, 'effective_from' => $from],
            ['status' => 'draft', 'on_sale' => ($current = $this->versionOnSale($price, $from)) === null ? 'none' : "v{$current->version} {$current->currency->value} {$current->amount()->toDecimal()}"],
            ['status' => 'published', 'on_sale' => "v{$version->version} {$amount} from {$from}"],
            "{$version->id}:{$from}", $reason, $maker);
    }

    /** Executes an approved publication (called by the approval desk, inside its transaction). */
    public function executePublication(FinancialApproval $approval): PlanPriceVersion
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::PricePublication);
        $from = (string) $approval->payload['effective_from'];
        $checker = User::query()->findOrFail($approval->checker_id);
        $price = PlanPrice::query()->with('planVersion.plan', 'market')->lockForUpdate()->findOrFail(PlanPriceVersion::query()->findOrFail($approval->subject_id)->plan_price_id);
        $locked = PlanPriceVersion::query()->lockForUpdate()->findOrFail($approval->subject_id);
        $this->assertPublishable($price, $locked, $from);
        $locked->forceFill(['status' => VersionStatus::Published, 'effective_from' => $from, 'published_by' => $checker->id, 'published_at' => now()])->save();
        $this->audit->platform(AuditAction::PriceVersionPublished, 'billing', $locked, "{$this->priceLabel($price)} v{$locked->version}",
            [['field' => 'status', 'before' => 'draft', 'after' => 'published'], ['field' => 'unit_amount', 'before' => null, 'after' => "{$locked->currency->value} {$locked->amount()->toDecimal()}"]],
            (string) $approval->checker_reason, $checker, ['plan_price_id' => $price->id, 'plan_price_version_id' => $locked->id, 'unit_amount_minor' => $locked->unit_amount_minor,
                'currency' => $locked->currency->value, 'minimum_quantity' => $locked->minimum_quantity, 'approval' => $approval->reference, 'maker_id' => $approval->maker_id,
                'checker_id' => $approval->checker_id, 'correlation_id' => $approval->correlation_key], $from);
        $this->approvals->executed($approval, "{$this->priceLabel($price)} v{$locked->version} on sale from {$from}");

        return $locked;
    }

    private function assertPublishable(PlanPrice $price, PlanPriceVersion $version, string $from): void
    {
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A price takes effect today or later: past invoices keep the price they were issued with.');
        }
        if ($version->status !== VersionStatus::Draft) {
            throw new RuntimeException("Version {$version->version} is {$version->status->value}: only a draft can be published.");
        }
        if ($price->planVersion->status !== VersionStatus::Published) {
            throw new RuntimeException("{$price->planVersion->label()} is no longer published: it cannot get a new price.");
        }
        $latest = PlanPriceVersion::query()->where('plan_price_id', $price->id)->where('status', '<>', VersionStatus::Draft->value)->whereNotNull('effective_from')->max('effective_from');
        if ($latest !== null && $from <= substr((string) $latest, 0, 10)) {
            throw new RuntimeException('A new price version starts after the previous one ('.substr((string) $latest, 0, 10).').');
        }
    }

    /** A published version stops being on sale (terms already pinned to it keep it); a draft is abandoned. */
    public function retirePriceVersion(PlanPriceVersion $version, string $reason, User $actor): PlanPriceVersion
    {
        OperatorChange::assert($actor, $reason, 'prices');

        return DB::transaction(function () use ($version, $reason, $actor) {
            $locked = PlanPriceVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status === VersionStatus::Retired) {
                return $locked;
            }
            $before = $locked->status->value;
            $locked->forceFill(['status' => VersionStatus::Retired, 'retired_by' => $actor->id, 'retired_at' => now()])->save();
            $price = PlanPrice::query()->findOrFail($locked->plan_price_id);
            $this->audit->platform(AuditAction::PriceVersionRetired, 'billing', $locked, "{$this->priceLabel($price)} v{$locked->version}",
                [['field' => 'status', 'before' => $before, 'after' => 'retired']], $reason, $actor, ['plan_price_id' => $price->id, 'plan_price_version_id' => $locked->id]);

            return $locked;
        });
    }

    /** The published version of $price on sale on $day: the latest one started, unless retired. */
    public function versionOnSale(PlanPrice $price, string $day): ?PlanPriceVersion
    {
        $version = PlanPriceVersion::query()->where('plan_price_id', $price->id)->where('status', '<>', VersionStatus::Draft->value)
            ->whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->first();

        return $version?->status === VersionStatus::Published ? $version : null;
    }

    /**
     * What PeopleOS costs in a market on a day: every priced plan version with the version on sale (for a future
     * pricing page; no page is built in SaaS.7).
     *
     * @return Collection<int, array{price: PlanPrice, version: PlanPriceVersion, amount: Money}>
     */
    public function catalogue(BillingMarket $market, string $day): Collection
    {
        return PlanPrice::query()->with('planVersion.plan')->where('market_id', $market->id)->orderBy('id')->get()
            ->map(fn (PlanPrice $price) => ($v = $this->versionOnSale($price, $day)) === null ? null : ['price' => $price, 'version' => $v, 'amount' => $v->amount()])
            ->filter()->values();
    }

    /**
     * The price matrix on $day: every published plan version × market × interval, with the version on sale, or
     * NO_PRICE_CONFIGURED (never zero, another market's price or a converted one), or SCHEDULED when only a future
     * version exists.
     *
     * @return list<array{plan_version: PlanVersion, market: BillingMarket, interval: BillingInterval, price: ?PlanPrice, version: ?PlanPriceVersion, state: string, next: ?PlanPriceVersion}>
     */
    public function matrix(string $day): array
    {
        // Three queries whatever the size: prices with their versions, published plan versions, markets.
        $prices = PlanPrice::query()->with('versions')->get()->keyBy(fn (PlanPrice $p) => "{$p->plan_version_id}|{$p->market_id}|{$p->interval->value}");
        $markets = BillingMarket::query()->orderBy('code')->get();
        $rows = [];
        foreach (PlanVersion::query()->with('plan')->where('status', VersionStatus::Published)->orderBy('plan_id')->orderBy('version')->get() as $planVersion) {
            foreach ($markets as $market) {
                foreach (BillingInterval::cases() as $interval) {
                    $price = $prices->get("{$planVersion->id}|{$market->id}|{$interval->value}");
                    $started = $price?->versions->filter(fn (PlanPriceVersion $v) => $v->status !== VersionStatus::Draft && $v->effective_from !== null && $v->effective_from->toDateString() <= $day)
                        ->sortByDesc(fn (PlanPriceVersion $v) => $v->effective_from->toDateString())->first();
                    $onSale = $started?->status === VersionStatus::Published ? $started : null;
                    $next = $price?->versions->filter(fn (PlanPriceVersion $v) => $v->status === VersionStatus::Published && $v->effective_from->toDateString() > $day)
                        ->sortBy(fn (PlanPriceVersion $v) => $v->effective_from->toDateString())->first();
                    $rows[] = ['plan_version' => $planVersion, 'market' => $market, 'interval' => $interval, 'price' => $price, 'version' => $onSale, 'next' => $next,
                        'state' => $onSale !== null ? 'CURRENT' : ($next !== null ? 'SCHEDULED' : 'NO_PRICE_CONFIGURED')];
                }
            }
        }

        return $rows;
    }

    /** DRAFT, PENDING_APPROVAL, SCHEDULED, CURRENT, SUPERSEDED or RETIRED on $day. */
    public function versionState(PlanPriceVersion $version, ?string $day = null, bool $pendingApproval = false): string
    {
        $day ??= now()->toDateString();

        return match (true) {
            $version->status === VersionStatus::Draft => $pendingApproval ? 'PENDING_APPROVAL' : 'DRAFT',
            $version->status === VersionStatus::Retired => 'RETIRED',
            $version->effective_from->toDateString() > $day => 'SCHEDULED',
            $this->versionOnSale(PlanPrice::query()->findOrFail($version->plan_price_id), $day)?->id === $version->id => 'CURRENT',
            default => 'SUPERSEDED',
        };
    }

    /** The last day a published version is on sale: the day before the next version starts (null while open). */
    public function versionEnds(PlanPriceVersion $version): ?string
    {
        if ($version->effective_from === null) {
            return null;
        }
        $next = PlanPriceVersion::query()->where('plan_price_id', $version->plan_price_id)->where('status', '<>', VersionStatus::Draft->value)
            ->whereDate('effective_from', '>', $version->effective_from->toDateString())->min('effective_from');

        return $next === null ? null : Carbon::parse(substr((string) $next, 0, 10))->subDay()->toDateString();
    }

    /** @return array<int, string> published plan versions, for choosing what a price or a deal is for */
    public function publishedPlanVersions(): array
    {
        return PlanVersion::query()->with('plan')->where('status', VersionStatus::Published)->get()->mapWithKeys(fn (PlanVersion $v) => [$v->id => $v->label()])->all();
    }

    /** The label of a published plan version (what a price or a deal may be for), refused otherwise. */
    public function publishedPlanLabel(int $planVersionId): string
    {
        $planVersion = PlanVersion::query()->with('plan')->findOrFail($planVersionId);
        if ($planVersion->status !== VersionStatus::Published) {
            throw new RuntimeException("{$planVersion->label()} is {$planVersion->status->value}: only a published plan version can be priced.");
        }

        return $planVersion->label();
    }

    public function planLabel(int $planVersionId): string
    {
        return PlanVersion::query()->with('plan')->findOrFail($planVersionId)->label();
    }

    public function priceLabel(PlanPrice $price): string
    {
        $price->loadMissing('planVersion.plan', 'market');

        return "{$price->planVersion->label()} · {$price->market->code} · {$price->basis->label()} · {$price->interval->label()}";
    }

    private function currency(string $code): Currency
    {
        try {
            return Currency::of(strtoupper(trim($code)));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    private function amount(string $amount, Currency $currency): Money
    {
        try {
            $money = Money::parse($amount, $currency);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        if ($money->isNegative()) {
            throw new RuntimeException('A price is zero or more.');
        }

        return $money;
    }

    /** @param  list<string>  $countries  @return array{0: list<string>, 1: string, 2: string, 3: string} */
    private function marketDetails(array $countries, string $supplierEntity, string $locale, string $name): array
    {
        $countries = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $countries)));
        if ($countries === [] || array_filter($countries, fn (string $c) => preg_match('/^[A-Z]{2}$/', $c) !== 1) !== []) {
            throw new RuntimeException('List the ISO 3166-1 countries the market is meant for (e.g. IN, US).');
        }
        $supplierEntity = strtoupper(trim($supplierEntity));
        if (preg_match('/^[A-Z0-9_-]{2,32}$/', $supplierEntity) !== 1) {
            throw new RuntimeException('The supplier entity is a code of 2 to 32 capital letters, digits, dash or underscore.');
        }
        $locale = trim($locale);
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1 || (new NumberFormatter($locale, NumberFormatter::CURRENCY))->getLocale() === null) {
            throw new RuntimeException('The display locale is like en_IN, en_US, de_DE.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('A market needs a name of at most 120 characters.');
        }

        return [$countries, $supplierEntity, $locale, $name];
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
