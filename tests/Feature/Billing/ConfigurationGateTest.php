<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\BillingInterval;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Billing\Services\InvoicePresentation;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\NegotiatedPrices;
use App\Domain\Billing\Services\PriceNotices;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Payments\Services\TdsSettlement;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Tax\Services\TaxRules;
use App\Filament\Pages\PlatformPaymentsPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7 configuration closure (release gate): every business value is changed by configuration, by an operator, with
| a second operator's approval and an effective date; nothing needs a deployment; nothing already issued moves; a
| missing value is refused, never assumed. Every amount, rate, registration and name here is fictional.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    [$this->op, $this->checker] = [$this->setup['operator'], $this->setup['verifier']];
    $this->market = $this->setup['market'];
    $this->config = app(CommercialConfiguration::class);
    $this->invoices = app(Invoices::class);
    $this->ctx = app(TenantContext::class);
});

/** Proposed by the maker, approved by the checker: the only way a value changes. */
function configure(ConfigurationKey $key, mixed $value, string $from, object $test, ?string $to = null): void
{
    approveAs(app(CommercialConfiguration::class)->propose($key, '', $value, $from, $to, 'Policy decided (fictional)', $test->op), $test->checker);
}

it('changes Markedge policy and tax rates by configuration alone, each from its effective date (no deployment)', function () {
    $tenant = provisionTenant('Alpha');
    billingProfile($tenant, $this->market, $this->op);

    // Payment terms: 15 days (shipped default) today, 30 days from May by an approved version.
    configure(ConfigurationKey::PaymentTermsDays, 30, '2027-05-01', $this);
    expect($this->invoices->issue(draftInvoice($tenant, $this->market, $this->op), null, 'April', $this->op)->due_date->toDateString())->toBe('2027-04-16');

    // B2B only: a consumer is refused until the policy changes.
    $consumer = ['customer_type' => 'consumer', 'tax_registration' => 'not_applicable', 'tax_id_type' => null, 'tax_id_value' => null];
    expect(fn () => billingProfile(provisionTenant('Consumer One'), $this->market, $this->op, $consumer))->toThrow(RuntimeException::class, 'businesses only');
    configure(ConfigurationKey::B2bOnly, false, '2027-04-01', $this);
    expect(billingProfile(provisionTenant('Consumer Two'), $this->market, $this->op, $consumer)->customer_type->value)->toBe('consumer');

    // TDS: allowed for India / INR (default); an empty list allows it nowhere.
    $issued = $this->invoices->issue(draftInvoice($tenant, $this->market, $this->op), null, 'For TDS', $this->op);
    configure(ConfigurationKey::TdsJurisdictions, '', '2027-04-01', $this);
    expect(fn () => app(TdsSettlement::class)->declare($issued, '10.00', null, 'Customer deducted TDS', $this->op))->toThrow(RuntimeException::class, 'no country');

    // The price-increase notice: 45 days from today, so a 30-day notice is no longer enough.
    configure(ConfigurationKey::PriceIncreaseNoticeDays, 45, '2027-04-01', $this);
    expect(PriceNotices::noticeDays('2027-04-01'))->toBe(45);

    // A new tax rate is a new rule version from its date; earlier days keep the earlier rate.
    verifiedIndiaRule($this->op, $this->checker, '2027-05-01', ['intra_state' => [['type' => 'CGST', 'rate' => '4.5'], ['type' => 'SGST', 'rate' => '4.5']],
        'intra_union_territory' => [['type' => 'CGST', 'rate' => '4.5'], ['type' => 'UTGST', 'rate' => '4.5']], 'inter_state' => [['type' => 'IGST', 'rate' => '9']]]);
    $april = $this->invoices->issue(draftInvoice($tenant, $this->market, $this->op, ['1000.00']), null, 'April', $this->op);
    $this->travelTo('2027-05-01 09:00:00');
    $may = $this->invoices->issue(draftInvoice($tenant, $this->market, $this->op, ['1000.00']), null, 'May invoice', $this->op);
    expect([$april->tax_minor, $may->tax_minor, $may->due_date->toDateString()])->toBe([7500, 9000, '2027-05-31']);

    // Proration rounding: half even from 1 June; a 2.5-paise share rounds to 2 (half up would give 3).
    configure(ConfigurationKey::ProrationRounding, 'half_even', '2027-06-01', $this);
    $growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-05-01');
    $price = pepmPrice($growth, $this->market, 'month', '0.05', '2027-05-01', $this->op, $this->checker);
    $this->travelTo('2027-06-16 09:00:00');
    $sub = app(CommercialSubscriptions::class)->start($tenant, $growth, '2027-06-16', null, 'Contract', $this->op);
    app(BillingTerms::class)->set($sub, $price, '2027-06-16', 'Order form', $this->op);
    staff($tenant, '2027-03-01');
    $this->travelTo('2027-07-02 09:00:00');
    app(BillingPeriods::class)->run($tenant);
    $june = billingPeriodsOf($tenant)->get('monthly_arrears 2027-06-01');
    expect([$june->days_billed, $june->days_in_period, $june->amount_minor, $june->evidence['rounding']])->toBe([15, 30, 2, 'half_even']);
});

it('never changes an issued invoice when prices, deals, tax rules, policy, registrations or customer details change', function () {
    $tenant = provisionTenant('Alpha');
    billingProfile($tenant, $this->market, $this->op);
    $growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    $price = pepmPrice($growth, $this->market, 'month', '100.00', '2027-04-01', $this->op, $this->checker);
    $sub = app(CommercialSubscriptions::class)->start($tenant, $growth, '2027-04-01', null, 'Contract', $this->op);
    app(BillingTerms::class)->set($sub, $price, '2027-04-01', 'Order form', $this->op);
    staff($tenant, '2027-03-01');
    staff($tenant, '2027-03-01');
    $this->travelTo('2027-05-02 09:00:00');
    app(BillingPeriods::class)->run($tenant);
    $issued = $this->invoices->issue(billingPeriodsOf($tenant)->get('monthly_arrears 2027-04-01')->invoice, null, 'April', $this->op);
    $freeze = fn () => [$issued->fresh()->toArray(), $this->ctx->runAs($tenant, fn () => InvoiceLine::query()->where('invoice_id', $issued->id)->get()->toArray()),
        $this->ctx->runAs($tenant, fn () => InvoiceTaxLine::query()->where('invoice_id', $issued->id)->get()->toArray()), app(InvoicePresentation::class)->document($issued->fresh())];
    $before = $freeze();
    expect($issued->snapshot['configuration']['policy']['billing.payment_terms_days'])->toMatchArray(['value' => 15, 'source' => 'shipped_default', 'version_id' => null])
        ->and($issued->snapshot['configuration']['due_date_source'])->toBe('policy')
        ->and($issued->snapshot['tax']['rule']['id'])->toBe($this->setup['rule']->id);

    // Everything changes after issue, through configuration.
    $catalog = app(BillingCatalog::class);
    publishVersion($catalog->draftPriceVersion(PlanPrice::query()->findOrFail($price->plan_price_id), '150.00', 'New list price', $this->op), '2027-06-01', $this->op, $this->checker);
    $deals = app(NegotiatedPrices::class);
    $deal = $deals->create($sub, $growth->id, $this->market, 'month', 'per_active_employee', '2027-06-01', null, 'MSA-LATER', null, 'Deal agreed later', $this->op);
    approveAs($deals->requestPublication($deals->draftVersion($deal, '80.00', 10, '5', null, 'Agreed', $this->op), '2027-06-01', 'Publish', $this->op), $this->checker);
    $newRule = verifiedIndiaRule($this->op, $this->checker, '2027-05-02', ['intra_state' => [['type' => 'CGST', 'rate' => '9'], ['type' => 'SGST', 'rate' => '9']],
        'intra_union_territory' => [['type' => 'CGST', 'rate' => '9'], ['type' => 'UTGST', 'rate' => '9']], 'inter_state' => [['type' => 'IGST', 'rate' => '18']]]);
    app(TaxRules::class)->retire($this->setup['rule']->fresh(), 'Superseded and withdrawn', $this->checker);
    configure(ConfigurationKey::PaymentTermsDays, 45, '2027-05-02', $this);
    supplierVersion('MARKEDGE-IN-TEST', ['legal_name' => 'Renamed Supplier (fictional) Pvt Ltd', 'address_line1' => '9 New Street', 'city' => 'Pune', 'country' => 'IN',
        'subdivision' => 'IN-MH', 'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27', '5'),
        'registrations' => [['type' => 'IN_LUT', 'reference' => 'FICTIONAL-LUT', 'valid_from' => '2027-04-01', 'valid_to' => '2028-03-31']]], '2027-05-02', $this->op, $this->checker);
    billingProfile($tenant, $this->market, $this->op, ['legal_name' => 'Renamed Customer Ltd', 'subdivision' => 'IN-MH'], '2027-05-02');

    expect($freeze())->toBe($before);
    // A new invoice uses everything as it is now, and records the policy version it used.
    $next = $this->invoices->issue(draftInvoice($tenant, $this->market, $this->op, ['1000.00']), null, 'After the changes', $this->op);
    expect([$next->tax_minor, $next->snapshot['tax']['rule']['id'], $next->snapshot['supplier']['legal_name'], $next->due_date->toDateString()])
        ->toBe([18000, $newRule->id, 'Renamed Supplier (fictional) Pvt Ltd', '2027-06-16'])
        ->and($next->snapshot['configuration']['policy']['billing.payment_terms_days'])->toMatchArray(['value' => 45, 'source' => 'approved', 'version' => 1]);
});

it('refuses to bill without a price and to issue without tax configuration; the billing run only drafts', function () {
    $us = billingMarket($this->op, 'US-TEST', 'USD', 'MARKEDGE-IN-TEST', 'en_US', ['US']);
    $growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    $price = pepmPrice($growth, $this->market, 'month', '100.00', '2027-04-01', $this->op, $this->checker);
    $priced = provisionTenant('Priced');
    billingProfile($priced, $this->market, $this->op);
    $unpriced = provisionTenant('Unpriced');
    billingProfile($unpriced, $us, $this->op, ['country' => 'US', 'subdivision' => 'US-CA', 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    $subs = app(CommercialSubscriptions::class);
    $pricedSub = $subs->start($priced, $growth, '2027-04-01', null, 'Contract', $this->op);
    $unpricedSub = $subs->start($unpriced, $growth, '2027-04-01', null, 'Contract', $this->op);
    app(BillingTerms::class)->set($pricedSub, $price, '2027-04-01', 'Order form', $this->op);
    expect(app(BillingTerms::class)->priceFor($unpricedSub, BillingInterval::Month, '2027-04-01'))->toMatchArray(['source' => 'none'])
        ->and(fn () => app(BillingTerms::class)->set($unpricedSub, $price, '2027-04-01', 'INR price for a USD customer', $this->op))->toThrow(RuntimeException::class);
    staff($priced, '2027-03-01');
    staff($unpriced, '2027-03-01');

    // Tax configuration goes missing before issue: drafting continues, issuing is refused, nothing is issued automatically.
    app(TaxRules::class)->retire($this->setup['rule']->fresh(), 'Withdrawn', $this->checker);
    $this->travelTo('2027-05-02 09:00:00');
    app(BillingPeriods::class)->run($priced);
    app(BillingPeriods::class)->run($unpriced);
    $draft = billingPeriodsOf($priced)->get('monthly_arrears 2027-04-01')->invoice;
    expect($draft->status)->toBe(InvoiceStatus::Draft)
        ->and(billingPeriodsOf($unpriced))->toHaveCount(0)                               // no terms, no price: nothing is billed at all
        ->and(fn () => $this->invoices->issue($draft, null, 'Issue', $this->op))->toThrow(RuntimeException::class, '[TAX_CONFIGURATION_MISSING]')
        ->and($draft->fresh()->status)->toBe(InvoiceStatus::Draft)
        ->and($this->invoices->readiness($draft)['ready'])->toBeFalse()
        ->and(Invoice::query()->withoutTenancy()->where('status', '<>', InvoiceStatus::Draft->value)->whereIn('tenant_id', [$priced->id, $unpriced->id])->count())->toBe(0);
});

it('applies a selling entity version (identity and registrations, e.g. the LUT) only once another operator approves it', function () {
    $suppliers = app(SupplierProfiles::class);
    $current = $suppliers->inForce('MARKEDGE-IN-TEST', '2027-04-01');
    $data = ['legal_name' => 'Markedge (fictional) Pvt Ltd', 'address_line1' => '1 Test Street', 'city' => 'Mumbai', 'country' => 'IN', 'subdivision' => 'IN-MH',
        'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27'), 'registrations' => [['type' => 'IN_LUT', 'reference' => 'FICTIONAL-LUT', 'valid_from' => '2027-04-01']]];
    $request = $suppliers->propose('MARKEDGE-IN-TEST', $data, '2027-04-01', 'LUT filed (fictional)', $this->op);
    $pending = SupplierProfile::query()->findOrFail($request->subject_id);
    expect($pending->status)->toBe(SupplierProfile::PENDING)
        ->and($suppliers->inForce('MARKEDGE-IN-TEST', '2027-04-01')->id)->toBe($current->id)                // pending: never used
        ->and(fn () => approveAs($request, $this->op))->toThrow(RuntimeException::class, 'Maker-checker')
        ->and(fn () => $pending->fresh()->forceFill(['status' => 'approved', 'approved_by' => $this->op->id])->save())->toThrow(RuntimeException::class)   // the maker, directly
        ->and(fn () => $pending->fresh()->forceFill(['legal_name' => 'Changed while pending'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => app(InvoiceSeries::class)->create('MARKEDGE-NEW', 'NEW/', '2027-04-01', '2028-03-31', 5, 'Series for a new entity', $this->op))
        ->toThrow(RuntimeException::class);
    $suppliers->propose('MARKEDGE-NEW', $data, '2027-04-01', 'A new entity, pending', $this->op);
    expect(fn () => app(InvoiceSeries::class)->create('MARKEDGE-NEW', 'NEW/', '2027-04-01', '2028-03-31', 5, 'Series for a pending entity', $this->op))
        ->toThrow(RuntimeException::class, 'no approved supplier profile');
    approveAs($request, $this->checker);
    expect([$suppliers->inForce('MARKEDGE-IN-TEST', '2027-04-01')->id, $pending->fresh()->status, $pending->fresh()->approved_by])
        ->toBe([$pending->id, SupplierProfile::APPROVED, $this->checker->id])
        ->and(fn () => $pending->fresh()->forceFill(['status' => 'rejected'])->save())->toThrow(RuntimeException::class);   // decided once

    // Rejected and withdrawn versions never apply; a version approved after its start is refused.
    $rejected = $suppliers->propose('MARKEDGE-IN-TEST', ['legal_name' => 'Wrong name'] + $data, '2027-04-02', 'A typo in the name', $this->op);
    app(ApprovalDesk::class)->reject($rejected, 'Wrong legal name', $this->checker);
    $withdrawn = $suppliers->propose('MARKEDGE-IN-TEST', ['legal_name' => 'Second thoughts'] + $data, '2027-04-03', 'Draft', $this->op);
    app(ApprovalDesk::class)->withdraw($withdrawn, 'Not yet', $this->op);
    $late = $suppliers->propose('MARKEDGE-IN-TEST', ['legal_name' => 'Late'] + $data, '2027-04-04', 'Late approval', $this->op);
    $this->travelTo('2027-04-05 09:00:00');
    expect(fn () => approveAs($late, $this->checker))->toThrow(RuntimeException::class, 'has passed')
        ->and(SupplierProfile::query()->whereIn('id', [$rejected->subject_id, $withdrawn->subject_id])->pluck('status')->sort()->values()->all())->toBe(['rejected', 'withdrawn'])
        ->and($suppliers->inForce('MARKEDGE-IN-TEST', '2027-04-05')->id)->toBe($pending->id);
    $events = AuditEvent::query()->withoutTenancy()->whereIn('action', [AuditAction::SupplierProfileProposed, AuditAction::SupplierProfileRecorded])
        ->where('metadata->version', $pending->version)->orderBy('id')->get();
    expect($events->map(fn ($e) => [$e->action->value, $e->actor_id])->all())
        ->toBe([['SUPPLIER_PROFILE_PROPOSED', $this->op->id], ['SUPPLIER_PROFILE_RECORDED', $this->checker->id]]);
});

it('records a chargeback event it cannot process yet as such, logs it and flags it to operators (B-12 deferred)', function () {
    config(['peopleos.billing.sandbox.enabled' => true, 'peopleos.billing.sandbox.webhook_secret' => 'sandbox-test-secret-0123456789']);
    Log::spy();
    $body = json_encode(['id' => 'evt_dispute_1', 'type' => 'payment.dispute.created', 'data' => ['reference' => 'sbx_fictional', 'amount_minor' => 1000, 'currency' => 'INR']]);
    postWebhook($this, $body)->assertOk();
    $event = PaymentProviderEvent::query()->where('event_id', 'evt_dispute_1')->sole();
    expect([$event->status->value, $event->outcome])->toBe(['ignored', 'chargeback_not_processed']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'chargeback'))->once();
    $this->actingAs($this->op);
    Livewire::test(PlatformPaymentsPage::class)->assertSee('chargeback not processed: handle manually');
});
