<?php

use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingProfiles;
use App\Domain\Entitlements\Models\TenantEntitlementProfile;
use App\Domain\Tax\Enums\CustomerType;
use App\Domain\Tax\Enums\TaxRegistration;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/BillingTestHelpers.php';

/*
| SaaS.7: a tenant's billing identity is explicit and versioned: market, B2B or B2C, legal name, billing contact,
| billing jurisdiction and tax registration. Nothing is derived from the tenant's operating country, locale or
| currency. Identifiers must belong to their country; GSTINs are format-checked against the state on record.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    [$this->operator] = billingOperators();
    $this->market = billingMarket($this->operator);
    $this->tenant = provisionTenant('Alpha');
    $this->profiles = app(BillingProfiles::class);
});

it('records B2B and B2C explicitly, with registration rules that match the customer type', function () {
    $b2b = billingProfile($this->tenant, $this->market, $this->operator);
    expect([$b2b->customer_type, $b2b->tax_registration, $b2b->tax_id_status, $b2b->tax_id_value])->toBe([CustomerType::Business, TaxRegistration::Registered, 'format_valid', fictionalGstin('29', '2')]);
    // B-5 (approved): business customers only at launch. B2C stays representable behind the launch rule.
    expect(fn () => billingProfile($this->tenant, $this->market, $this->operator, ['customer_type' => 'consumer', 'tax_registration' => 'not_applicable', 'tax_id_type' => null, 'tax_id_value' => null]))
        ->toThrow(RuntimeException::class, 'businesses only');
    config(['peopleos.billing.b2b_only' => false]);
    $b2c = billingProfile($this->tenant, $this->market, $this->operator, ['customer_type' => 'consumer', 'tax_registration' => 'not_applicable', 'tax_id_type' => null, 'tax_id_value' => null]);
    expect([$b2c->customer_type, $b2c->tax_registration, $b2c->tax_id_value, $b2c->version])->toBe([CustomerType::Consumer, TaxRegistration::NotApplicable, null, 2]);

    foreach ([
        'consumer with a registration' => ['customer_type' => 'consumer'],
        'business without a status' => ['tax_registration' => 'not_applicable', 'tax_id_type' => null, 'tax_id_value' => null],
        'no customer type' => ['customer_type' => ''],
        'unregistered with an identifier' => ['tax_registration' => 'unregistered'],
        'GSTIN of another state' => ['subdivision' => 'IN-MH', 'tax_id_value' => fictionalGstin('29', '2')],
        'GSTIN with a wrong check character' => ['tax_id_value' => substr(fictionalGstin('29', '2'), 0, 14).'0'],
        'UK VAT number in India' => ['tax_id_type' => 'GB_VAT', 'tax_id_value' => 'GB000000000'],
        'no Indian state' => ['subdivision' => null],
        'unknown special status' => ['special_tax_status' => 'embassy'],
        'bad e-mail' => ['billing_email' => 'not-an-email'],
        'bad country' => ['country' => 'India'],
    ] as $what => $override) {
        expect(fn () => billingProfile($this->tenant, $this->market, $this->operator, $override))->toThrow(RuntimeException::class, null, $what);
    }
    expect(fn () => billingProfile($this->tenant, $this->market, $this->operator, [], '2027-03-31'))->toThrow(RuntimeException::class, 'today or later');
});

it('accepts any country through generic identifiers, and special statuses only where a jurisdiction knows them', function () {
    $gb = billingProfile($this->tenant, $this->market, $this->operator, ['country' => 'GB', 'subdivision' => null, 'tax_id_type' => 'GB_VAT', 'tax_id_value' => 'gb 000 0000 00']);
    $de = billingProfile($this->tenant, $this->market, $this->operator, ['country' => 'DE', 'subdivision' => null, 'tax_id_type' => 'EU_VAT_ID', 'tax_id_value' => 'DE000000000']);
    $us = billingProfile($this->tenant, $this->market, $this->operator, ['country' => 'US', 'subdivision' => 'US-CA', 'tax_id_type' => 'US_SALES_TAX_PERMIT', 'tax_id_value' => 'CA-0000']);
    expect([$gb->tax_id_value, $gb->tax_id_status, $de->tax_id_status, $us->subdivision])->toBe(['GB000000000', 'not_validated', 'not_validated', 'US-CA'])
        ->and(billingProfile($this->tenant, $this->market, $this->operator, ['special_tax_status' => 'sez'])->special_tax_status)->toBe('sez')
        ->and(fn () => billingProfile($this->tenant, $this->market, $this->operator, ['country' => 'GB', 'subdivision' => null, 'tax_id_type' => 'GB_VAT', 'tax_id_value' => 'GB0000000000', 'special_tax_status' => 'sez']))
        ->toThrow(RuntimeException::class, 'not a special tax status');
});

it('keeps versions: the one in force is the latest started, invoices keep theirs, and nothing touches entitlements or HR data', function () {
    $first = billingProfile($this->tenant, $this->market, $this->operator);
    $future = billingProfile($this->tenant, $this->market, $this->operator, ['legal_name' => 'From May Ltd'], '2027-05-01');
    $sameDay = billingProfile($this->tenant, $this->market, $this->operator, ['legal_name' => 'Corrected Today Ltd']);
    expect($this->profiles->inForce($this->tenant, '2027-04-01')->id)->toBe($sameDay->id)
        ->and($this->profiles->inForce($this->tenant, '2027-05-01')->id)->toBe($future->id)
        ->and($this->profiles->inForce($this->tenant, '2027-03-31'))->toBeNull()
        ->and(fn () => app(TenantContext::class)->runAs($this->tenant, fn () => $first->fresh()->forceFill(['legal_name' => 'Edited'])->save()))->toThrow(RuntimeException::class, 'never changes')
        ->and(fn () => app(TenantContext::class)->runAs($this->tenant, fn () => $first->fresh()->delete()))->toThrow(RuntimeException::class, 'never deleted')
        // A billing profile is not entitlement configuration and not an HR record.
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => TenantEntitlementProfile::query()->count()))->toBe(0)
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => [\App\Domain\Organisation\Models\LegalEntity::query()->count(), \App\Domain\Compliance\Models\StatutoryRegistration::query()->count()]))->toBe([0, 0]);
});
