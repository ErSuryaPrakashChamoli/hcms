<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\StatutoryDataset;
use App\Domain\Identity\Models\User;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\JurisdictionStatus;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Enums\TaxRuleState;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Services\TaxRules;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Domain\Tax\Support\TaxParty;
use App\Domain\Tax\Support\TaxQuote;
use App\Support\Money\Currency;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/BillingTestHelpers.php';

/*
| SaaS.7 configuration: the current statutory values of India, the UK, the EU member states, the UAE and the
| researched US states ship as a dataset with their official sources. They are data: loaded pending by one operator,
| verified by another, versioned and effective-dated; pending values stay unusable; missing configuration is never
| 0 %. The dataset's values are real statutory rates; parties, numbers and the SAC used to complete a rule are
| fictional.
*/

/** An Indian supplier (Maharashtra, fictional GSTIN) holding $registrations (e.g. an LUT). */
function supplierParty(array $registrations = []): TaxParty
{
    return new TaxParty(new TaxJurisdiction('IN', 'IN-MH'), TaxRegistration::Registered, TaxIdType::InGstin, fictionalGstin('27'), registrations: $registrations);
}

function customerParty(string $country, ?string $subdivision = null, CustomerType $type = CustomerType::Business, ?TaxIdType $idType = null, ?string $id = null,
    ?string $special = null, ?string $locality = null): TaxParty
{
    return new TaxParty(new TaxJurisdiction($country, $subdivision, $locality), $id === null ? TaxRegistration::Unregistered : TaxRegistration::Registered, $idType, $id, $type, $special);
}

function lut(?string $to = null): array
{
    return ['type' => 'IN_LUT', 'reference' => 'AD2710260000001', 'valid_from' => '2026-04-01', 'valid_to' => $to ?? '2027-03-31'];
}

/** The quote, or the reason code it was refused with. */
function quoteOr(TaxParty $supplier, TaxParty $customer, string $currency = 'USD', ?string $day = null): TaxQuote|string
{
    try {
        return app(TaxEngine::class)->quote(new TaxContext($supplier, $customer, 'peopleos.subscription', $day ?? now()->toDateString(), Currency::of($currency)));
    } catch (TaxUnavailableException $e) {
        return $e->reasonCode;
    }
}

/** Loads and verifies the shipped dataset, then completes India's rule with a (fictional) SAC as a verified version 2 from today. */
function shippedDatasetWithSac(User $maker, User $checker): TaxRule
{
    app(StatutoryDataset::class)->load('2026.10', 'Load the shipped statutory values', $maker);
    if (TaxRule::query()->where(['dataset_version' => '2026.10', 'status' => TaxRuleStatus::Verified])->doesntExist()) {
        app(StatutoryDataset::class)->activate('2026.10', 'TEST-VERIFY-2026.10', $checker);
    }
    $rules = app(TaxRules::class);
    $v1 = TaxRule::query()->where(['regime' => TaxRegime::InGst, 'country' => 'IN'])->sole();
    $v2 = $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', now()->toDateString(), $v1->outcomes, 'half_up', ['sac' => '000000'], 'SAC confirmed by the (fictional) adviser', $maker,
        ['rule_code' => 'IN-GST-TEST-18', 'source' => $v1->source, 'source_reference' => $v1->source_reference, 'source_url' => $v1->source_url]);
    $rules->submit($v2, 'Review please', $maker);

    return $rules->verify($v2, 'TEST-SAC-REVIEW', null, $checker);
}

beforeEach(function () {
    $this->travelTo('2026-10-08 09:00:00');
    [$this->maker, $this->checker] = billingOperators();
    $this->dataset = app(StatutoryDataset::class);
    $this->rules = app(TaxRules::class);
});

it('ships the current statutory values as data: loaded pending by one operator, verified by another, pending values kept pending (current rules load)', function () {
    expect($this->dataset->versions())->toBe(['2026.10'])
        ->and(TaxRule::query()->count())->toBe(0)
        ->and(quoteOr(supplierParty(), customerParty('IN', 'IN-KA', id: fictionalGstin('29', '2'), idType: TaxIdType::InGstin), 'INR'))->toBe('TAX_CONFIGURATION_MISSING');

    expect($this->dataset->load('2026.10', 'Load the shipped statutory values', $this->maker))->toBe(['rules' => 36, 'parameters' => 1])
        ->and($this->dataset->load('2026.10', 'Load again', $this->maker))->toBe(['rules' => 36, 'parameters' => 1])   // idempotent
        ->and(TaxRule::query()->count())->toBe(36)
        ->and(TaxRule::query()->get()->every(fn (TaxRule $r) => $this->rules->state($r) === TaxRuleState::PendingVerification))->toBeTrue()
        ->and(quoteOr(supplierParty(), customerParty('IN', 'IN-KA', id: fictionalGstin('29', '2'), idType: TaxIdType::InGstin), 'INR'))->toBe('TAX_RULE_UNVERIFIED');

    // Maker-checker: the operator who loaded it cannot verify it, and nothing is half-verified.
    expect(fn () => $this->dataset->activate('2026.10', 'SELF-VERIFY', $this->maker))->toThrow(RuntimeException::class)
        ->and(TaxRule::query()->where('status', TaxRuleStatus::Verified)->count())->toBe(0);
    expect($this->dataset->activate('2026.10', 'TEST-VERIFY-2026.10', $this->checker))->toBe(['verified' => 36, 'pending' => 1])   // 35 rules + 1 parameter
        ->and(fn () => $this->dataset->activate('2026.10', 'TEST-VERIFY-AGAIN', $this->checker))->toThrow(RuntimeException::class, 'already verified');   // once
    $status = $this->dataset->status('2026.10');
    expect([$status['shipped_rules'], $status['rules'], $status['verified'], $status['pending'], $status['parameters_approved']])->toBe([36, 36, 35, 1, 1])
        ->and($status['pending_items'][0]['key'])->toBe('GR.VAT.peopleos-subscription');

    $rule = fn (string $key) => TaxRule::query()->where('dataset_key', $key)->sole();
    expect($this->rules->state($rule('IN.GST.peopleos-subscription')))->toBe(TaxRuleState::Current)
        ->and($this->rules->state($rule('GR.VAT.peopleos-subscription')))->toBe(TaxRuleState::PendingVerification)
        ->and($this->rules->state($rule('US.CA.SALES.peopleos-subscription')))->toBe(TaxRuleState::Current)
        ->and($this->rules->state($rule('US.CA.SALES.peopleos-subscription.2027')))->toBe(TaxRuleState::Scheduled)
        ->and($rule('IN.GST.peopleos-subscription')->outcome('inter_state')['components'][0]->rate)->toBe('18')
        ->and($rule('IN.GST.peopleos-subscription')->source_url)->toStartWith('https://')
        ->and($rule('IN.GST.peopleos-subscription')->verified_by)->toBe($this->checker->id)
        ->and(app(CommercialConfiguration::class)->value(ConfigurationKey::InvoiceNumberMaxLength, 'IN'))->toBe(16)
        ->and(app(TaxEngine::class)->status('IN'))->toBe(JurisdictionStatus::Configured)
        ->and(app(TaxEngine::class)->status('JP'))->toBe(JurisdictionStatus::NotSupported);

    $loaded = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', AuditAction::StatutoryDatasetLoaded)->get();
    $activated = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', AuditAction::StatutoryDatasetActivated)->sole();
    expect($loaded)->toHaveCount(2)->and($loaded->first()->actor_id)->toBe($this->maker->id)->and($activated->actor_id)->toBe($this->checker->id);
    $verified = AuditEvent::query()->withoutTenancy()->whereNull('tenant_id')->where('action', AuditAction::TaxRuleVerified)->first();
    expect([$verified->metadata['maker_id'], $verified->metadata['checker_id']])->toBe([$this->maker->id, $this->checker->id]);
});

it('determines India GST from the shipped rule and refuses until the SAC is confirmed; a new version supplies it and the old one stays for its dates (critical 8, 9, 16)', function () {
    $this->dataset->load('2026.10', 'Load the shipped statutory values', $this->maker);
    $this->dataset->activate('2026.10', 'TEST-VERIFY-2026.10', $this->checker);
    $karnataka = customerParty('IN', 'IN-KA', idType: TaxIdType::InGstin, id: fictionalGstin('29', '2'));
    expect(quoteOr(supplierParty(), $karnataka, 'INR'))->toBe('TAX_CLASSIFICATION_PENDING');

    $this->travelTo('2026-10-09 09:00:00');
    $v2 = shippedDatasetWithSac($this->maker, $this->checker);
    $v1 = TaxRule::query()->where(['regime' => TaxRegime::InGst, 'version' => 1])->sole();
    $quote = quoteOr(supplierParty(), $karnataka, 'INR');
    expect($quote->rule->id)->toBe($v2->id)
        ->and(array_map(fn ($c) => [$c->type, $c->rate], $quote->components))->toBe([['IGST', '18']])
        ->and(array_map(fn ($c) => [$c->type, $c->rate], quoteOr(supplierParty(), customerParty('IN', 'IN-MH', idType: TaxIdType::InGstin, id: fictionalGstin('27', '2')), 'INR')->components))
        ->toBe([['CGST', '9'], ['SGST', '9']])
        ->and(quoteOr(supplierParty(), $karnataka, 'INR', '2026-10-08'))->toBe('TAX_CLASSIFICATION_PENDING')     // the day before: version 1 applies
        ->and([$this->rules->state($v1), $this->rules->state($v2)])->toBe([TaxRuleState::Superseded, TaxRuleState::Current]);

    // A published rule never changes and is never deleted: a change is a new version.
    expect(fn () => $v1->fresh()->forceFill(['outcomes' => ['inter_state' => [['type' => 'IGST', 'rate' => '5']]]])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $v2->fresh()->forceFill(['effective_to' => '2026-12-31'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $v2->fresh()->delete())->toThrow(RuntimeException::class)
        ->and($this->rules->history($v2)->pluck('version')->all())->toBe([2, 1]);
});

it('zero-rates an export of services only when its statutory conditions hold, adds the customer country\'s treatment and reports the INR value (India export conditions)', function () {
    shippedDatasetWithSac($this->maker, $this->checker);
    $california = customerParty('US', 'US-CA');

    expect(quoteOr(supplierParty(), $california))->toBe('EXPORT_CONDITIONS_NOT_SATISFIED')                         // no LUT
        ->and(quoteOr(supplierParty([lut('2026-09-30')]), $california))->toBe('EXPORT_CONDITIONS_NOT_SATISFIED')    // LUT expired
        ->and(quoteOr(supplierParty([lut()]), $california, 'INR'))->toBe('EXPORT_CONDITIONS_NOT_SATISFIED')         // paid in rupees
        ->and(quoteOr(supplierParty([lut()]), customerParty('US', 'US-CA', special: 'supplier_establishment')))->toBe('EXPORT_CONDITIONS_NOT_SATISFIED')   // distinct person
        ->and(quoteOr(supplierParty([lut()]), customerParty('US', 'US-TX')))->toBe('REGISTRATION_REQUIRED')          // Texas taxes SaaS: a permit is needed
        ->and(quoteOr(supplierParty([lut()]), customerParty('US', 'US-FL')))->toBe('TAX_CONFIGURATION_MISSING')      // not researched: never 0 %
        ->and(quoteOr(supplierParty([lut()]), customerParty('US')))->toBe('PLACE_OF_SUPPLY_UNRESOLVED');           // no national US tax

    $quote = quoteOr(supplierParty([lut()]), $california);
    expect(array_map(fn ($l) => [$l->role, $l->determination->regime->value, $l->treatment->value, count($l->components)], $quote->legs()))
        ->toBe([['supplier', 'IN_GST', 'zero_rated', 0], ['destination', 'US_SALES_TAX', 'not_taxable', 0]])
        ->and($quote->wording())->toContain('SUPPLY MEANT FOR EXPORT/SUPPLY TO SEZ UNIT OR SEZ DEVELOPER FOR AUTHORISED OPERATIONS UNDER BOND OR LETTER OF UNDERTAKING WITHOUT PAYMENT OF INTEGRATED TAX')
        ->and($quote->reportingCurrency)->toBe('INR');

    // The invoice: issued only with the INR value (rate and source given by the operator), frozen in its snapshot.
    supplierVersion('MARKEDGE-IN-TEST', ['legal_name' => 'Markedge Test Pvt Ltd', 'address_line1' => '1 Test Street', 'city' => 'Mumbai', 'postal_code' => '400001',
        'country' => 'IN', 'subdivision' => 'IN-MH', 'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27'), 'registrations' => [lut()]], now()->toDateString(), $this->maker, $this->checker);
    $us = billingMarket($this->maker, 'US-TEST', 'USD', 'MARKEDGE-IN-TEST', 'en_US', ['US']);
    invoiceSeries($this->maker);
    $tenant = provisionTenant('Export customer');
    billingProfile($tenant, $us, $this->maker, ['country' => 'US', 'subdivision' => 'US-CA', 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    $draft = draftInvoice($tenant, $us, $this->maker, ['1000.00']);
    expect(fn () => app(Invoices::class)->issue($draft, null, 'Issue', $this->maker))->toThrow(RuntimeException::class, '[REPORTING_VALUE_REQUIRED]')
        ->and($draft->fresh()->status)->toBe(InvoiceStatus::Draft);
    $issued = app(Invoices::class)->issue($draft, null, 'Issue', $this->maker, ['rate' => '83.2575', 'source' => 'Fictional accounting rate', 'date' => '2026-10-08']);
    expect([$issued->status, $issued->tax_minor, $issued->total_minor, $issued->tax_treatment])->toBe([InvoiceStatus::Issued, 0, 100000, TaxTreatment::ZeroRated->value])
        ->and($issued->snapshot['reporting'])->toMatchArray(['currency' => 'INR', 'rate' => '83.2575', 'total_minor' => 8325750, 'tax_minor' => 0])
        ->and(array_column($issued->snapshot['tax']['legs'], 'role'))->toBe(['supplier', 'destination'])
        ->and(app(TenantContext::class)->runAs($tenant, fn () => InvoiceTaxLine::query()->where('invoice_id', $issued->id)->count()))->toBe(0);
});

it('applies EU member-state, UK and UAE destination rules: consumer rates where the supplier is registered, reverse charge for businesses with their number (EU member-state aware, UAE electronic services)', function () {
    shippedDatasetWithSac($this->maker, $this->checker);
    $registered = fn (string ...$types) => supplierParty(array_merge([lut()], array_map(fn ($t) => ['type' => $t, 'reference' => "FICTIONAL-{$t}", 'valid_from' => '2026-01-01', 'valid_to' => null], $types)));
    $destination = fn ($quote) => is_string($quote) ? $quote : [$quote->legs()[1]->treatment->value, array_map(fn ($c) => "{$c->type} {$c->rate}", $quote->legs()[1]->components), $quote->legs()[1]->wording];
    $consumer = fn (string $country) => customerParty($country, type: CustomerType::Consumer);

    expect($destination(quoteOr(supplierParty([lut()]), $consumer('DE'), 'EUR')))->toBe('REGISTRATION_REQUIRED')               // OSS (non-Union) needed
        ->and($destination(quoteOr($registered('EU_OSS_NON_UNION'), $consumer('DE'), 'EUR')))->toBe(['standard', ['VAT 19'], null])
        ->and($destination(quoteOr($registered('EU_OSS_NON_UNION'), $consumer('FR'), 'EUR'))[1])->toBe(['VAT 20'])
        ->and($destination(quoteOr($registered('EU_OSS_NON_UNION'), $consumer('HU'), 'EUR'))[1])->toBe(['VAT 27'])
        ->and($destination(quoteOr($registered('EU_OSS_NON_UNION'), $consumer('FI'), 'EUR'))[1])->toBe(['VAT 25.5'])
        ->and($destination(quoteOr($registered('EU_OSS_NON_UNION'), $consumer('GR'), 'EUR')))->toBe('TAX_RULE_UNVERIFIED')      // shipped pending
        ->and($destination(quoteOr(supplierParty([lut()]), customerParty('DE', idType: TaxIdType::EuVatId, id: 'DE999999999'), 'EUR')))->toBe(['reverse_charge', [], 'Reverse charge'])
        ->and($destination(quoteOr(supplierParty([lut()]), customerParty('DE'), 'EUR')))->toBe('CUSTOMER_TAX_STATUS_UNRESOLVED');  // a business without its VAT number

    expect($destination(quoteOr(supplierParty([lut()]), $consumer('GB'), 'GBP')))->toBe('REGISTRATION_REQUIRED')
        ->and($destination(quoteOr($registered('GB_VAT'), $consumer('GB'), 'GBP'))[1])->toBe(['VAT 20'])
        ->and($destination(quoteOr(supplierParty([lut()]), customerParty('GB', idType: TaxIdType::GbVat, id: 'GB999999973'), 'GBP'))[0])->toBe('reverse_charge');

    expect($destination(quoteOr(supplierParty([lut()]), customerParty('AE', idType: TaxIdType::AeTrn, id: '100000000000003'), 'AED'))[0])->toBe('reverse_charge')
        ->and($destination(quoteOr(supplierParty([lut()]), customerParty('AE'), 'AED')))->toBe('REGISTRATION_REQUIRED')   // 5 % only by a UAE-registered supplier
        ->and($destination(quoteOr($registered('AE_TRN'), $consumer('AE'), 'AED'))[1])->toBe(['VAT 5']);
});

it('keeps US sales tax state by state: dates per state, taxable share, registration and local rates; unresearched states pending; no national rate (US jurisdiction-specific, critical 17, 18)', function () {
    shippedDatasetWithSac($this->maker, $this->checker);
    $us = TaxRule::query()->where('regime', TaxRegime::UsSalesTax)->get();
    expect($us->pluck('subdivision')->unique()->sort()->values()->all())->toBe(['US-CA', 'US-NY', 'US-PA', 'US-TX', 'US-WA'])
        ->and($us->where('subdivision', '')->count())->toBe(0)
        ->and($us->firstWhere('subdivision', 'US-TX')->outcome('saas'))->toMatchArray(['taxable_percent' => '80', 'requires_supplier_registration' => 'US_STATE:US-TX'])
        ->and(array_map(fn ($c) => "{$c->type} {$c->rate}", $us->firstWhere('subdivision', 'US-NY')->outcome('saas')['components']))->toBe(['STATE 4']);

    // Local rates are data too: Texas requires them, so the customer's local tax jurisdiction and its verified rule decide.
    $texasPermit = supplierParty([lut(), ['type' => 'US_STATE:US-TX', 'reference' => 'FICTIONAL-TX', 'valid_from' => '2026-01-01', 'valid_to' => null]]);
    $austin = customerParty('US', 'US-TX', locality: 'TX-FICTIONAL-01');
    expect(quoteOr($texasPermit, customerParty('US', 'US-TX')))->toBe('PLACE_OF_SUPPLY_UNRESOLVED')       // no local jurisdiction recorded
        ->and(quoteOr($texasPermit, $austin))->toBe('TAX_CONFIGURATION_MISSING');                          // recorded, but no local rule: never the state rate alone
    try {
        app(TaxEngine::class)->quote(new TaxContext($texasPermit, $austin, 'peopleos.subscription', now()->toDateString(), Currency::USD));
    } catch (TaxUnavailableException $e) {
        expect($e->getMessage())->toContain('US-TX / TX-FICTIONAL-01');
    }
    // A fictional local rule (an operator would take it from a rate source): pending until verified, then its own leg on the same taxable share.
    $local = $this->rules->draft(TaxRegime::UsSalesTax, 'US', 'US-TX', 'peopleos.subscription', now()->toDateString(),
        ['saas' => ['components' => [['type' => 'CITY', 'rate' => '1'], ['type' => 'COUNTY', 'rate' => '0.5']], 'treatment' => 'standard', 'taxable_percent' => '80']],
        'half_up', null, 'Fictional local rates for tests', $this->maker, ['locality' => 'tx-fictional-01', 'source' => 'Fictional', 'source_reference' => 'Test only']);
    $this->rules->submit($local, 'Review please', $this->maker);
    expect(quoteOr($texasPermit, $austin))->toBe('TAX_RULE_UNVERIFIED');
    $this->rules->verify($local, 'TEST-LOCAL', null, $this->checker);
    $quote = quoteOr($texasPermit, $austin);
    expect(array_map(fn ($l) => [$l->role, $l->rule->locality, array_map(fn ($c) => "{$c->type} {$c->rate}", $l->components)], $quote->legs()))
        ->toBe([['supplier', '', []], ['destination', '', ['STATE 5']], ['destination_local', 'TX-FICTIONAL-01', ['CITY 0.8', 'COUNTY 0.4']]])
        ->and(quoteOr($texasPermit, customerParty('US', 'US-TX', locality: 'TX-FICTIONAL-02')))->toBe('TAX_CONFIGURATION_MISSING')   // another locality's rule never applies
        ->and($this->rules->inForce(TaxRegime::UsSalesTax, 'US', 'US-TX', 'peopleos.subscription', now()->toDateString())->locality)->toBe('')   // nor as the state rule
        ->and($this->rules->state($local->fresh()))->toBe(TaxRuleState::Current);

    // California: not taxable today; taxable from 1 January 2027 by the scheduled version (it activates on its date).
    $caPermit = supplierParty([lut(), ['type' => 'US_STATE:US-CA', 'reference' => 'FICTIONAL-CA', 'valid_from' => '2026-01-01', 'valid_to' => null]]);
    $ca = $us->where('subdivision', 'US-CA')->sortBy('effective_from')->values();
    expect(quoteOr($caPermit, customerParty('US', 'US-CA'))->legs()[1]->rule->id)->toBe($ca[0]->id)
        ->and(quoteOr($caPermit, customerParty('US', 'US-CA'), day: '2027-01-01'))->toBe('PLACE_OF_SUPPLY_UNRESOLVED');   // from 2027 local rates apply too
    $this->travelTo('2027-01-02 09:00:00');
    expect([$this->rules->state($ca[0]), $this->rules->state($ca[1])])->toBe([TaxRuleState::Superseded, TaxRuleState::Current])
        ->and(quoteOr(supplierParty([lut()]), customerParty('US', 'US-CA')))->toBe('REGISTRATION_REQUIRED')
        ->and(quoteOr($caPermit, customerParty('US', 'US-CA'), day: '2026-12-31')->legs()[1]->treatment)->toBe(TaxTreatment::NotTaxable);   // history still answers
});

it('lets a checker reject a pending rule, which then never applies, and limits invoice numbers by the shipped statutory length (statutory parameters)', function () {
    $this->dataset->load('2026.10', 'Load the shipped statutory values', $this->maker);
    $this->dataset->activate('2026.10', 'TEST-VERIFY-2026.10', $this->checker);
    $greece = TaxRule::query()->where('dataset_key', 'GR.VAT.peopleos-subscription')->sole();
    expect(fn () => $this->rules->reject($greece, 'I loaded it', $this->maker))->toThrow(RuntimeException::class);
    $this->rules->reject($greece, 'Rate not confirmed against a current source', $this->checker);
    expect([$greece->fresh()->status, $this->rules->state($greece->fresh())])->toBe([TaxRuleStatus::Rejected, TaxRuleState::Rejected])
        ->and(fn () => $this->rules->verify($greece->fresh(), 'LATE', null, $this->checker))->toThrow(RuntimeException::class)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', AuditAction::TaxRuleRejected)->sole()->actor_id)->toBe($this->checker->id);

    indiaSupplier($this->maker);
    expect(fn () => app(InvoiceSeries::class)->create('MARKEDGE-IN-TEST', 'PO/2026-27/AB', now()->toDateString(), '2027-03-31', 6, 'Too long a number', $this->maker))
        ->toThrow(RuntimeException::class, 'at most 16 characters')
        ->and(app(InvoiceSeries::class)->create('MARKEDGE-IN-TEST', 'PO/26-27/', now()->toDateString(), '2027-03-31', 6, 'Fits the limit', $this->maker)->max_length)->toBe(16);
});

it('never falls back to an older version when the current one expires, applies a taxable share exactly and takes only https sources (expired configuration)', function () {
    shippedDatasetWithSac($this->maker, $this->checker);
    $nevada = customerParty('US', 'US-NV');
    expect(quoteOr(supplierParty([lut()]), $nevada))->toBe('TAX_CONFIGURATION_MISSING');            // no rule: never 0 %

    // A fictional operator rule (Nevada is not in the dataset): 10 % on 80 % of the price, from today, then a version that expires.
    $draft = fn (string $from, ?string $to, string $rate) => $this->rules->draft(TaxRegime::UsSalesTax, 'US', 'US-NV', 'peopleos.subscription', $from,
        ['saas' => ['components' => [['type' => 'STATE', 'rate' => $rate]], 'treatment' => 'standard', 'taxable_percent' => '80']], 'half_up', null, 'Fictional test rule', $this->maker,
        ['effective_to' => $to, 'source' => 'Fictional', 'source_reference' => 'Test only', 'source_url' => 'https://example.test/nevada']);
    $verify = function (TaxRule $rule) {
        $this->rules->submit($rule, 'Review please', $this->maker);

        return $this->rules->verify($rule, 'TEST-NV', null, $this->checker);
    };
    $v1 = $verify($draft('2026-10-08', null, '10'));
    $v2 = $verify($draft('2026-11-01', '2026-11-30', '12.5'));
    $components = fn (string $day) => array_map(fn ($c) => "{$c->type} {$c->rate}", quoteOr(supplierParty([lut()]), $nevada, day: $day)->legs()[1]->components);
    expect($components('2026-10-08'))->toBe(['STATE 8'])                                                  // 10 % × 80 %
        ->and($components('2026-11-15'))->toBe(['STATE 10'])                                              // 12.5 % × 80 %
        ->and(quoteOr(supplierParty([lut()]), $nevada, day: '2026-12-01'))->toBe('TAX_CONFIGURATION_MISSING')   // v2 expired: v1 does not come back
        ->and([$this->rules->state($v1, '2026-12-01'), $this->rules->state($v2, '2026-12-01')])->toBe([TaxRuleState::Superseded, TaxRuleState::Expired]);
    // The one-pass derivation the tax page uses agrees with state() for every rule on every day that matters.
    foreach (['2026-10-08', '2026-11-15', '2026-12-01', '2027-01-01'] as $day) {
        $all = TaxRule::query()->get();
        expect($this->rules->states($all, $day))->toBe($all->mapWithKeys(fn (TaxRule $r) => [$r->id => $this->rules->state($r, $day)])->all());
    }

    foreach (['javascript:alert(1)', 'http://example.test/plain', 'https://example.test/"><script>'] as $url) {
        expect(fn () => $this->rules->draft(TaxRegime::UsSalesTax, 'US', 'US-NV', 'peopleos.subscription', '2027-01-01', ['saas' => [['type' => 'STATE', 'rate' => '1']]], 'half_up', null,
            'Bad source', $this->maker, ['source_url' => $url]))->toThrow(RuntimeException::class, 'https://');
    }
});

it('issues a US invoice with the state leg and the local leg of the customer\'s recorded local tax jurisdiction (US state/local aware)', function () {
    shippedDatasetWithSac($this->maker, $this->checker);
    supplierVersion('MARKEDGE-IN-TEST', ['legal_name' => 'Markedge Test Pvt Ltd', 'address_line1' => '1 Test Street', 'city' => 'Mumbai', 'country' => 'IN', 'subdivision' => 'IN-MH',
        'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27'),
        'registrations' => [lut(), ['type' => 'US_STATE:US-TX', 'reference' => 'FICTIONAL-TX', 'valid_from' => '2026-01-01', 'valid_to' => null]]], now()->toDateString(), $this->maker, $this->checker);
    $local = $this->rules->draft(TaxRegime::UsSalesTax, 'US', 'US-TX', 'peopleos.subscription', now()->toDateString(),
        ['saas' => ['components' => [['type' => 'CITY', 'rate' => '1'], ['type' => 'COUNTY', 'rate' => '0.5']], 'treatment' => 'standard', 'taxable_percent' => '80']],
        'half_up', null, 'Fictional local rates for tests', $this->maker, ['locality' => 'TX-FICTIONAL-01', 'source' => 'Fictional', 'source_reference' => 'Test only']);
    $this->rules->submit($local, 'Review please', $this->maker);
    $this->rules->verify($local, 'TEST-LOCAL', null, $this->checker);
    $us = billingMarket($this->maker, 'US-TEST', 'USD', 'MARKEDGE-IN-TEST', 'en_US', ['US']);
    invoiceSeries($this->maker);
    $tenant = provisionTenant('Texas customer');
    billingProfile($tenant, $us, $this->maker, ['country' => 'US', 'subdivision' => 'US-TX', 'tax_locality' => 'tx-fictional-01', 'tax_registration' => 'unregistered',
        'tax_id_type' => null, 'tax_id_value' => null]);
    $issued = app(Invoices::class)->issue(draftInvoice($tenant, $us, $this->maker, ['1000.00']), null, 'Issue', $this->maker,
        ['rate' => '83.25', 'source' => 'Fictional accounting rate', 'date' => now()->toDateString()]);
    $lines = app(TenantContext::class)->runAs($tenant, fn () => InvoiceTaxLine::query()->where('invoice_id', $issued->id)->orderBy('id')->get());
    expect($lines->map(fn ($l) => [$l->tax_type, (string) $l->rate, $l->tax_minor, $l->metadata['leg'], $l->metadata['locality'] ?? null])->all())
        ->toBe([['STATE', '5.0000', 5000, 'destination', 'TX-FICTIONAL-01'], ['CITY', '0.8000', 800, 'destination_local', 'TX-FICTIONAL-01'], ['COUNTY', '0.4000', 400, 'destination_local', 'TX-FICTIONAL-01']])
        ->and([$issued->tax_minor, $issued->total_minor])->toBe([6200, 106200])
        ->and($issued->snapshot['customer']['tax_locality'])->toBe('TX-FICTIONAL-01')
        ->and(array_column($issued->snapshot['tax']['legs'], 'role'))->toBe(['supplier', 'destination', 'destination_local']);
});
