<?php

use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\NegotiatedPrice;
use App\Domain\Billing\Models\NegotiatedPriceVersion;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Tax\Enums\TaxRuleStatus;
use App\Domain\Tax\Models\TaxRule;
use App\Filament\Pages\PlatformApprovalsPage;
use App\Filament\Pages\PlatformBillingAccountsPage;
use App\Filament\Pages\PlatformBillingCatalogPage;
use App\Filament\Pages\PlatformCommercialPoliciesPage;
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
    $pages = [PlatformBillingCatalogPage::class, PlatformTaxSetupPage::class, PlatformBillingAccountsPage::class, PlatformInvoicesPage::class, PlatformPaymentsPage::class,
        PlatformApprovalsPage::class, PlatformCommercialPoliciesPage::class];
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
        ->callAction('createPrice', data: ['plan_version' => $this->growth->id, 'market' => $market->id, 'interval' => 'month', 'basis' => 'per_active_employee', 'reason' => 'Fictional price'])->assertHasNoActionErrors();
    Livewire::test(PlatformBillingCatalogPage::class)
        ->callAction('draftPriceVersion', data: ['price' => PlanPrice::query()->sole()->id, 'amount' => '999.00', 'minimum' => 3, 'reason' => 'Fictional amount'])->assertHasNoActionErrors();
    Livewire::test(PlatformBillingCatalogPage::class)
        ->callAction('requestPublication', data: ['version' => PlanPriceVersion::query()->sole()->id, 'from' => '2027-04-01', 'reason' => 'On sale today'])->assertHasNoActionErrors();
    // B-13 maker-checker: the maker sees no approve button on their own request; the second operator approves it.
    $approval = FinancialApproval::query()->sole();
    expect(PlanPriceVersion::query()->sole()->status->value)->toBe('draft');
    Livewire::test(PlatformApprovalsPage::class, ['approval' => $approval->reference])->assertActionHidden('approve')->assertActionVisible('withdraw');
    $this->actingAs($this->checker);
    $this->get(PlatformApprovalsPage::getUrl(['approval' => $approval->reference]))->assertOk()->assertSee('Price publication')->assertSee('On sale today');
    Livewire::test(PlatformApprovalsPage::class, ['approval' => $approval->reference])->assertActionHidden('withdraw')
        ->callAction('approve', data: ['reason' => 'Amount matches the price sheet'])->assertHasNoActionErrors();
    expect([$approval->fresh()->status, PlanPriceVersion::query()->sole()->status->value, $approval->fresh()->checker_id])->toBe([ApprovalStatus::Approved, 'published', $this->checker->id]);
    $this->actingAs($this->op);
    $this->get(PlatformBillingCatalogPage::getUrl())->assertOk()->assertSee('₹999.00')->assertSee('IN-UI')->assertSee('minimum 3');

    $sub = app(CommercialSubscriptions::class)->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('recordProfile', data: ['market' => $market->id, 'customer_type' => 'business', 'legal_name' => 'Alpha Test Ltd', 'billing_email' => 'billing@alpha.test',
            'address_line1' => '2 Alpha Road', 'city' => 'Bengaluru', 'country' => 'IN', 'subdivision_known' => 'IN-KA', 'tax_registration' => 'registered',
            'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('29', '2'), 'from' => '2027-04-01', 'reason' => 'Signed order form'])
        ->assertHasNoActionErrors();
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('setTerms', data: ['subscription' => $sub->id, 'from' => '2027-04-01', 'version' => 's:'.PlanPriceVersion::query()->sole()->id, 'reason' => 'Order form price'])
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
        ->callAction('requestResolution', data: ['outcome' => 'accept', 'reason' => 'Customer short-paid bank charges; accept'])->assertHasNoActionErrors();
    expect($payment->fresh()->reconciliation_status)->toBe(ReconciliationStatus::Exception);
    $this->actingAs($this->checker);
    Livewire::test(PlatformApprovalsPage::class, ['approval' => FinancialApproval::query()->where('action', 'exception_resolution')->sole()->reference])
        ->callAction('approve', data: ['reason' => 'Bank charges confirmed'])->assertHasNoActionErrors();
    expect($payment->fresh()->reconciliation_status)->toBe(ReconciliationStatus::Resolved)->and($draft->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('loads and verifies the statutory dataset, agrees a customer price and changes a policy from the pages, each with a second operator (admin screens)', function () {
    $this->actingAs($this->op);
    $this->get(PlatformTaxSetupPage::getUrl())->assertOk()->assertSee('Statutory dataset')->assertSee('2026.10')->assertSee('not loaded');
    Livewire::test(PlatformTaxSetupPage::class)->callAction('loadDataset', data: ['version' => '2026.10', 'reason' => 'Load the shipped statutory values'])->assertHasNoActionErrors();
    expect(TaxRule::query()->where('status', TaxRuleStatus::Review)->count())->toBe(36);
    Livewire::test(PlatformTaxSetupPage::class)->callAction('activateDataset', data: ['version' => '2026.10', 'reference' => 'SELF-VERIFY'])->assertHasNoActionErrors();
    expect(TaxRule::query()->where('status', TaxRuleStatus::Verified)->count())->toBe(0);          // the loader cannot verify
    $this->actingAs($this->checker);
    Livewire::test(PlatformTaxSetupPage::class)->callAction('activateDataset', data: ['version' => '2026.10', 'reference' => 'TEST-VERIFY-UI'])->assertHasNoActionErrors();
    expect(TaxRule::query()->where('status', TaxRuleStatus::Verified)->count())->toBe(35);
    $this->get(PlatformTaxSetupPage::getUrl())->assertOk()->assertSee('IN-GST-9983-18')->assertSee('PENDING VERIFICATION')->assertSee('SUPERSEDED')
        ->assertSee('official source')->assertSee('classification pending')->assertSee('United States (state level')->assertSee('PENDING VERIFICATION')->assertSee('Florida')
        ->assertSee('European Union member states')->assertSee('EU-DE-VAT-DEST')->assertSee('invoice.number_max_length');

    // A new version of a shipped rule from the page (pre-filled), and a rejection by the checker.
    $india = TaxRule::query()->where(['regime' => 'IN_GST'])->sole();
    $this->actingAs($this->op);
    Livewire::test(PlatformTaxSetupPage::class)->callAction('amendRule', data: ['rule' => $india->id, 'from' => '2027-04-02', 'rule_code' => 'IN-GST-TEST-18',
        'outcomes_json' => json_encode($india->outcomes), 'classification' => 'sac=000000', 'source' => 'CBIC', 'reason' => 'SAC confirmed (fictional)'])->assertHasNoActionErrors();
    expect(TaxRule::query()->where(['regime' => 'IN_GST', 'version' => 2])->sole()->classification)->toBe(['sac' => '000000']);
    $this->actingAs($this->checker);
    Livewire::test(PlatformTaxSetupPage::class)->callAction('rejectRule', data: ['rule' => TaxRule::query()->where('dataset_key', 'GR.VAT.peopleos-subscription')->sole()->id,
        'reason' => 'Rate not confirmed'])->assertHasNoActionErrors();
    expect(TaxRule::query()->where('dataset_key', 'GR.VAT.peopleos-subscription')->sole()->status)->toBe(TaxRuleStatus::Rejected);

    // A customer deal: recorded and drafted by one operator, published by another, pinned as the subscription's terms.
    $this->actingAs($this->op);
    $market = billingMarket($this->op);
    $standard = pepmPrice($this->growth, $market, 'month', '100.00', '2027-04-01', $this->op, $this->checker);
    billingProfile($this->tenant, $market, $this->op);
    $sub = app(CommercialSubscriptions::class)->start($this->tenant, $this->growth, '2027-04-01', null, 'Contract', $this->op);
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('createDeal', data: ['subscription' => $sub->id, 'plan_version' => $this->growth->id, 'market' => $market->id, 'interval' => 'month', 'basis' => 'per_active_employee',
            'contract_start' => '2027-04-01', 'contract_reference' => 'MSA-UI-1', 'reason' => 'Deal agreed with Alpha'])->assertHasNoActionErrors();
    $deal = app(TenantContext::class)->runAs($this->tenant, fn () => NegotiatedPrice::query()->sole());
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('draftDealVersion', data: ['deal' => $deal->id, 'amount' => '85.00', 'minimum' => 250, 'discount' => '5', 'reason' => 'Agreed amount'])->assertHasNoActionErrors();
    $version = app(TenantContext::class)->runAs($this->tenant, fn () => NegotiatedPriceVersion::query()->sole());
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('requestDealPublication', data: ['version' => $version->id, 'from' => '2027-04-01', 'reason' => 'Publish the deal'])->assertHasNoActionErrors();
    $this->get(PlatformBillingAccountsPage::getUrl(['tenant' => $this->tenant->id]))->assertOk()->assertSee('Negotiated prices')->assertSee('MSA-UI-1')->assertSee('PENDING APPROVAL')
        ->assertSee('NO PRICE CONFIGURED');
    $this->actingAs($this->checker);
    $approval = FinancialApproval::query()->where('action', 'negotiated_price_publication')->sole();
    $this->get(PlatformApprovalsPage::getUrl(['approval' => $approval->reference]))->assertOk()->assertSee('MSA-UI-1');
    Livewire::test(PlatformApprovalsPage::class, ['approval' => $approval->reference])->callAction('approve', data: ['reason' => 'Matches the signed order form'])->assertHasNoActionErrors();
    $this->actingAs($this->op);
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('setTerms', data: ['subscription' => $sub->id, 'from' => '2027-04-01', 'version' => 's:'.$standard->id, 'reason' => 'Standard price'])
        ->assertHasActionErrors(['version']);                                                   // the agreed price takes precedence: the standard one is not offered
    Livewire::test(PlatformBillingAccountsPage::class, ['tenant' => $this->tenant->id])
        ->callAction('setTerms', data: ['subscription' => $sub->id, 'from' => '2027-04-01', 'version' => 'n:'.$version->id, 'reason' => 'Agreed price'])->assertHasNoActionErrors();
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => SubscriptionBillingTerm::query()->sole()->negotiated_price_version_id))->toBe($version->id);
    $this->get(PlatformBillingAccountsPage::getUrl(['tenant' => $this->tenant->id]))->assertOk()->assertSee('agreed price')->assertSee('CURRENT')->assertSee('minimum 250');
    $this->get(PlatformBillingCatalogPage::getUrl())->assertOk()->assertSee('Price matrix today')->assertSee('NO PRICE CONFIGURED')->assertSee('CURRENT');

    // A policy change: proposed by one operator, approved by another, shown with its history and trail.
    Livewire::test(PlatformCommercialPoliciesPage::class)->callAction('propose', data: ['key' => 'billing.payment_terms_days', 'value' => '30', 'from' => '2027-05-01',
        'reason' => 'Net 30 from May'])->assertHasNoActionErrors();
    $this->get(PlatformCommercialPoliciesPage::getUrl())->assertOk()->assertSee('Payment terms (days after issue)')->assertSee('15 days')->assertSee('shipped default')
        ->assertSee('PENDING APPROVAL')->assertSee('NOT CONFIGURED', false);
    $this->actingAs($this->checker);
    Livewire::test(PlatformApprovalsPage::class, ['approval' => FinancialApproval::query()->where('action', 'configuration_change')->sole()->reference])
        ->callAction('approve', data: ['reason' => 'Policy decided'])->assertHasNoActionErrors();
    $this->get(PlatformCommercialPoliciesPage::getUrl())->assertOk()->assertSee('SCHEDULED')->assertSee('CONFIGURATION_APPROVED')->assertSee('STATUTORY_DATASET_ACTIVATED');
});
