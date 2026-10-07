<?php

use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\SupplierProfile;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingProfiles;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Billing\Support\InvoiceLineInput;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Jurisdictions\India\GstinValidator;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Services\TaxRules;
use App\Support\Money\Money;

/*
 | SaaS.7 test helpers. Every value is fictional: GSTINs are built on the pseudo-PAN ZZZZZ9999Z with a computed
 | check character, rates are odd test values (never a real business decision), entities and names are invented.
 */

function fictionalGstin(string $stateCode, string $entity = '1'): string
{
    $first14 = $stateCode.'ZZZZZ9999Z'.$entity.'Z';

    return $first14.GstinValidator::checkCharacter($first14);
}

/** Two operators: rules are verified by someone other than their author. @return array{0: User, 1: User} */
function billingOperators(): array
{
    return [platformAdmin(), platformAdmin()];
}

function indiaSupplier(User $operator, string $entity = 'MARKEDGE-IN-TEST', string $subdivision = 'IN-MH', ?string $from = null): SupplierProfile
{
    $code = substr(fictionalGstin(\App\Domain\Tax\Jurisdictions\India\GstStates::code($subdivision)), 0, 2);

    return app(SupplierProfiles::class)->record($entity, ['legal_name' => 'Markedge Test Supplier Pvt Ltd', 'address_line1' => '1 Test Street', 'city' => 'Testpur',
        'postal_code' => '400001', 'country' => 'IN', 'subdivision' => $subdivision, 'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin($code)],
        $from ?? now()->toDateString(), 'Fictional supplier for tests', $operator);
}

function billingMarket(User $operator, string $code = 'IN-TEST', string $currency = 'INR', string $entity = 'MARKEDGE-IN-TEST', string $locale = 'en_IN', array $countries = ['IN']): BillingMarket
{
    return app(BillingCatalog::class)->createMarket($code, "Test market {$code}", $currency, $countries, $entity, $locale, 'Fictional market for tests', $operator);
}

function invoiceSeries(User $operator, string $entity = 'MARKEDGE-IN-TEST', string $prefix = 'TST/', ?string $from = null, ?string $to = null): InvoiceNumberSeries
{
    return app(InvoiceSeries::class)->create($entity, $prefix, $from ?? now()->toDateString(), $to ?? now()->addYear()->toDateString(), 6, 'Fictional series for tests', $operator);
}

/** A verified India GST rule with fictional rates (7.5 % split 3.75 + 3.75), category peopleos.subscription. */
function verifiedIndiaRule(User $author, User $verifier, ?string $from = null, array $outcomes = []): TaxRule
{
    $rules = app(TaxRules::class);
    $rule = $rules->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', $from ?? now()->toDateString(), $outcomes ?: [
        'intra_state' => [['type' => 'CGST', 'rate' => '3.75'], ['type' => 'SGST', 'rate' => '3.75']],
        'intra_union_territory' => [['type' => 'CGST', 'rate' => '3.75'], ['type' => 'UTGST', 'rate' => '3.75']],
        'inter_state' => [['type' => 'IGST', 'rate' => '7.5']],
    ], 'half_up', ['sac' => '000000'], 'Fictional test rule', $author);
    $rules->submit($rule, 'Submitted for test review', $author);

    return $rules->verify($rule, 'TEST-REVIEW-1', 'Fictional verification', $verifier);
}

/** @param  array<string, mixed>  $overrides */
function billingProfile(Tenant $tenant, BillingMarket $market, User $operator, array $overrides = [], ?string $from = null): TenantBillingProfile
{
    $subdivision = $overrides['subdivision'] ?? 'IN-KA';
    $data = array_merge(['customer_type' => 'business', 'legal_name' => "{$tenant->name} Test Customer Ltd", 'billing_email' => 'billing@example.test',
        'address_line1' => '2 Customer Road', 'city' => 'Custombad', 'postal_code' => '560001', 'country' => 'IN', 'subdivision' => $subdivision,
        'tax_registration' => 'registered', 'tax_id_type' => 'IN_GSTIN',
        'tax_id_value' => fictionalGstin(\App\Domain\Tax\Jurisdictions\India\GstStates::code($subdivision) ?? '29', '2')], $overrides);

    return app(BillingProfiles::class)->record($tenant, $market, $data, $from ?? now()->toDateString(), 'Fictional billing profile', $operator);
}

/** A draft with $amounts (major units of the market currency) as lines of quantity 1. */
function draftInvoice(Tenant $tenant, BillingMarket $market, User $operator, array $amounts = ['1000.00'], ?string $key = null): Invoice
{
    $lines = array_map(fn (string $a, int $i) => new InvoiceLineInput('Test service line '.($i + 1), 1, Money::parse($a, $market->currency)), $amounts, array_keys($amounts));

    return app(Invoices::class)->draft($tenant, $market, $lines, 'Fictional draft for tests', $operator, idempotencyKey: $key);
}

/** The whole India setup: supplier, market, series, verified rule. @return array{market: BillingMarket, supplier: SupplierProfile, rule: TaxRule, operator: User, verifier: User} */
function indiaBilling(): array
{
    [$operator, $verifier] = billingOperators();
    $supplier = indiaSupplier($operator);
    $market = billingMarket($operator);
    invoiceSeries($operator);
    $rule = verifiedIndiaRule($operator, $verifier);

    return compact('market', 'supplier', 'rule', 'operator', 'verifier');
}
