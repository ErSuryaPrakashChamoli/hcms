<?php

use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\JurisdictionStatus;
use App\Domain\Tax\Enums\TaxIdType;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Domain\Tax\Enums\TaxRounding;
use App\Domain\Tax\Enums\TaxTreatment;
use App\Domain\Tax\Exceptions\TaxUnavailableException;
use App\Domain\Tax\Jurisdictions\India\GstinValidator;
use App\Domain\Tax\Jurisdictions\India\GstStates;
use App\Domain\Tax\Services\TaxCalculator;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Services\TaxRules;
use App\Domain\Tax\Support\TaxComponent;
use App\Domain\Tax\Support\TaxContext;
use App\Domain\Tax\Support\TaxJurisdiction;
use App\Domain\Tax\Support\TaxParty;
use App\Support\Money\Currency;
use App\Support\Money\Money;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/TestRegimes.php';

/*
| SaaS.7: the jurisdiction-neutral tax engine. India GST is determined (intra-state, intra-UT, inter-state) from the
| supplier's and customer's recorded jurisdictions and priced only by a rule verified by a second operator; every
| gap refuses (exports, SEZ, unregistered supplier, no verified rule, unsupported regime). VAT and sales tax run
| through the same engine and tax lines (test-only determiners), and nothing is ever "supported" by software.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    [$this->author, $this->verifier] = billingOperators();
    $this->engine = fn () => app(TaxEngine::class);
});

function indiaContext(string $supplierState, string $customerCountry, ?string $customerState, TaxRegistration $registration = TaxRegistration::Registered,
    CustomerType $type = CustomerType::Business, ?string $special = null, bool $supplierRegistered = true, string $day = '2027-04-01'): TaxContext
{
    $supplierCode = GstStates::code($supplierState);
    $customerCode = $customerCountry === 'IN' ? GstStates::code($customerState) : null;

    return new TaxContext(
        new TaxParty(new TaxJurisdiction('IN', $supplierState), TaxRegistration::Registered, $supplierRegistered ? TaxIdType::InGstin : null, $supplierRegistered ? fictionalGstin($supplierCode) : null),
        new TaxParty(new TaxJurisdiction($customerCountry, $customerState), $registration, $registration === TaxRegistration::Registered ? TaxIdType::InGstin : null,
            $registration === TaxRegistration::Registered && $customerCode ? fictionalGstin($customerCode, '2') : null, $type, $special),
        'peopleos.subscription', $day, Currency::INR);
}

it('determines India GST from recorded jurisdictions and prices it only with the verified rule', function () {
    verifiedIndiaRule($this->author, $this->verifier);
    $quote = fn (...$args) => ($this->engine)()->quote(indiaContext(...$args));

    $inter = $quote('IN-MH', 'IN', 'IN-KA');
    expect([$inter->determination->outcome, $inter->determination->treatment, array_map(fn ($c) => [$c->type, $c->rate], $inter->components)])
        ->toBe(['inter_state', TaxTreatment::Standard, [['IGST', '7.5']]])
        ->and($inter->determination->placeOfSupply->subdivision)->toBe('IN-KA')
        ->and($inter->determination->metadata['place_of_supply_code'])->toBe('29');
    expect(array_map(fn ($c) => $c->type, $quote('IN-MH', 'IN', 'IN-MH')->components))->toBe(['CGST', 'SGST'])
        ->and(array_map(fn ($c) => $c->type, $quote('IN-CH', 'IN', 'IN-CH')->components))->toBe(['CGST', 'UTGST'])   // union territory without legislature
        ->and($quote('IN-MH', 'IN', 'IN-KA', TaxRegistration::Unregistered)->determination->outcome)->toBe('inter_state')
        ->and($quote('IN-MH', 'IN', 'IN-MH', TaxRegistration::NotApplicable, CustomerType::Consumer)->determination->outcome)->toBe('intra_state')
        ->and($quote('IN-MH', 'IN', 'IN-OR')->determination->placeOfSupply->subdivision)->toBe('IN-OD');                 // former ISO code accepted
});

it('refuses rather than guesses: exports, SEZ, unregistered supplier, missing state, no verified rule, unpriced outcome, unsupported regimes', function () {
    $refused = function (TaxContext $context, string $code) {
        try {
            ($this->engine)()->quote($context);
            $this->fail("Expected {$code}");
        } catch (TaxUnavailableException $e) {
            expect($e->reasonCode)->toBe($code);
        }
    };
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA'), 'TAX_CONFIGURATION_MISSING');        // nothing configured yet: never 0 %
    $rules = app(TaxRules::class);
    $draft = $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => [['type' => 'IGST', 'rate' => '7.5']]], 'half_up', ['sac' => '000000'], 'Only inter-state', $this->author);
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA'), 'TAX_RULE_UNVERIFIED');              // a draft never applies
    $rules->submit($draft, 'Review please', $this->author);
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA'), 'TAX_RULE_UNVERIFIED');              // nor a rule in review
    $rules->verify($draft, 'TEST-REVIEW-2', null, $this->verifier);
    expect(($this->engine)()->quote(indiaContext('IN-MH', 'IN', 'IN-KA'))->rule->id)->toBe($draft->id);
    $refused(indiaContext('IN-MH', 'IN', 'IN-MH'), 'TAX_CONFIGURATION_MISSING');        // the rule does not price intra-state
    $refused(indiaContext('IN-MH', 'US', 'US-CA'), 'TAX_CONFIGURATION_MISSING');        // the rule does not price export of services
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA', special: 'sez'), 'TAX_CONFIGURATION_MISSING');
    $refused(indiaContext('IN-MH', 'IN', null), 'PLACE_OF_SUPPLY_UNRESOLVED');
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA', supplierRegistered: false), 'SUPPLIER_TAX_STATUS_UNRESOLVED');
    $rules->retire($draft, 'Withdrawn after review', $this->verifier);
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA'), 'TAX_CONFIGURATION_MISSING');        // a retired rule stops applying (never an older one)
    // A standard-rated outcome priced with no component is refused, never read as "no tax".
    $empty = $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => []], 'half_up', ['sac' => '000000'], 'Empty inter-state outcome', $this->author);
    $rules->submit($empty, 'Review please', $this->author);
    $rules->verify($empty, 'TEST-REVIEW-EMPTY', null, $this->verifier);
    $refused(indiaContext('IN-MH', 'IN', 'IN-KA'), 'TAX_CONFIGURATION_MISSING');
    $rules->retire($empty, 'Withdrawn after review', $this->verifier);

    $abroad = fn (string $country, ?string $sub) => new TaxContext(new TaxParty(new TaxJurisdiction($country, $sub), TaxRegistration::Registered),
        new TaxParty(new TaxJurisdiction($country, $sub), TaxRegistration::Unregistered, customerType: CustomerType::Business), 'peopleos.subscription', '2027-04-01', Currency::USD);
    $refused($abroad('US', 'US-CA'), 'TAX_CONFIGURATION_MISSING');                     // no supplier-side determination outside India
    $refused($abroad('DE', null), 'TAX_CONFIGURATION_MISSING');
    $refused($abroad('JP', null), 'TAX_CONFIGURATION_MISSING');
});

it('keeps rules honest: maker-checker, immutable once submitted, effective-dated, today or later, regime-checked', function () {
    $rules = app(TaxRules::class);
    $rule = $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-05-01', ['inter_state' => [['type' => 'IGST', 'rate' => '7.5']]], 'half_up', ['sac' => '000000'], 'Future rule', $this->author);
    $rules->submit($rule, 'Review please', $this->author);
    expect(fn () => $rules->verify($rule, 'SELF-REVIEW', null, $this->author))->toThrow(RuntimeException::class, 'other than the one who drafted')
        ->and(fn () => $rule->fresh()->forceFill(['outcomes' => ['inter_state' => [['type' => 'IGST', 'rate' => '1']]]])->save())->toThrow(RuntimeException::class, 'never changes')
        ->and(fn () => $rule->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted');
    $rules->verify($rule, 'TEST-REVIEW-3', null, $this->verifier);
    $refusedBefore = fn () => ($this->engine)()->quote(indiaContext('IN-MH', 'IN', 'IN-KA'));
    expect($refusedBefore)->toThrow(TaxUnavailableException::class)                       // not in force before 1 May
        ->and(($this->engine)()->quote(indiaContext('IN-MH', 'IN', 'IN-KA', day: '2027-05-01'))->rule->id)->toBe($rule->id);

    foreach ([
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-03-31', ['inter_state' => [['type' => 'IGST', 'rate' => '1']]], 'half_up', ['sac' => '1'], 'Back-dated', $this->author),
        fn () => $rules->draft(TaxRegime::InGst, 'GB', null, 'peopleos.subscription', '2027-04-01', ['x' => [['type' => 'IGST', 'rate' => '1']]], 'half_up', ['sac' => '1'], 'Wrong country', $this->author),
        fn () => $rules->draft(TaxRegime::EuVat, 'DE', null, 'peopleos.subscription', '2027-04-01', ['standard' => [['type' => 'VAT', 'rate' => '19']]], 'half_up', null, 'No determiner', $this->author),
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => [['type' => 'VAT', 'rate' => '1']]], 'half_up', ['sac' => '1'], 'Wrong type', $this->author),
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => [['type' => 'IGST', 'rate' => '101']]], 'half_up', ['sac' => '1'], 'Bad rate', $this->author),
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => [['type' => 'IGST', 'rate' => '1']]], 'ceiling', ['sac' => '1'], 'Bad rounding', $this->author),
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['export' => [['type' => 'IGST', 'rate' => '0']]], 'half_up', ['sac' => '1'], 'Unknown outcome', $this->author),
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => [['type' => 'IGST', 'rate' => '1']]], 'half_up', ['sac' => '1'], 'Expires first', $this->author, ['effective_to' => '2027-03-31']),
        fn () => $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => ['components' => [['type' => 'IGST', 'rate' => '1']], 'conditions' => ['unknown_condition' => true]]], 'half_up', ['sac' => '1'], 'Unknown condition', $this->author),
    ] as $attempt) {
        expect($attempt)->toThrow(RuntimeException::class);
    }
});

it('calculates per line and component, rounding once each with the rule mode; no components means no tax', function () {
    $lines = [1 => Money::parse('0.50', 'INR'), 2 => Money::parse('0.50', 'INR'), 3 => Money::parse('999.99', 'INR')];
    $intra = [new TaxComponent('CGST', '9'), new TaxComponent('SGST', '9')];
    $up = TaxCalculator::calculate(Currency::INR, $lines, $intra, TaxRounding::HalfUp);
    $even = TaxCalculator::calculate(Currency::INR, $lines, $intra, TaxRounding::HalfEven);
    expect(array_map(fn ($l) => [$l->lineNo, $l->type, $l->tax->minor], $up->lines))->toBe([[1, 'CGST', 5], [1, 'SGST', 5], [2, 'CGST', 5], [2, 'SGST', 5], [3, 'CGST', 9000], [3, 'SGST', 9000]])
        ->and($up->total->minor)->toBe(18020)       // the sum of rounded amounts
        ->and($even->total->minor)->toBe(18016)     // 4.5 paise rounds to 4 four times
        ->and(array_map(fn (Money $m) => $m->minor, $up->totalsByType()))->toBe(['CGST' => 9010, 'SGST' => 9010])
        ->and(TaxCalculator::calculate(Currency::INR, $lines, [], TaxRounding::HalfUp)->lines)->toBe([])
        ->and(TaxCalculator::calculate(Currency::JPY, [1 => Money::parse('333', 'JPY')], [new TaxComponent('VAT', '10')], TaxRounding::HalfUp)->total->minor)->toBe(33)
        ->and(TaxCalculator::calculate(Currency::USD, [1 => Money::parse('100', 'USD')], [new TaxComponent('STATE', '0')], TaxRounding::HalfUp)->total->minor)->toBe(0);
});

it('validates GSTINs by format, check character and state; other identifiers are stored as not validated', function () {
    $valid = fictionalGstin('27');
    $validator = new GstinValidator;
    expect($validator->validate(strtolower($valid), 'IN-MH'))->toBe($valid)
        ->and(fn () => $validator->validate(substr($valid, 0, 14).($valid[14] === 'A' ? 'B' : 'A'), null))->toThrow(InvalidArgumentException::class, 'check character')
        ->and(fn () => $validator->validate($valid, 'IN-KA'))->toThrow(InvalidArgumentException::class, 'not in Karnataka')
        ->and(fn () => $validator->validate('98'.substr($valid, 2), null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validate('27ZZZZZ9999Z1Y0', null))->toThrow(InvalidArgumentException::class, 'not a GSTIN')
        ->and(app(TaxEngine::class)->normaliseTaxId(TaxIdType::GbVat, 'gb 123 4567 89', null))->toBe(['value' => 'GB123456789', 'status' => 'not_validated'])
        ->and(app(TaxEngine::class)->normaliseTaxId(TaxIdType::InGstin, $valid, 'IN-MH'))->toBe(['value' => $valid, 'status' => 'format_valid']);
});

it('reports jurisdiction status honestly and never claims support', function () {
    $engine = app(TaxEngine::class);
    expect($engine->status('IN'))->toBe(JurisdictionStatus::PendingTaxReview)
        ->and($engine->status('US'))->toBe(JurisdictionStatus::PendingTaxReview)     // destination rules representable, none verified
        ->and($engine->status('DE'))->toBe(JurisdictionStatus::PendingTaxReview)
        ->and($engine->status('GB'))->toBe(JurisdictionStatus::PendingTaxReview)
        ->and($engine->status('JP'))->toBe(JurisdictionStatus::NotSupported);
    verifiedIndiaRule($this->author, $this->verifier);
    expect($engine->status('IN'))->toBe(JurisdictionStatus::Configured)
        ->and(collect(['IN', 'US', 'GB', 'AE', 'SG', 'AU', 'CA', 'DE', 'FR', 'JP'])->contains(fn ($c) => $engine->status($c) === JurisdictionStatus::Supported))->toBeFalse();
});

it('runs VAT and sales tax through the same engine and generic tax lines, and never runs India GST for them', function () {
    useTestRegimes();
    $rules = app(TaxRules::class);
    foreach ([[TaxRegime::GbVat, 'GB', null, ['domestic' => [['type' => 'VAT', 'rate' => '20']], 'reverse_charge' => []]],
        [TaxRegime::UsSalesTax, 'US', 'US-TX', ['state' => [['type' => 'STATE', 'rate' => '6.25'], ['type' => 'CITY', 'rate' => '2']]]]] as [$regime, $country, $sub, $outcomes]) {
        $rule = $rules->draft($regime, $country, $sub, 'peopleos.subscription', '2027-04-01', $outcomes, 'half_up', null, 'Test-only rule', $this->author);
        $rules->submit($rule, 'Review please', $this->author);
        $rules->verify($rule, 'TEST-ONLY', null, $this->verifier);
    }
    $engine = app(TaxEngine::class);
    $gb = fn (string $country, ?string $taxId) => new TaxContext(new TaxParty(new TaxJurisdiction('GB'), TaxRegistration::Registered, TaxIdType::GbVat, 'GB000000000'),
        new TaxParty(new TaxJurisdiction($country), $taxId ? TaxRegistration::Registered : TaxRegistration::Unregistered, $taxId ? TaxIdType::EuVatId : null, $taxId, CustomerType::Business),
        'peopleos.subscription', '2027-04-01', Currency::GBP);

    $domestic = $engine->quote($gb('GB', null));
    $vat = $engine->calculate($domestic, Currency::GBP, [1 => Money::parse('1250', 'GBP')]);
    expect([$domestic->determination->regime, $vat->lines[0]->type, $vat->total->toDecimal()])->toBe([TaxRegime::GbVat, 'VAT', '250.00']);

    $reverse = $engine->quote($gb('DE', 'DE000000000'));
    expect([$reverse->determination->treatment, $reverse->components, $engine->calculate($reverse, Currency::GBP, [1 => Money::parse('1250', 'GBP')])->total->minor])
        ->toBe([TaxTreatment::ReverseCharge, [], 0]);                                      // explicit treatment, no tax lines

    $us = new TaxContext(new TaxParty(new TaxJurisdiction('US', 'US-TX'), TaxRegistration::Registered), new TaxParty(new TaxJurisdiction('US', 'US-TX'), TaxRegistration::Unregistered,
        customerType: CustomerType::Consumer), 'peopleos.subscription', '2027-04-01', Currency::USD);
    $sales = $engine->calculate($engine->quote($us), Currency::USD, [1 => Money::parse('99.99', 'USD')]);
    expect(array_keys($sales->totalsByType()))->toBe(['STATE', 'CITY'])->and($sales->total->toDecimal())->toBe('8.25');   // 6.25 → 6.249 + 2 → 2.000 rounded per component
});
