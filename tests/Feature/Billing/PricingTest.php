<?php

use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Entitlements\Enums\Capability;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7: prices by market. A price is one published plan version × market × interval; its amounts are versions
| published from a date and never changed. Markets are independent: changing INR never touches USD or EUR. A
| subscription's billing terms pin one price version; a new price never re-prices a subscriber on its own. Price
| never reaches the entitlement engine.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    [$this->operator, $this->verifier] = billingOperators();
    $this->catalog = app(BillingCatalog::class);
    $this->growth = publishedPlan($this->operator, 'growth', ['leave' => true, 'payroll' => true], '2027-04-01');
    $this->in = billingMarket($this->operator, 'IN-TEST', 'INR', 'MARKEDGE-IN-TEST', 'en_IN', ['IN']);
    $this->us = billingMarket($this->operator, 'US-TEST', 'USD', 'MARKEDGE-US-TEST', 'en_US', ['US']);
    $this->eu = billingMarket($this->operator, 'EU-TEST', 'EUR', 'MARKEDGE-EU-TEST', 'de_DE', ['DE', 'FR']);
    $this->jp = billingMarket($this->operator, 'JP-TEST', 'JPY', 'MARKEDGE-JP-TEST', 'ja_JP', ['JP']);
});

function publishPrice($catalog, $planVersion, $market, string $amount, string $from, $operator, string $interval = 'month', string $basis = 'per_active_employee'): PlanPriceVersion
{
    $price = \App\Domain\Billing\Models\PlanPrice::query()->where(['plan_version_id' => $planVersion->id, 'market_id' => $market->id, 'interval' => $interval])->first()
        ?? $catalog->createPrice($planVersion, $market, $interval, $basis, 'Fictional price', $operator);

    return $catalog->publishPriceVersion($catalog->draftPriceVersion($price, $amount, 'Fictional amount', $operator), $from, 'Fictional publication', $operator);
}

it('prices each market in its own currency and precision, independently versioned', function () {
    $inr = publishPrice($this->catalog, $this->growth, $this->in, '199.00', '2027-04-01', $this->operator);
    $usd = publishPrice($this->catalog, $this->growth, $this->us, '4.50', '2027-04-01', $this->operator);
    $eur = publishPrice($this->catalog, $this->growth, $this->eu, '4.00', '2027-04-01', $this->operator);
    $jpy = publishPrice($this->catalog, $this->growth, $this->jp, '500', '2027-04-01', $this->operator);
    expect([$inr->currency->value, $inr->unit_amount_minor, $usd->currency->value, $usd->unit_amount_minor, $eur->unit_amount_minor, $jpy->unit_amount_minor])
        ->toBe(['INR', 19900, 'USD', 450, 400, 500])
        ->and(fn () => $this->catalog->draftPriceVersion(\App\Domain\Billing\Models\PlanPrice::query()->findOrFail($jpy->plan_price_id), '500.5', 'Yen has no decimals', $this->operator))
        ->toThrow(RuntimeException::class, 'decimal places');

    // A new INR price from May: the USD and EUR prices and the April INR price do not move.
    $this->travelTo('2027-04-10 09:00:00');
    $inr2 = publishPrice($this->catalog, $this->growth, $this->in, '249.00', '2027-05-01', $this->operator);
    $onSale = fn ($market, $day) => $this->catalog->catalogue($market, $day)->map(fn ($r) => [$r['amount']->currency->value, $r['amount']->toDecimal(), $r['version']->version])->all();
    expect($onSale($this->in, '2027-04-30'))->toBe([['INR', '199.00', 1]])
        ->and($onSale($this->in, '2027-05-01'))->toBe([['INR', '249.00', 2]])
        ->and($onSale($this->us, '2027-05-01'))->toBe([['USD', '4.50', 1]])
        ->and($onSale($this->eu, '2027-05-01'))->toBe([['EUR', '4.00', 1]])
        ->and($inr->fresh()->unit_amount_minor)->toBe(19900)
        ->and(fn () => $inr->fresh()->forceFill(['unit_amount_minor' => 1])->save())->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $inr->fresh()->forceFill(['currency' => 'USD'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $inr2->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('publishes only today or later, after the previous version, for a published plan version; one draft at a time', function () {
    $price = $this->catalog->createPrice($this->growth, $this->in, 'year', 'flat', 'Annual flat price', $this->operator);
    $draft = $this->catalog->draftPriceVersion($price, '20000', 'Draft', $this->operator);
    expect(fn () => $this->catalog->draftPriceVersion($price, '1', 'Second draft', $this->operator))->toThrow(RuntimeException::class, 'already has a draft')
        ->and(fn () => $this->catalog->publishPriceVersion($draft, '2027-03-31', 'Back-dated', $this->operator))->toThrow(RuntimeException::class, 'today or later')
        ->and(fn () => $this->catalog->draftPriceVersion($price, '-1', 'Negative', $this->operator))->toThrow(RuntimeException::class);
    $this->catalog->publishPriceVersion($draft, '2027-06-01', 'June start', $this->operator);
    $next = $this->catalog->draftPriceVersion($price, '21000', 'Next version', $this->operator);
    expect(fn () => $this->catalog->publishPriceVersion($next, '2027-06-01', 'Same day', $this->operator))->toThrow(RuntimeException::class, 'after the previous')
        ->and(fn () => $this->catalog->createPrice($this->growth, $this->in, 'year', 'flat', 'Duplicate', $this->operator))->toThrow(RuntimeException::class, 'already has')
        ->and(fn () => $this->catalog->createPrice($this->growth, $this->in, 'week', 'flat', 'Bad interval', $this->operator))->toThrow(RuntimeException::class)
        ->and(fn () => $this->catalog->createMarket('IN-TEST', 'Again', 'INR', ['IN'], 'X1', 'en_IN', 'Duplicate code', $this->operator))->toThrow(RuntimeException::class)
        ->and(fn () => $this->catalog->createMarket('XX-TEST', 'Bad currency', 'XYZ', ['IN'], 'X1', 'en_IN', 'Unknown currency', $this->operator))->toThrow(RuntimeException::class)
        ->and(fn () => $this->in->fresh()->forceFill(['currency' => 'USD'])->save())->toThrow(RuntimeException::class, 'keeps its code and currency');
});

it('pins a price version to a subscription and answers what applied on a day; later prices never re-price it', function () {
    $tenant = provisionTenant('Alpha');
    $sub = app(CommercialSubscriptions::class)->start($tenant, $this->growth, '2027-04-01', null, 'Contract', $this->operator);
    $april = publishPrice($this->catalog, $this->growth, $this->in, '199.00', '2027-04-01', $this->operator);
    $terms = app(BillingTerms::class);
    expect(fn () => $terms->set($sub, $april, '2027-04-01', 'No profile yet', $this->operator))->toThrow(RuntimeException::class, 'no billing profile');
    billingProfile($tenant, $this->in, $this->operator);
    $usd = publishPrice($this->catalog, $this->growth, $this->us, '4.50', '2027-04-01', $this->operator);
    expect(fn () => $terms->set($sub, $usd, '2027-04-01', 'Wrong market', $this->operator))->toThrow(RuntimeException::class, 'billed in IN-TEST');
    $terms->set($sub, $april, '2027-04-01', 'Signed at the April price', $this->operator, 'DEAL-1');
    expect($terms->set($sub, $april, '2027-04-01', 'Same terms again', $this->operator)->plan_price_version_id)->toBe($april->id);

    // A new price from May reaches new terms only; the subscriber keeps 199.00 until an operator re-pins.
    $this->travelTo('2027-04-15 09:00:00');
    $may = publishPrice($this->catalog, $this->growth, $this->in, '249.00', '2027-05-01', $this->operator);
    $applies = fn (string $day) => (($a = $terms->applicableOn($sub, $day)) === null ? null : [$a['unit_amount']->toDecimal(), $a['unit_amount']->currency->value, $a['consistent']]);
    expect($applies('2027-03-31'))->toBeNull()->and($applies('2027-04-01'))->toBe(['199.00', 'INR', true])->and($applies('2027-06-01'))->toBe(['199.00', 'INR', true])
        ->and(fn () => $terms->set($sub, $april, '2027-05-01', 'Old price after the new one starts', $this->operator))->toThrow(RuntimeException::class, 'not the version of this price on sale');
    $terms->set($sub, $may, '2027-07-01', 'Renewal at the May price', $this->operator);
    expect($applies('2027-06-30'))->toBe(['199.00', 'INR', true])->and($applies('2027-07-01'))->toBe(['249.00', 'INR', true]);

    // A plan change without a new pin is reported as inconsistent, never silently re-priced.
    $starter = publishedPlan($this->operator, 'starter', ['leave' => true], '2027-04-15');
    app(CommercialSubscriptions::class)->changePlan($sub, $starter, '2027-08-01', 'Downgrade', $this->operator);
    expect($applies('2027-08-01'))->toBe(['249.00', 'INR', false])
        ->and(fn () => $terms->set($sub, $may, '2027-08-01', 'Price of another plan', $this->operator))->toThrow(RuntimeException::class, 'not on');
});

it('never lets price reach entitlement: the same decisions with and without prices and terms', function () {
    $tenant = provisionTenant('Beta');
    app(CommercialSubscriptions::class)->start($tenant, $this->growth, '2027-04-01', null, 'Contract', $this->operator);
    actAsTenant($tenant);
    $before = collect(Capability::cases())->map(fn (Capability $c) => app(Entitlements::class)->evaluate($c)->toArray())->all();
    $price = publishPrice($this->catalog, $this->growth, $this->in, '0.00', '2027-04-01', $this->operator, 'month', 'flat');   // even a zero price
    billingProfile($tenant, $this->in, $this->operator);
    app(BillingTerms::class)->set(\App\Domain\Subscriptions\Models\TenantSubscription::query()->sole(), $price, '2027-04-01', 'Free terms', $this->operator);
    app(\App\Domain\Entitlements\Services\EntitlementStateStore::class)->forget($tenant->id);
    actAsTenant($tenant);
    expect(collect(Capability::cases())->map(fn (Capability $c) => app(Entitlements::class)->evaluate($c)->toArray())->all())->toBe($before);
});
