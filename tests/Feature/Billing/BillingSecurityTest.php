<?php

use App\Domain\Audit\Exceptions\ImmutableAuditRecordException;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Billing\Models\SubscriptionBillingTerm;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingProfiles;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Billing\Support\InvoiceLineInput;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayrollRuns;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Services\TaxRules;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Payroll/PayrollTestHelpers.php';

/*
| SaaS.7: billing, tax and payments are platform operations. Only operators change them (in the services, not just
| on pages), always with a reason; tenants never see one another's financial records and see nothing without a
| bound tenant; every change is on the audit chains; legacy tenants get nothing; payroll never depends on billing.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    $this->operator = $this->setup['operator'];
    $this->a = provisionTenant('Alpha');
    $this->b = provisionTenant('Beta');
    billingProfile($this->b, $this->setup['market'], $this->operator);
    $this->bInvoice = app(Invoices::class)->issue(draftInvoice($this->b, $this->setup['market'], $this->operator), null, 'Beta invoice', $this->operator);
    $this->bPayment = app(Payments::class)->recordBankTransfer($this->bInvoice, '1', 'INR', 'UTR-BETA-1', '2027-04-01', 'Short transfer', $this->operator);
});

it('refuses every non-operator in every billing, tax and payment service', function () {
    $flagged = tenantUser($this->a, ['*']);
    $flagged->forceFill(['is_platform_admin' => true])->save();
    $people = ['employee' => tenantUser($this->a, ['leave.apply']), 'tenant administrator' => tenantUser($this->a, ['*']), 'tenant user flagged as operator' => $flagged,
        'tenantless non-operator' => User::factory()->create(['tenant_id' => null, 'is_platform_admin' => false])];
    $price = app(BillingCatalog::class)->createPrice(publishedPlanFor($this->operator), $this->setup['market'], 'month', 'flat', 'Fictional price', $this->operator);
    foreach ($people as $who => $user) {
        $user = $user->fresh();
        foreach ([
            fn () => app(BillingCatalog::class)->createMarket('XX', 'X market', 'USD', ['US'], 'X1', 'en_US', 'Not allowed', $user),
            fn () => app(BillingCatalog::class)->draftPriceVersion($price, '1.00', 'Not allowed', $user),
            fn () => app(SupplierProfiles::class)->record('X1', ['legal_name' => 'X', 'address_line1' => 'X', 'city' => 'X', 'country' => 'US'], '2027-04-01', 'Not allowed', $user),
            fn () => app(InvoiceSeries::class)->create('MARKEDGE-IN-TEST', 'X/', '2027-04-01', '2027-04-30', 4, 'Not allowed', $user),
            fn () => app(TaxRules::class)->draft(TaxRegime::InGst, 'IN', null, 'peopleos.subscription', '2027-04-01', ['inter_state' => [['type' => 'IGST', 'rate' => '1']]], 'half_up', ['sac' => '1'], 'Not allowed', $user),
            fn () => app(TaxRules::class)->verify($this->setup['rule'], 'Self-verify', null, $user),
            fn () => app(BillingProfiles::class)->record($this->a, $this->setup['market'], ['customer_type' => 'consumer'], '2027-04-01', 'Not allowed', $user),
            fn () => app(Invoices::class)->draft($this->a, $this->setup['market'], [new InvoiceLineInput('X', 1, Money::parse('1', 'INR'))], 'Not allowed', $user),
            fn () => app(Invoices::class)->issue($this->bInvoice, null, 'Not allowed', $user),
            fn () => app(Invoices::class)->discard($this->bInvoice, 'Not allowed', $user),
            fn () => app(Payments::class)->recordBankTransfer($this->bInvoice, '1', 'INR', 'UTR-X-1', '2027-04-01', 'Not allowed', $user),
            fn () => app(Payments::class)->resolveException($this->bPayment, 'Not allowed', $user),
            fn () => app(Payments::class)->initiate($this->bInvoice, 'manual', 'Not allowed', $user),
        ] as $attempt) {
            expect($attempt)->toThrow(RuntimeException::class, 'Only platform operators');
        }
    }
    expect(fn () => app(Payments::class)->resolveException($this->bPayment, 'no', $this->operator))->toThrow(RuntimeException::class, 'reason');
});

it('keeps each tenant\'s financial records to itself and shows nothing without a tenant', function () {
    $counts = fn () => [TenantBillingProfile::query()->count(), SubscriptionBillingTerm::query()->count(), Invoice::query()->count(), InvoiceLine::query()->count(),
        InvoiceTaxLine::query()->count(), Payment::query()->count()];
    expect(app(TenantContext::class)->runAs($this->b, $counts))->toBe([1, 0, 1, 1, 1, 1])
        ->and(app(TenantContext::class)->runAs($this->a, $counts))->toBe([0, 0, 0, 0, 0, 0])
        ->and(app(TenantContext::class)->runAs($this->a, fn () => [Invoice::query()->find($this->bInvoice->id), Payment::query()->find($this->bPayment->id)]))->toBe([null, null]);
    actAsTenant(null);
    expect($counts())->toBe([0, 0, 0, 0, 0, 0]);
});

it('audits every financial change on the chains, with actor and reason, never rewritable', function () {
    $actions = AuditEvent::query()->withoutTenancy()->whereIn('module', ['billing', 'tax', 'payments'])->get();
    expect($actions->whereNull('tenant_id')->pluck('action')->map->value->unique()->sort()->values()->all())->toBe(['BILLING_MARKET_CREATED', 'BILLING_PROFILE_RECORDED',
        'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'INVOICE_SERIES_CREATED', 'PAYMENT_RECONCILIATION_EXCEPTION', 'PAYMENT_RECORDED', 'PAYMENT_SUCCEEDED', 'SUPPLIER_PROFILE_RECORDED',
        'TAX_RULE_DRAFTED', 'TAX_RULE_SUBMITTED', 'TAX_RULE_VERIFIED'])
        ->and($actions->where('tenant_id', $this->b->id)->pluck('action')->map->value->unique()->sort()->values()->all())->toBe(['BILLING_PROFILE_RECORDED',
            'INVOICE_DRAFTED', 'INVOICE_ISSUED', 'PAYMENT_RECONCILIATION_EXCEPTION', 'PAYMENT_RECORDED', 'PAYMENT_SUCCEEDED'])
        ->and($actions->every(fn ($e) => filled($e->reason) && $e->actor_id !== null))->toBeTrue()
        ->and($actions->firstWhere('action.value', 'INVOICE_ISSUED')->metadata['number'])->toBe('TST/000001')
        ->and(fn () => $actions->first()->update(['reason' => 'rewritten']))->toThrow(ImmutableAuditRecordException::class)
        ->and(app(AuditIntegrityVerifier::class)->verify($this->b->id)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

it('creates nothing for tenants an operator has not configured, and never blocks payroll', function () {
    syncComplianceRules();
    expect(app(TenantContext::class)->runAs($this->a, fn () => [TenantBillingProfile::query()->count(), Invoice::query()->count(), Payment::query()->count(),
        \App\Domain\Entitlements\Models\TenantEntitlementProfile::query()->count()]))->toBe([0, 0, 0, 0])
        ->and(fn () => app(Invoices::class)->issue(draftInvoice($this->a, $this->setup['market'], $this->operator), null, 'No profile', $this->operator))->toThrow(RuntimeException::class, 'no billing profile');

    // Beta has an unpaid invoice and an unreconciled short payment: its payroll runs to payslips regardless.
    actAsTenant($this->b);
    $hr = tenantUser($this->b, ['payroll.*', 'employee.*']);
    $approver = tenantUser($this->b, ['payroll.*', 'employee.*']);
    $this->actingAs($hr);
    $company = payrollCompany();
    $employee = salariedEmployee(600000);
    $runs = app(PayrollRuns::class);
    $run = $runs->finalize($runs->approve($runs->validate($runs->calculate($runs->open($company, 2027, 3))), $approver));
    expect($run->status)->toBe('finalized')->and(Payslip::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

function publishedPlanFor(User $operator): \App\Domain\Entitlements\Models\PlanVersion
{
    require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

    return publishedPlan($operator, 'sec-'.substr(uniqid(), -5), ['leave' => true], now()->toDateString());
}
