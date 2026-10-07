<?php

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Models\TaxRule;
use App\Filament\Pages\PlatformBillingAccountsPage;
use App\Filament\Pages\PlatformBillingCatalogPage;
use App\Filament\Pages\PlatformInvoicesPage;
use App\Filament\Pages\PlatformPaymentsPage;
use App\Filament\Pages\PlatformTaxSetupPage;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7: the operator pages run the same services (operator-only, reasoned, audited): markets and prices, tax
| rules verified by a second operator, supplier entities and series, billing profiles and terms, invoice issue
| and bank transfers, reconciliation exceptions. Every tenant user is refused every billing page.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    [$this->op, $this->checker] = billingOperators();
    $this->tenant = provisionTenant('Alpha');
    $this->growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    actAsTenant(null);
});

it('refuses every billing page to every tenant user', function () {
    $pages = [PlatformBillingCatalogPage::class, PlatformTaxSetupPage::class, PlatformBillingAccountsPage::class, PlatformInvoicesPage::class, PlatformPaymentsPage::class];
    foreach ([tenantUser($this->tenant, ['*']), tenantUser($this->tenant, ['leave.apply'])] as $user) {
        $this->actingAs($user);
        foreach ($pages as $page) {
            $this->get($page::getUrl())->assertForbidden();
            expect($page::canAccess())->toBeFalse();
        }
    }
    $this->actingAs($this->op);
    foreach ($pages as $page) {
        $this->get($page::getUrl())->assertOk();
    }
});

it('runs the whole setup and billing flow from the pages, each step reasoned and checked', function () {
    $this->actingAs($this->op);
    Livewire::test(PlatformTaxSetupPage::class)
        ->callAction('recordSupplier', data: ['entity' => 'MARKEDGE-IN-UI', 'legal_name' => 'Markedge UI Test Pvt Ltd', 'address_line1' => '1 UI Street', 'city' => 'Testpur',
            'country' => 'IN', 'subdivision' => 'IN-MH', 'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27'), 'from' => '2027-04-01', 'reason' => 'Fictional entity'])
        ->assertHasNoActionErrors()
        ->callAction('createSeries', data: ['entity' => 'MARKEDGE-IN-UI', 'prefix' => 'UI/', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31', 'padding' => 5, 'reason' => 'Fictional series'])
        ->assertHasNoActionErrors()
        ->callAction('draftRule', data: ['regime' => 'IN_GST', 'country' => 'IN', 'category' => 'peopleos.subscription', 'from' => '2027-04-01',
            'outcome_IN_GST_intra_state' => 'CGST 3.75; SGST 3.75', 'outcome_IN_GST_inter_state' => 'IGST 7.5', 'rounding' => 'half_up', 'classification' => 'sac=000000', 'reason' => 'Fictional rule'])
        ->assertHasNoActionErrors();
    $rule = TaxRule::query()->sole();
    Livewire::test(PlatformTaxSetupPage::class)->callAction('submitRule', data: ['rule' => $rule->id, 'reason' => 'Please review'])->assertHasNoActionErrors()
        ->callAction('verifyRule', data: ['rule' => $rule->id, 'reference' => 'SELF-CHECK'])->assertHasNoActionErrors();
    expect($rule->fresh()->status)->toBe(TaxRuleStatus::Review);                       // the author cannot verify: refused, shown as a notification
    $this->actingAs($this->checker);
    Livewire::test(PlatformTaxSetupPage::class)->callAction('verifyRule', data: ['rule' => $rule->id, 'reference' => 'TEST-REVIEW-UI'])->assertHasNoActionErrors();
    expect($rule->fresh()->status)->toBe(TaxRuleStatus::Verified);
    $this->get(PlatformTaxSetupPage::getUrl())->assertOk()->assertSee('Configured (verified rule in force; not a legal approval)')->assertSee('Not supported (architecture-ready; tax rules not configured)');

    $this->actingAs($this->op);
    Livewire::test(PlatformBillingCatalogPage::class)
        ->callAction('createMarket', data: ['code' => 'IN-UI', 'currency' => 'INR', 'name' => 'India (UI test)', 'countries' => 'IN', 'supplier_entity' => 'MARKEDGE-IN-UI', 'locale' => 'en_IN', 'reason' => 'Fictional market'])
        ->assertHasNoActionErrors()
        ->callAction('createMarket', data: ['code' => 'IN-UI2', 'currency' => 'INR', 'name' => 'x', 'countries' => 'IN', 'supplier_entity' => 'X', 'locale' => 'en_IN', 'reason' => 'no'])
        ->assertHasActionErrors(['reason']);
    $market = BillingMarket::query()->sole();
    Livewire::test(PlatformBillingCatalogPage::class)
        ->callAction('createPrice', data: ['plan_version' => $this->growth->id, 'market' => $market->id, 'interval' => 'month', 'basis' => 'flat', 'reason' => 'Fictional price'])->assertHasNoActionErrors();
    Livewire::test(PlatformBillingCatalogPage::class)
        ->callAction('draftPriceVersion', data: ['price' => PlanPrice::query()->sole()->id, 'amount' => '999.00', 'reason' => 'Fictional amount'])->assertHasNoActionErrors();
    Livewire::test(PlatformBillingCatalogPage::class)
        ->callAction('publishPriceVersion', data: ['version' => PlanPriceVersion::query()->sole()->id, 'from' => '2027-04-01', 'reason' => 'On sale today'])->assertHasNoActionErrors();
    $this->get(PlatformBillingCatalogPage::getUrl())->assertOk()->assertSee('₹999.00')->assertSee('IN-UI');

    $sub = app(CommercialSubscriptions::class)->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('recordProfile', data: ['market' => $market->id, 'customer_type' => 'business', 'legal_name' => 'Alpha Test Ltd', 'billing_email' => 'billing@alpha.test',
            'address_line1' => '2 Alpha Road', 'city' => 'Bengaluru', 'country' => 'IN', 'subdivision_known' => 'IN-KA', 'tax_registration' => 'registered',
            'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('29', '2'), 'from' => '2027-04-01', 'reason' => 'Signed order form'])
        ->assertHasNoActionErrors();
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('setTerms', data: ['subscription' => $sub->id, 'from' => '2027-04-01', 'version' => PlanPriceVersion::query()->sole()->id, 'reason' => 'Order form price'])
        ->assertHasNoActionErrors();
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => [TenantBillingProfile::query()->count(), SubscriptionBillingTerm::query()->count()]))->toBe([1, 1]);
    $this->get(PlatformBillingAccountsPage::getUrl(['tenant' => $this->tenant->id]))->assertOk()->assertSee('Alpha Test Ltd')->assertSee('₹999.00');

    $draft = draftInvoice($this->tenant, $market, $this->op, ['999.00']);
    $this->get(PlatformInvoicesPage::getUrl(['invoice' => $draft->reference]))->assertOk()->assertSee('This draft can be issued today');
    Livewire::test(PlatformInvoicesPage::class, ['invoice' => $draft->reference])
        ->assertActionVisible('issue')->assertActionHidden('recordTransfer')
        ->callAction('issue', data: ['reason' => 'April invoice'])->assertHasNoActionErrors();
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Issued);
    $this->get(PlatformInvoicesPage::getUrl(['invoice' => $draft->reference]))->assertOk()->assertSee('UI/00001')->assertSee('Place of supply')->assertSee('Karnataka (29)')->assertSee('₹1,073.93');
    Livewire::test(PlatformInvoicesPage::class, ['invoice' => $draft->reference])
        ->callAction('recordTransfer', data: ['amount' => '1000.00', 'currency' => 'INR', 'bank_reference' => 'UTR-UI-1', 'received_on' => '2027-04-01', 'reason' => 'Short NEFT'])
        ->assertHasNoActionErrors();
    $payment = app(TenantContext::class)->runAs($this->tenant, fn () => Payment::query()->sole());
    expect($payment->reconciliation_code)->toBe('amount_mismatch');
    $this->get(PlatformPaymentsPage::getUrl(['payment' => $payment->reference]))->assertOk()->assertSee('amount_mismatch');
    Livewire::test(PlatformPaymentsPage::class, ['payment' => $payment->reference])
        ->callAction('resolveException', data: ['note' => 'Refunded by bank outside PeopleOS'])->assertHasNoActionErrors();
    expect($payment->fresh()->reconciliation_status)->toBe(ReconciliationStatus::Resolved)->and($draft->fresh()->status)->toBe(InvoiceStatus::Issued);
});
