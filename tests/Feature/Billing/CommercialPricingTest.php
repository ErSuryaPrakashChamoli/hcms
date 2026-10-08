<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\NegotiatedPrice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\NegotiatedPrices;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Models\TenantSubscription;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Support\Tenancy\TenantContext;
use Brick\Math\RoundingMode;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7 configuration: prices are data, customer deals are data, Markedge policy is data. Precedence is CUSTOMER
| AGREED TERMS > PLAN PRICE VERSION > NO PRICE; nothing is ever billed at zero, at another market's price or after a
| conversion; published versions never change; historical invoices and pinned subscribers never move. Every amount
| here is fictional.
*/

/** A customer's deal (created when $deal is null) with one version published by maker-checker from $from. */
function agreedVersion(?NegotiatedPrice $deal, TenantSubscription $subscription, PlanVersion $plan, BillingMarket $market, string $interval, string $basis, string $amount,
    string $from, User $maker, User $checker, int $minimum = 0, ?string $discount = null, ?string $contractEnd = null): NegotiatedPriceVersion
{
    $deals = app(NegotiatedPrices::class);
    $deal ??= $deals->create($subscription, $plan->id, $market, $interval, $basis, $from, $contractEnd, 'MSA-FICTIONAL-'.$subscription->id, 'Fictional deal', 'Deal agreed with the customer', $maker);
    $version = $deals->draftVersion($deal, $amount, $minimum, $discount, null, 'Agreed amount', $maker);
    approveAs($deals->requestPublication($version, $from, 'Publish the agreed amount', $maker), $checker);

    return app(TenantContext::class)->runAs(Tenant::query()->findOrFail($subscription->tenant_id), fn () => $version->fresh());
}

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    [$this->op, $this->checker] = [$this->setup['operator'], $this->setup['verifier']];
    $this->in = $this->setup['market'];
    $this->growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    $this->standard = pepmPrice($this->growth, $this->in, 'month', '100.00', '2027-04-01', $this->op, $this->checker);
    $this->subs = app(CommercialSubscriptions::class);
    $this->terms = app(BillingTerms::class);
    $this->deals = app(NegotiatedPrices::class);
    $this->run = app(BillingPeriods::class);
    $this->ctx = app(TenantContext::class);
});

it('bills Client A at its agreed PEPM with a minimum: agreed terms take precedence, need maker-checker, and touch no other customer or the catalogue (critical 4, 13, 14)', function () {
    $alpha = provisionTenant('Alpha');
    $beta = provisionTenant('Beta');
    billingProfile($alpha, $this->in, $this->op);
    billingProfile($beta, $this->in, $this->op);
    $subA = $this->subs->start($alpha, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $subB = $this->subs->start($beta, $this->growth, '2027-04-01', null, 'Contract', $this->op);

    $deal = $this->deals->create($subA, $this->growth->id, $this->in, 'month', 'per_active_employee', '2027-04-01', null, 'MSA-ALPHA-001', 'Fictional Client A deal', 'Deal agreed with Alpha', $this->op);
    $draft = $this->deals->draftVersion($deal, '80.00', 250, null, $this->standard, 'Agreed amount', $this->op);
    $request = $this->deals->requestPublication($draft, '2027-04-01', 'Publish the agreed amount', $this->op);
    expect(fn () => approveAs($request, $this->op))->toThrow(RuntimeException::class, 'Maker-checker')            // the maker cannot approve
        ->and($this->deals->agreedFor($subA, $this->growth->id, $this->in->id, BillingInterval::Month, '2027-04-01'))->toBeNull();   // a draft never applies
    approveAs($request, $this->checker);
    $agreed = $this->ctx->runAs($alpha, fn () => $draft->fresh());

    // Precedence: the agreed price for Alpha, the catalogue for Beta.
    expect($this->terms->priceFor($subA, BillingInterval::Month, '2027-04-01'))->toMatchArray(['source' => 'negotiated'])
        ->and($this->terms->priceFor($subA, BillingInterval::Month, '2027-04-01')['version']->id)->toBe($agreed->id)
        ->and($this->terms->priceFor($subB, BillingInterval::Month, '2027-04-01')['version']->id)->toBe($this->standard->id)
        ->and(fn () => $this->terms->set($subA, $this->standard, '2027-04-01', 'Wrong price', $this->op))->toThrow(RuntimeException::class, 'Agreed terms take precedence')
        ->and(fn () => $this->terms->set($subB, $agreed, '2027-04-01', 'Another customer\'s deal', $this->op))->toThrow(RuntimeException::class, 'another subscription');
    $termA = $this->terms->set($subA, $agreed, '2027-04-01', 'Order form', $this->op);
    $this->terms->set($subB, $this->standard, '2027-04-01', 'Order form', $this->op);
    expect([$termA->source(), $termA->plan_price_version_id, $termA->negotiated_price_version_id])->toBe(['negotiated', null, $agreed->id])
        ->and($this->terms->applicableOn($subA, '2027-04-15'))->toMatchArray(['source' => 'negotiated']);

    $this->travelTo('2027-05-02 09:00:00');
    foreach (['2027-03-01', '2027-03-01', '2027-04-10'] as $joined) {
        staff($alpha, $joined);
        staff($beta, $joined);
    }
    $this->run->run($alpha);
    $this->run->run($beta);
    $a = billingPeriodsOf($alpha)->get('monthly_arrears 2027-04-01');
    $b = billingPeriodsOf($beta)->get('monthly_arrears 2027-04-01');
    expect([$a->price_source, $a->negotiated_price_version_id, $a->plan_price_version_id, $a->measured_peak, $a->billed_quantity, $a->amount()->toDecimal()])
        ->toBe(['negotiated', $agreed->id, null, 3, 250, '20000.00'])                     // max(peak 3, agreed minimum 250) × 80.00
        ->and([$b->price_source, $b->plan_price_version_id, $b->billed_quantity, $b->amount()->toDecimal()])->toBe(['standard', $this->standard->id, 3, '300.00']);
    $line = $this->ctx->runAs($alpha, fn () => InvoiceLine::query()->where('invoice_id', $a->invoice_id)->sole());
    expect([$line->negotiated_price_version_id, $line->plan_price_version_id, $line->unit_amount_minor])->toBe([$agreed->id, null, 8000])
        ->and($line->description)->toContain('agreed price');

    // Isolation: the deal is Alpha's alone; nothing is visible without a tenant; the catalogue is untouched.
    expect($this->ctx->runAs($beta, fn () => NegotiatedPrice::query()->count() + NegotiatedPriceVersion::query()->count()))->toBe(0)
        ->and($this->deals->forTenant($beta))->toHaveCount(0)
        ->and(NegotiatedPrice::query()->count())->toBe(0)                                 // fail closed without a tenant
        ->and($this->deals->forTenant($alpha)->sole()->contract_reference)->toBe('MSA-ALPHA-001')
        ->and([PlanPriceVersion::query()->count(), $this->standard->fresh()->unit_amount_minor])->toBe([1, 10000]);
    // Audited on both chains with maker and checker.
    $published = AuditEvent::query()->withoutTenancy()->where('action', AuditAction::NegotiatedPriceVersionPublished)->whereNull('tenant_id')->sole();
    expect($published->metadata['maker_id'])->toBe($this->op->id)->and($published->metadata['checker_id'])->toBe($this->checker->id);
});

it('bills Client B a fixed annual commitment in advance, ends its terms with the contract and then reports NO_PRICE_CONFIGURED instead of guessing', function () {
    $tenant = provisionTenant('Gamma');
    billingProfile($tenant, $this->in, $this->op);
    $sub = $this->subs->start($tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->standard, '2027-04-01', 'Order form', $this->op);
    $annual = agreedVersion(null, $sub, $this->growth, $this->in, 'year', 'flat', '600000.00', '2027-05-01', $this->op, $this->checker, contractEnd: '2028-04-30');   // a fixed annual commitment, entered as it is agreed
    expect(fn () => $this->deals->draftVersion($this->ctx->runAs($tenant, fn () => $annual->negotiatedPrice()->first()), '1.00', 5, null, null, 'Minimum on a fixed deal', $this->op))
        ->toThrow(RuntimeException::class, 'per-employee deal');
    $term = $this->terms->set($sub, $annual, '2027-05-01', 'Annual order form', $this->op);
    expect([$term->interval, $term->effective_to->toDateString(), $term->committed_quantity])->toBe([BillingInterval::Year, '2028-04-30', null]);

    $this->travelTo('2027-06-02 09:00:00');
    staff($tenant, '2027-03-01');
    $this->run->run($tenant);
    $periods = billingPeriodsOf($tenant);
    $advance = $periods->get('annual_advance 2027-05-01');
    expect([$advance->price_source, $advance->billed_quantity, $advance->amount()->toDecimal(), $advance->evidence['method'], $advance->period_end->toDateString()])
        ->toBe(['negotiated', 1, '600000.00', 'flat', '2028-04-30'])                       // the annual amount, once, in advance
        ->and($periods->get('monthly_arrears 2027-04-01')->amount()->toDecimal())->toBe('100.00')
        ->and($periods->has('annual_true_up 2027-05-01'))->toBeFalse();                   // a fixed price has no true-up

    // The contract ended on 30 April 2028 and nothing was pinned after it: May is reported, never billed.
    $this->travelTo('2028-06-02 09:00:00');
    $this->run->run($tenant);
    $may = billingPeriodsOf($tenant)->get('monthly_arrears 2028-05-01');
    expect([$may->status, $may->price_source, $may->amount_minor, $may->invoice_id, $may->billing_term_id])->toBe([BillingPeriod::EXCEPTION, 'none', 0, null, null])
        ->and($may->exception)->toStartWith('NO_PRICE_CONFIGURED: 31 billable days')
        ->and($this->terms->applicableOn($sub, '2028-05-15'))->toBeNull()
        ->and($this->terms->priceFor($sub, BillingInterval::Year, '2028-05-15'))->toMatchArray(['source' => 'none']);

    // A renewal is a new contract window; overlapping windows are refused.
    expect(fn () => $this->deals->create($sub, $this->growth->id, $this->in, 'year', 'flat', '2028-04-01', null, 'MSA-OVERLAP', null, 'Overlapping deal', $this->op))
        ->toThrow(RuntimeException::class, 'in that window');
    $renewal = $this->deals->create($sub, $this->growth->id, $this->in, 'year', 'flat', '2028-07-01', '2029-06-30', 'MSA-RENEWAL', null, 'Renewed deal', $this->op);
    expect($renewal->contract_start->toDateString())->toBe('2028-07-01');
});

it('applies an agreed discount once to the unit, keeps published versions immutable and makes a renegotiation a new version (critical 15, 17)', function () {
    $tenant = provisionTenant('Delta');
    billingProfile($tenant, $this->in, $this->op);
    $sub = $this->subs->start($tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $v1 = agreedVersion(null, $sub, $this->growth, $this->in, 'month', 'per_active_employee', '99.99', '2027-04-01', $this->op, $this->checker, discount: '12.5');
    expect([$v1->amount()->toDecimal(), $v1->netAmount()->toDecimal(), $v1->netAmount(RoundingMode::HalfEven)->toDecimal()])->toBe(['99.99', '87.49', '87.49'])
        ->and($this->deals->state($v1))->toBe('CURRENT');

    // Published versions and the deal itself never change; nothing is deleted.
    $this->ctx->runAs($tenant, function () use ($v1) {
        expect(fn () => $v1->fresh()->forceFill(['unit_amount_minor' => 1])->save())->toThrow(RuntimeException::class, 'immutable')
            ->and(fn () => $v1->fresh()->forceFill(['discount_percent' => '50'])->save())->toThrow(RuntimeException::class, 'immutable')
            ->and(fn () => $v1->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted')
            ->and(fn () => $v1->negotiatedPrice->forceFill(['contract_end' => '2027-12-31'])->save())->toThrow(RuntimeException::class, 'keeps its contract terms');
    });
    expect(fn () => PlanPriceVersion::query()->findOrFail($this->standard->id)->forceFill(['unit_amount_minor' => 1])->save())->toThrow(RuntimeException::class);

    // A renegotiation: version 2 scheduled from June; version 1 stays for May.
    $deal = $this->ctx->runAs($tenant, fn () => $v1->negotiatedPrice()->first());
    $v2 = agreedVersion($deal, $sub, $this->growth, $this->in, 'month', 'per_active_employee', '90.00', '2027-06-01', $this->op, $this->checker);
    expect([$this->deals->state($v1, '2027-05-15'), $this->deals->state($v2, '2027-05-15'), $this->deals->state($v1, '2027-06-01'), $this->deals->state($v2, '2027-06-01')])
        ->toBe(['CURRENT', 'SCHEDULED', 'SUPERSEDED', 'CURRENT']);

    foreach ([['100', null], ['99.99999', null], ['-1', null]] as [$discount]) {
        expect(fn () => $this->deals->draftVersion($deal, '10.00', 0, $discount, null, 'Bad discount', $this->op))->toThrow(RuntimeException::class);
    }
    expect(fn () => $this->deals->draftVersion($deal, '-5.00', 0, null, null, 'Negative', $this->op))->toThrow(RuntimeException::class, 'zero or more')
        ->and(fn () => $this->deals->requestPublication($v2, '2027-07-01', 'Republish', $this->op))->toThrow(RuntimeException::class, 'only a draft');
    $v3 = $this->deals->draftVersion($deal, '85.00', 0, null, null, 'Next round', $this->op);
    expect(fn () => $this->deals->draftVersion($deal, '84.00', 0, null, null, 'Second draft', $this->op))->toThrow(RuntimeException::class, 'already has a draft')
        ->and(fn () => $this->deals->requestPublication($v3, '2027-05-15', 'Before version 2', $this->op))->toThrow(RuntimeException::class, 'after the previous one')
        ->and(fn () => $this->deals->requestPublication($v3, '2027-03-31', 'In the past', $this->op))->toThrow(RuntimeException::class, 'today or later');
});

it('keeps markets and currencies independent: no price is converted or borrowed from another market (critical 5, 6, 7)', function () {
    $us = billingMarket($this->op, 'US-TEST', 'USD', 'MARKEDGE-IN-TEST', 'en_US', ['US']);
    $catalog = app(BillingCatalog::class);
    $states = fn () => collect($catalog->matrix(now()->toDateString()))->filter(fn ($r) => $r['plan_version']->id === $this->growth->id)
        ->mapWithKeys(fn ($r) => ["{$r['market']->code} {$r['interval']->value}" => $r['state']])->all();
    expect($states())->toBe(['IN-TEST month' => 'CURRENT', 'IN-TEST year' => 'NO_PRICE_CONFIGURED', 'US-TEST month' => 'NO_PRICE_CONFIGURED', 'US-TEST year' => 'NO_PRICE_CONFIGURED']);

    $tenant = provisionTenant('Echo');
    billingProfile($tenant, $us, $this->op, ['country' => 'US', 'subdivision' => 'US-CA', 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    $sub = $this->subs->start($tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    expect($this->terms->priceFor($sub, BillingInterval::Month, '2027-04-01'))->toMatchArray(['source' => 'none', 'version' => null])
        ->and($this->terms->priceFor($sub, BillingInterval::Month, '2027-04-01')['reason'])->toContain('NO_PRICE_CONFIGURED')->toContain('US-TEST')
        ->and(fn () => $this->terms->set($sub, $this->standard, '2027-04-01', 'INR price for a USD customer', $this->op))->toThrow(RuntimeException::class, 'billed in US-TEST');

    $usd = pepmPrice($this->growth, $us, 'month', '12.00', '2027-04-01', $this->op, $this->checker);
    $inr2 = publishVersion($catalog->draftPriceVersion(PlanPrice::query()->findOrFail($this->standard->plan_price_id), '120.00', 'India increase', $this->op), '2027-05-01', $this->op, $this->checker);
    $onSale = fn (PlanPriceVersion $v, string $day) => $catalog->versionOnSale(PlanPrice::query()->findOrFail($v->plan_price_id), $day);
    expect([$onSale($usd, '2027-05-01')->id, $onSale($usd, '2027-05-01')->currency->value, $onSale($usd, '2027-05-01')->unit_amount_minor])->toBe([$usd->id, 'USD', 1200])
        ->and([$onSale($this->standard, '2027-05-01')->id, $onSale($this->standard, '2027-05-01')->unit_amount_minor])->toBe([$inr2->id, 12000])
        ->and($this->terms->priceFor($sub, BillingInterval::Month, '2027-04-01')['version']->id)->toBe($usd->id)
        ->and([$catalog->versionState($this->standard, '2027-04-15'), $catalog->versionState($inr2, '2027-04-15'), $catalog->versionEnds($this->standard)])
        ->toBe(['CURRENT', 'SCHEDULED', '2027-04-30']);

    // A deal is in its market's currency and is never based on another currency's price.
    $deal = $this->deals->create($sub, $this->growth->id, $us, 'month', 'per_active_employee', '2027-04-01', null, null, null, 'USD deal', $this->op);
    expect($deal->currency->value)->toBe('USD')
        ->and(fn () => $this->deals->draftVersion($deal, '10.00', 0, null, $this->standard, 'Based on the INR price', $this->op))->toThrow(RuntimeException::class, 'never converted');
    $wrongMarket = $this->deals->create($sub, $this->growth->id, $this->in, 'month', 'per_active_employee', '2027-04-01', null, null, null, 'INR deal for a USD customer', $this->op);
    $inrDeal = agreedVersion($wrongMarket, $sub, $this->growth, $this->in, 'month', 'per_active_employee', '700.00', '2027-04-01', $this->op, $this->checker);
    expect(fn () => $this->terms->set($sub, $inrDeal, '2027-04-01', 'INR deal', $this->op))->toThrow(RuntimeException::class, 'billed in US-TEST');
});

it('never re-prices history: issued invoices and pinned subscribers keep their price, new subscribers get the price on sale (critical 1, 2, 3, 9)', function () {
    $alpha = provisionTenant('Alpha');
    billingProfile($alpha, $this->in, $this->op);
    $sub = $this->subs->start($alpha, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $this->terms->set($sub, $this->standard, '2027-04-01', 'Order form', $this->op);
    $this->travelTo('2027-05-02 09:00:00');
    staff($alpha, '2027-03-01');
    staff($alpha, '2027-03-01');
    $this->run->run($alpha);
    $april = billingPeriodsOf($alpha)->get('monthly_arrears 2027-04-01');
    $invoice = app(Invoices::class)->issue($april->invoice, null, 'Issue April', $this->op);
    $before = [$invoice->subtotal_minor, $invoice->tax_minor, $invoice->total_minor, $invoice->snapshot['tax']['rule']['id']];

    $catalog = app(BillingCatalog::class);
    $v2 = publishVersion($catalog->draftPriceVersion(PlanPrice::query()->findOrFail($this->standard->plan_price_id), '150.00', 'Increase', $this->op), '2027-06-01', $this->op, $this->checker);
    $fresh = $invoice->fresh();
    $line = $this->ctx->runAs($alpha, fn () => InvoiceLine::query()->where('invoice_id', $invoice->id)->sole());
    expect([$fresh->subtotal_minor, $fresh->tax_minor, $fresh->total_minor, $fresh->snapshot['tax']['rule']['id']])->toBe($before)
        ->and([$line->plan_price_version_id, $line->unit_amount_minor])->toBe([$this->standard->id, 10000])
        ->and($this->terms->applicableOn($sub, '2027-06-15')['version']->id)->toBe($this->standard->id);   // pinned until re-pinned after notice

    $beta = provisionTenant('Beta');
    billingProfile($beta, $this->in, $this->op, from: '2027-06-01');
    $this->travelTo('2027-06-01 09:00:00');
    $newcomer = $this->subs->start($beta, $this->growth, '2027-06-01', null, 'Contract', $this->op);
    expect($this->terms->priceFor($newcomer, BillingInterval::Month, '2027-06-01')['version']->id)->toBe($v2->id)
        ->and($this->terms->set($newcomer, $v2, '2027-06-01', 'Order form', $this->op)->plan_price_version_id)->toBe($v2->id)
        ->and(fn () => $this->terms->set($newcomer, $this->standard, '2027-06-01', 'Old price', $this->op))->toThrow(RuntimeException::class, 'not the version of this price on sale');
});

it('keeps Markedge policy as approved, effective-dated configuration with history, maker-checker and audit, never a silent fallback (critical 17, 18, 19)', function () {
    $config = app(CommercialConfiguration::class);
    expect($config->resolve(ConfigurationKey::PaymentTermsDays))->toMatchArray(['value' => 15, 'source' => 'shipped_default'])
        ->and($config->value(ConfigurationKey::PriceIncreaseNoticeDays))->toBe(30)
        ->and($config->value(ConfigurationKey::B2bOnly))->toBeTrue();

    $request = $config->propose(ConfigurationKey::PaymentTermsDays, '', '30', '2027-05-01', null, 'Net 30 from May', $this->op);
    expect(fn () => approveAs($request, $this->op))->toThrow(RuntimeException::class, 'Maker-checker');
    approveAs($request, $this->checker);
    $v1 = ConfigurationVersion::query()->where('key', ConfigurationKey::PaymentTermsDays->value)->sole();
    expect([$config->value(ConfigurationKey::PaymentTermsDays), $config->value(ConfigurationKey::PaymentTermsDays, '', '2027-05-01')])->toBe([15, 30])   // future-dated
        ->and([$config->state($v1), $config->state($v1, '2027-05-01')])->toBe(['SCHEDULED', 'CURRENT'])
        ->and(fn () => $v1->fresh()->forceFill(['value' => ['v' => 45]])->save())->toThrow(RuntimeException::class);

    approveAs($config->propose(ConfigurationKey::PaymentTermsDays, '', 45, '2027-07-01', '2027-07-31', 'July promotion', $this->op), $this->checker);
    $v2 = ConfigurationVersion::query()->where(['key' => ConfigurationKey::PaymentTermsDays->value, 'version' => 2])->sole();
    expect([$config->value(ConfigurationKey::PaymentTermsDays, '', '2027-07-15'), $config->state($v1, '2027-07-15'), $config->state($v2, '2027-08-01')])->toBe([45, 'SUPERSEDED', 'EXPIRED'])
        ->and($config->value(ConfigurationKey::PaymentTermsDays, '', '2027-08-01'))->toBeNull()                    // expired: no fallback to version 1 or the default
        ->and(fn () => $config->required(ConfigurationKey::PaymentTermsDays, '', '2027-08-01'))->toThrow(RuntimeException::class, '[CONFIGURATION_MISSING]')
        ->and($config->history(ConfigurationKey::PaymentTermsDays)->pluck('version')->all())->toBe([2, 1]);

    // The policy reaches billing: an invoice issued in May is due 30 days later.
    $tenant = provisionTenant('Foxtrot');
    billingProfile($tenant, $this->in, $this->op);
    $this->travelTo('2027-05-03 09:00:00');
    expect(app(Invoices::class)->issue(draftInvoice($tenant, $this->in, $this->op), null, 'Issue', $this->op)->due_date->toDateString())->toBe('2027-06-02');

    // Rejected and withdrawn proposals change nothing; values are validated; a statutory value needs its source.
    $rejected = $config->propose(ConfigurationKey::B2bOnly, '', 'false', '2027-06-01', null, 'Open to consumers', $this->op);
    app(ApprovalDesk::class)->reject($rejected, 'Not decided', $this->checker);
    $withdrawn = $config->propose(ConfigurationKey::PriceIncreaseNoticeDays, '', 60, '2027-06-01', null, 'Longer notice', $this->op);
    app(ApprovalDesk::class)->withdraw($withdrawn, 'Changed my mind', $this->op);
    expect(ConfigurationVersion::query()->whereIn('key', [ConfigurationKey::B2bOnly->value, ConfigurationKey::PriceIncreaseNoticeDays->value])->pluck('status')->sort()->values()->all())
        ->toBe(['rejected', 'withdrawn'])
        ->and($config->value(ConfigurationKey::B2bOnly, '', '2027-06-01'))->toBeTrue()
        ->and(fn () => $config->propose(ConfigurationKey::ProrationRounding, '', 'ceiling', '2027-06-01', null, 'Bad rounding', $this->op))->toThrow(RuntimeException::class)
        ->and(fn () => $config->propose(ConfigurationKey::PaymentTermsDays, '', 10, '2027-04-01', null, 'Back-dated', $this->op))->toThrow(RuntimeException::class)
        ->and(fn () => $config->propose(ConfigurationKey::InvoiceNumberMaxLength, 'IN', 16, '2027-06-01', null, 'No source', $this->op))->toThrow(RuntimeException::class);

    // A proposal approved after its start date would rewrite the past: refused, the request stays pending.
    $late = $config->propose(ConfigurationKey::PriceIncreaseNoticeDays, '', 45, '2027-05-03', null, 'Longer notice from today', $this->op);
    $this->travelTo('2027-05-04 09:00:00');
    expect(fn () => approveAs($late, $this->checker))->toThrow(RuntimeException::class, 'has passed')
        ->and($late->fresh()->status->value)->toBe('pending')
        ->and($config->value(ConfigurationKey::PriceIncreaseNoticeDays, '', '2027-05-04'))->toBe(30);

    // Audited on the platform chain: who proposed, who approved, the value before and after.
    $events = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->whereIn('action', [AuditAction::ConfigurationProposed, AuditAction::ConfigurationApproved])
        ->where('metadata->key', ConfigurationKey::PaymentTermsDays->value)->with('fieldChanges')->orderBy('id')->get();
    expect($events->pluck('action')->map->value->all())->toBe(['CONFIGURATION_PROPOSED', 'CONFIGURATION_APPROVED', 'CONFIGURATION_PROPOSED', 'CONFIGURATION_APPROVED'])
        ->and($events[1]->actor_id)->toBe($this->checker->id)
        ->and($events[0]->fieldChanges->first()->after)->toContain('30');
});

it('pins a deal only to its own subscription and billing terms to exactly one price source', function () {
    $tenant = provisionTenant('Golf');
    billingProfile($tenant, $this->in, $this->op);
    $sub = $this->subs->start($tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    $agreed = agreedVersion(null, $sub, $this->growth, $this->in, 'month', 'per_active_employee', '75.00', '2027-04-01', $this->op, $this->checker);
    $this->ctx->runAs($tenant, function () use ($sub, $agreed) {
        $base = ['subscription_id' => $sub->id, 'plan_version_id' => $this->growth->id, 'market_id' => $this->in->id, 'currency' => 'INR', 'interval' => 'month',
            'basis' => 'per_active_employee', 'effective_from' => '2027-04-01', 'status' => 'active', 'reason' => 'Direct write'];
        expect(fn () => SubscriptionBillingTerm::query()->create($base))->toThrow(RuntimeException::class, 'exactly one price')
            ->and(fn () => SubscriptionBillingTerm::query()->create($base + ['plan_price_version_id' => $this->standard->id, 'plan_price_id' => $this->standard->plan_price_id,
                'negotiated_price_version_id' => $agreed->id]))->toThrow(RuntimeException::class, 'exactly one price');
    });

    // The same tenant's next subscription cannot take over the previous subscription's deal.
    $this->subs->cancel($sub, '2027-05-01', 'Contract ended', $this->op);
    $next = $this->subs->start(Tenant::query()->findOrFail($sub->tenant_id), $this->growth, '2027-05-01', null, 'New contract', $this->op);
    expect(fn () => $this->terms->set($next, $agreed, '2027-05-01', 'Old deal on the new subscription', $this->op))->toThrow(RuntimeException::class, 'another subscription');
});
