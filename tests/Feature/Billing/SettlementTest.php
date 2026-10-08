<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingMarket;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\InvoiceTdsClaim;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\SupplierProfiles;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\TdsSettlement;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Tax\Enums\JurisdictionStatus;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Services\TaxEngine;
use App\Domain\Tax\Services\TaxRules;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/TestRegimes.php';
require_once __DIR__.'/../Entitlements/PlanTestHelpers.php';

/*
| SaaS.7 completion (B-4 markets, B-11, B-14): settlement. Each market prices in its own currency (never a converted
| INR price) and its invoices stay in that currency. Customer TDS is declared by an operator and makes the amount
| due exact; a short payment alone is never TDS. A foreign payment settles to Markedge in INR through the provider:
| that settlement and the rate it implies are recorded beside the payment, never changing the invoice or payment.
| Missing tax configuration never becomes 0 % tax: issue is refused.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    [$this->op, $this->checker] = [$this->setup['operator'], $this->setup['verifier']];
    $this->tenant = provisionTenant('Alpha');
    billingProfile($this->tenant, $this->setup['market'], $this->op);
    $this->invoices = app(Invoices::class);
    $this->payments = app(Payments::class);
    $this->invoice = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->op, ['1000.00']), null, 'April invoice', $this->op);   // 1075.00 with 7.5 % IGST
});

it('settles with declared customer TDS: payment plus TDS equal the amount due; a short payment alone is never TDS (critical 15)', function () {
    $tds = app(TdsSettlement::class);
    expect($this->invoice->due_date->toDateString())->toBe('2027-04-16');                     // B-11: net 15 from the issue date by default
    $short = $this->payments->recordBankTransfer($this->invoice, '1055.00', 'INR', 'UTR-T1', '2027-04-01', 'Paid net of something', $this->op);
    expect([$short->reconciliation_code, $this->invoice->fresh()->status])->toBe(['amount_mismatch', InvoiceStatus::Issued])
        ->and(fn () => $tds->declare($this->invoice, '1075.00', null, 'The whole invoice', $this->op))->toThrow(RuntimeException::class, 'less than the amount due')
        ->and(fn () => $tds->declare($this->invoice, '0.00', null, 'Nothing', $this->op))->toThrow(RuntimeException::class, 'more than zero')
        ->and(fn () => $tds->declare($this->invoice, '20.00', 'X', 'Bad certificate', $this->op))->toThrow(RuntimeException::class, 'certificate');

    // Declared with its certificate: the held payment is now exactly the amount due, and settles the invoice.
    $claim = $tds->declare($this->invoice, '20.00', 'tds-cert-q1-001', 'Form 16A received', $this->op);
    expect([$claim->status, $claim->certificate_reference, $claim->amount_minor, $this->invoices->amountDue($this->invoice->fresh())->toDecimal()])
        ->toBe([InvoiceTdsClaim::CERTIFIED, 'TDS-CERT-Q1-001', 2000, '1055.00'])
        ->and([$this->invoice->fresh()->status, $this->invoice->fresh()->paid_by_payment_id, $short->fresh()->reconciliation_status])->toBe([InvoiceStatus::Paid, $short->id, ReconciliationStatus::Matched])
        ->and(fn () => $tds->declare($this->invoice->fresh(), '1.00', null, 'Twice', $this->op))->toThrow(RuntimeException::class, 'unpaid issued invoice')
        ->and(fn () => $claim->fresh()->forceFill(['amount_minor' => 1])->save())->toThrow(RuntimeException::class, 'keeps its amount');

    // Declared before the payment, certificate later: partially paid only while the certificate is pending.
    $second = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->op, ['500.00']), null, 'May invoice', $this->op);   // 537.50
    $pending = $tds->declare($second, '10.00', null, 'Customer statement shows TDS', $this->op);
    $paid = $this->payments->recordBankTransfer($second, '527.50', 'INR', 'UTR-T2', '2027-04-01', 'Paid net of TDS', $this->op);
    expect([$pending->status, $paid->reconciliation_status, $second->fresh()->status, $second->fresh()->status->label()])
        ->toBe([InvoiceTdsClaim::PENDING, ReconciliationStatus::Matched, InvoiceStatus::PartiallyPaid, 'partially paid (TDS certificate pending)'])
        ->and($this->payments->recordBankTransfer($second, '527.50', 'INR', 'UTR-T3', '2027-04-01', 'Paid twice', $this->op)->reconciliation_code)->toBe('invoice_already_paid');
    $tds->certify($pending, 'TDS-CERT-Q1-002', 'Certificate received', $this->op);
    expect([$pending->fresh()->status, $second->fresh()->status, $second->fresh()->paid_by_payment_id])->toBe([InvoiceTdsClaim::CERTIFIED, InvoiceStatus::Paid, $paid->id]);
    foreach (['TDS_CLAIM_RECORDED', 'INVOICE_PARTIALLY_PAID', 'TDS_CLAIM_CERTIFIED'] as $action) {
        expect(AuditEvent::query()->withoutTenancy()->where('action', $action)->whereNotNull('tenant_id')->exists())->toBeTrue($action);
    }
});

it('prices the five markets each in its own currency, independently, and drafts each tenant in its market currency (critical 11, 12)', function () {
    $growth = publishedPlan($this->op, 'growth', ['leave' => true], '2027-04-01');
    // B-7 intent: the Indian entity sells in every market. Every amount here is fictional.
    $markets = ['IN-TEST' => $this->setup['market'], 'US-TEST' => billingMarket($this->op, 'US-TEST', 'USD', 'MARKEDGE-IN-TEST', 'en_US', ['US']),
        'UK-TEST' => billingMarket($this->op, 'UK-TEST', 'GBP', 'MARKEDGE-IN-TEST', 'en_GB', ['GB']), 'EU-TEST' => billingMarket($this->op, 'EU-TEST', 'EUR', 'MARKEDGE-IN-TEST', 'de_DE', ['DE', 'FR']),
        'AE-TEST' => billingMarket($this->op, 'AE-TEST', 'AED', 'MARKEDGE-IN-TEST', 'en_AE', ['AE'])];
    $amounts = ['IN-TEST' => '100.00', 'US-TEST' => '4.50', 'UK-TEST' => '3.75', 'EU-TEST' => '4.25', 'AE-TEST' => '16.50'];
    $versions = collect($amounts)->map(fn (string $amount, string $code) => pepmPrice($growth, $markets[$code], 'month', $amount, '2027-04-01', $this->op, $this->checker));
    expect($versions->map(fn ($v) => "{$v->currency->value} {$v->amount()->toDecimal()}")->all())
        ->toBe(['IN-TEST' => 'INR 100.00', 'US-TEST' => 'USD 4.50', 'UK-TEST' => 'GBP 3.75', 'EU-TEST' => 'EUR 4.25', 'AE-TEST' => 'AED 16.50']);

    // A new INR price never moves another market's price.
    pepmPrice($growth, $markets['IN-TEST'], 'month', '120.00', '2027-05-01', $this->op, $this->checker);
    $onSale = fn (string $code) => app(BillingCatalog::class)->catalogue($markets[$code], '2027-05-01')->map(fn ($r) => "{$r['amount']->currency->value} {$r['amount']->toDecimal()}")->all();
    expect([$onSale('IN-TEST'), $onSale('US-TEST'), $onSale('AE-TEST')])->toBe([['INR 120.00'], ['USD 4.50'], ['AED 16.50']]);

    // A US business is billed in USD from the USD price; the draft is in USD and nothing is converted.
    $us = provisionTenant('US Customer');
    billingProfile($us, $markets['US-TEST'], $this->op, ['country' => 'US', 'subdivision' => 'US-CA', 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    $sub = app(CommercialSubscriptions::class)->start($us, $growth, '2027-04-01', null, 'US contract', $this->op);
    app(BillingTerms::class)->set($sub, $versions['US-TEST'], '2027-04-01', 'US order form', $this->op);
    foreach (range(1, 3) as $i) {
        staff($us, '2027-03-01');
    }
    $this->travelTo('2027-05-01 06:00:00');
    app(BillingPeriods::class)->run($us);
    $draft = billingPeriodsOf($us)['monthly_arrears 2027-04-01']->invoice;
    expect([$draft->currency->value, $draft->market_id, $draft->subtotal()->toDecimal(), $draft->status])->toBe(['USD', $markets['US-TEST']->id, '13.50', InvoiceStatus::Draft]);
});

it('never turns missing or pending tax configuration into 0 % tax: issue is refused and the draft stays a draft (critical 22, 23)', function () {
    // Pending: India's rule withdrawn. The draft is refused, never issued at 0 %.
    $draft = draftInvoice($this->tenant, $this->setup['market'], $this->op, ['200.00']);
    app(TaxRules::class)->retire($this->setup['rule'], 'Rule under review', $this->setup['verifier']);
    $next = InvoiceNumberSeries::query()->where('document_type', 'invoice')->sole()->next_sequence;
    expect(fn () => $this->invoices->issue($draft, null, 'Issue anyway', $this->op))->toThrow(RuntimeException::class, 'Tax cannot be determined')
        ->and($this->invoices->readiness($draft)['ready'])->toBeFalse()
        ->and([$draft->fresh()->status, $draft->fresh()->tax_minor, $draft->fresh()->number])->toBe([InvoiceStatus::Draft, 0, null])
        ->and(InvoiceNumberSeries::query()->where('document_type', 'invoice')->sole()->next_sequence)->toBe($next)
        ->and(app(TaxEngine::class)->status('IN'))->toBe(JurisdictionStatus::PendingTaxReview);

    // Unsupported or not yet advised: a foreign customer of the Indian entity (export of services, B-8 pending) and a
    // country PeopleOS has no tax module for. Both refused; nothing is ever "supported" by default.
    verifiedIndiaRule($this->op, $this->setup['verifier']);
    foreach (['US' => ['US-CA', 'export of services'], 'AU' => [null, 'export of services']] as $country => [$subdivision, $message]) {
        $tenant = provisionTenant("Customer {$country}");
        $market = BillingMarket::query()->where('code', "{$country}-PENDING")->first() ?? billingMarket($this->op, "{$country}-PENDING", 'INR', 'MARKEDGE-IN-TEST', 'en_IN', [$country]);
        billingProfile($tenant, $market, $this->op, ['country' => $country, 'subdivision' => $subdivision, 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
        $foreign = draftInvoice($tenant, $market, $this->op, ['100.00']);
        expect(fn () => $this->invoices->issue($foreign, null, 'Foreign customer', $this->op))->toThrow(RuntimeException::class, $message)
            ->and($foreign->fresh()->status)->toBe(InvoiceStatus::Draft);
    }
    expect(app(TaxEngine::class)->status('US'))->toBe(JurisdictionStatus::PendingTaxReview)   // destination rules representable, none verified
        ->and(app(TaxEngine::class)->status('AU'))->toBe(JurisdictionStatus::NotSupported)
        ->and(app(TaxEngine::class)->status('IN'))->toBe(JurisdictionStatus::Configured)
        ->and(collect(JurisdictionStatus::cases())->map->value->contains('supported'))->toBeTrue()          // representable…
        ->and(collect(['IN', 'US', 'GB', 'AE', 'DE', 'AU'])->map(fn ($c) => app(TaxEngine::class)->status($c))->contains(JurisdictionStatus::Supported))->toBeFalse();   // …never claimed
});

it('records the INR settlement of a foreign-currency payment beside it, never changing the invoice or payment (critical 13, 14)', function () {
    // A USD invoice of a fictional US test entity under a test-only regime (to have an issued foreign invoice).
    useTestRegimes();
    $this->invoices = app(Invoices::class);
    $rules = app(TaxRules::class);
    $rule = $rules->draft(TaxRegime::UsSalesTax, 'US', 'US-TX', 'peopleos.subscription', '2027-04-01', ['state' => [['type' => 'STATE', 'rate' => '6.25']]], 'half_up', null, 'Test-only rule', $this->op);
    $rules->submit($rule, 'Review please', $this->op);
    $rules->verify($rule, 'TEST-ONLY', null, $this->checker);
    app(SupplierProfiles::class)->record('MARKEDGE-US-TEST', ['legal_name' => 'Markedge US Test Ltd', 'address_line1' => '1 Test Way', 'city' => 'Testville', 'country' => 'US',
        'subdivision' => 'US-TX', 'tax_id_type' => 'US_SALES_TAX_PERMIT', 'tax_id_value' => 'TX-00000'], '2027-04-01', 'Fictional entity', $this->op);
    invoiceSeries($this->op, 'MARKEDGE-US-TEST', 'US-');
    $usd = billingMarket($this->op, 'US-TEST', 'USD', 'MARKEDGE-US-TEST', 'en_US', ['US']);
    $us = provisionTenant('Texas Customer');
    billingProfile($us, $usd, $this->op, ['country' => 'US', 'subdivision' => 'US-TX', 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    $invoice = $this->invoices->issue(draftInvoice($us, $usd, $this->op, ['100.00']), null, 'US invoice', $this->op);
    expect($invoice->total()->toDecimal())->toBe('106.25');
    $before = $invoice->fresh()->only(['currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'snapshot']);

    // Razorpay (test mode) collects USD and settles INR to Markedge: the payment captured reports its INR base amount.
    razorpayTestMode();
    $api = fakeRazorpay();
    $payment = $this->payments->initiate($invoice, 'razorpay', 'Card payment link', $this->op);
    [$body, $headers] = razorpayWebhook('evt_FAKE_USD_1', 'payment.captured', razorpayPayment($payment->provider_reference, 10625, 'USD', 900000));
    postWebhook($this, $body, $headers, 'razorpay')->assertOk();
    $payment = $payment->fresh();
    expect([$payment->status, $payment->reconciliation_status, $payment->amount_minor, $payment->currency->value, $invoice->fresh()->status])
        ->toBe([PaymentStatus::Succeeded, ReconciliationStatus::Matched, 10625, 'USD', InvoiceStatus::Paid])
        ->and([$payment->settlement_amount_minor, $payment->settlement_currency, (string) $payment->settlement_fx_rate, $payment->settlement_fx_source, $payment->provider_transaction_reference])
        ->toBe([900000, 'INR', '84.7058823529', 'razorpay:base_amount', 'pay_FAKE000001'])
        ->and($payment->settlement_recorded_at)->not->toBeNull()
        ->and($invoice->fresh()->only(['currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'snapshot']))->toBe($before)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PAYMENT_SETTLEMENT_RECORDED')->whereNotNull('tenant_id')->count())->toBe(1)
        // The snapshot is written once: it never changes, even directly.
        ->and(fn () => $payment->forceFill(['settlement_amount_minor' => 1])->save())->toThrow(RuntimeException::class);

    // A bank transfer from abroad may record what the bank credited in INR, the same way.
    $second = $this->invoices->issue(draftInvoice($us, $usd, $this->op, ['100.00']), null, 'Second US invoice', $this->op);
    $wire = $this->payments->recordBankTransfer($second, '106.25', 'USD', 'SWIFT-0001', '2027-04-01', 'SWIFT received', $this->op, '8950.00', 'INR');
    expect([$wire->reconciliation_status, $wire->settlement_amount_minor, $wire->settlement_currency, (string) $wire->settlement_fx_rate, $wire->settlement_fx_source, $second->fresh()->status])
        ->toBe([ReconciliationStatus::Matched, 895000, 'INR', '84.2352941176', 'bank_advice', InvoiceStatus::Paid])
        ->and($second->fresh()->total_minor)->toBe(10625);
});
