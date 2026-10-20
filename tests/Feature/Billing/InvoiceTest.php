<?php

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Billing\Services\InvoicePresentation;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Billing\Support\InvoiceLineInput;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Tax\Enums\TaxRegime;
use App\Domain\Tax\Services\TaxRules;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

require_once __DIR__.'/BillingTestHelpers.php';
require_once __DIR__.'/TestRegimes.php';

/*
| SaaS.7: invoices. Issue fixes the number (gap-free, from the series covering the day), the tax (from the engine,
| refused when it cannot be determined), the totals and the supplier, customer and tax snapshots; nothing financial
| changes afterwards, whatever later happens to profiles, registrations, rules or prices. Invoices keep their
| currency; presentation follows the market's locale and the regime's own rows, from the snapshot only.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    $this->operator = $this->setup['operator'];
    $this->invoices = app(Invoices::class);
    $this->tenant = provisionTenant('Alpha');
});

function inTenant($tenant, Closure $work): mixed
{
    return app(TenantContext::class)->runAs($tenant, $work);
}

it('issues an India GST invoice: gap-free number, tax per line, totals and snapshots fixed in one transaction', function () {
    billingProfile($this->tenant, $this->setup['market'], $this->operator);              // Karnataka customer, Maharashtra supplier
    $draft = draftInvoice($this->tenant, $this->setup['market'], $this->operator, ['1000.00', '0.50', '333.33']);
    expect($draft->status)->toBe(InvoiceStatus::Draft)->and($draft->number)->toBeNull()->and($draft->subtotal_minor)->toBe(133383)
        ->and($this->invoices->readiness($draft))->toMatchArray(['ready' => true, 'problems' => []]);

    $issued = $this->invoices->issue($draft, '2027-04-30', 'Monthly invoice', $this->operator);
    expect([$issued->status, $issued->number, $issued->issue_date->toDateString(), $issued->tax_regime, $issued->tax_treatment])
        ->toBe([InvoiceStatus::Issued, 'TST/000001', '2027-04-01', 'IN_GST', 'standard']);
    $tax = inTenant($this->tenant, fn () => InvoiceTaxLine::query()->where('invoice_id', $issued->id)->orderBy('line_no')->get());
    // IGST 7.5 %: 75.00, 0.0375 → 0.04, 24.99975 → 25.00 (half up, per line)
    expect($tax->map(fn ($t) => [$t->line_no, $t->tax_type, (string) $t->rate, $t->tax_minor])->all())
        ->toBe([[1, 'IGST', '7.5000', 7500], [2, 'IGST', '7.5000', 4], [3, 'IGST', '7.5000', 2500]])
        ->and([$issued->tax_minor, $issued->total_minor])->toBe([10004, 143387])
        ->and($issued->snapshot['tax']['determination']['place_of_supply']['subdivision'])->toBe('IN-KA')
        ->and($issued->snapshot['supplier']['tax_id'])->toBe($this->setup['supplier']->tax_id_value)
        ->and($issued->snapshot['customer']['tax_id'])->toBe(fictionalGstin('29', '2'))
        ->and($issued->snapshot['tax']['rule']['verification_reference'])->toBe('TEST-REVIEW-1');

    // Issuing twice changes nothing; the next invoice takes the next number.
    expect($this->invoices->issue($issued, null, 'Again', $this->operator)->number)->toBe('TST/000001');
    $second = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), null, 'Second', $this->operator);
    expect($second->number)->toBe('TST/000002')->and(InvoiceNumberSeries::query()->sole()->next_sequence)->toBe(3);
});

it('keeps an issued invoice exactly as issued after the customer, registration, supplier, rule and price change', function () {
    billingProfile($this->tenant, $this->setup['market'], $this->operator);
    $issued = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), '2027-05-31', 'April invoice', $this->operator);
    $before = [$issued->fresh()->toArray(), inTenant($this->tenant, fn () => InvoiceTaxLine::query()->where('invoice_id', $issued->id)->get()->toArray()),
        app(InvoicePresentation::class)->document($issued->fresh())];
    expect($before[1])->not->toBeEmpty()->and($before[2]['lines'])->toHaveCount(1)->and($before[2]['tax_summary'])->toHaveCount(1)->and($before[2]['regime_rows'])->not->toBeEmpty();

    $this->travelTo('2027-04-20 09:00:00');
    billingProfile($this->tenant, $this->setup['market'], $this->operator, ['legal_name' => 'Renamed Customer Ltd', 'subdivision' => 'IN-MH']);   // new GSTIN, new state
    supplierVersion('MARKEDGE-IN-TEST', ['legal_name' => 'Renamed Supplier Ltd', 'address_line1' => 'New Street', 'city' => 'Newpur', 'country' => 'IN',
        'subdivision' => 'IN-MH', 'tax_id_type' => 'IN_GSTIN', 'tax_id_value' => fictionalGstin('27', '3')], '2027-04-20', $this->operator);
    $newRule = verifiedIndiaRule($this->operator, $this->setup['verifier'], '2027-04-20', ['intra_state' => [['type' => 'CGST', 'rate' => '1'], ['type' => 'SGST', 'rate' => '1']], 'inter_state' => [['type' => 'IGST', 'rate' => '2']]]);

    expect([$issued->fresh()->toArray(), inTenant($this->tenant, fn () => InvoiceTaxLine::query()->where('invoice_id', $issued->id)->get()->toArray()),
        app(InvoicePresentation::class)->document($issued->fresh())])->toBe($before);
    // A new invoice uses everything as it is now: intra-state, the new rule, the new names.
    $may = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), null, 'Later invoice', $this->operator);
    expect([$may->snapshot['tax']['determination']['outcome'], $may->tax_rule_id, $may->snapshot['customer']['legal_name'], $may->snapshot['supplier']['legal_name'], $may->tax_minor])
        ->toBe(['intra_state', $newRule->id, 'Renamed Customer Ltd', 'Renamed Supplier Ltd', 2000]);
});

it('refuses to issue when anything needed is missing, and gives the number back', function () {
    $draft = draftInvoice($this->tenant, $this->setup['market'], $this->operator);
    expect(fn () => $this->invoices->issue($draft, null, 'No profile', $this->operator))->toThrow(RuntimeException::class, 'no billing profile');
    billingProfile($this->tenant, $this->setup['market'], $this->operator, ['country' => 'US', 'subdivision' => 'US-CA', 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    expect(fn () => $this->invoices->issue($draft, null, 'Export', $this->operator))->toThrow(RuntimeException::class, 'export of services')
        ->and($this->invoices->readiness($draft)['problems'][0])->toContain('export of services');
    billingProfile($this->tenant, $this->setup['market'], $this->operator, ['special_tax_status' => 'sez']);
    expect(fn () => $this->invoices->issue($draft, null, 'SEZ customer', $this->operator))->toThrow(RuntimeException::class, 'special status');
    billingProfile($this->tenant, $this->setup['market'], $this->operator);
    expect(fn () => $this->invoices->issue($draft, '2027-03-01', 'Past due date', $this->operator))->toThrow(RuntimeException::class, 'due date');
    app(TaxRules::class)->retire($this->setup['rule'], 'Rule withdrawn', $this->setup['verifier']);
    expect(fn () => $this->invoices->issue($draft, null, 'No rule', $this->operator))->toThrow(RuntimeException::class, 'No verified IN_GST rule');
    verifiedIndiaRule($this->operator, $this->setup['verifier']);
    app(InvoiceSeries::class)->close(InvoiceNumberSeries::query()->sole(), 'Year closed', $this->operator);
    expect(fn () => $this->invoices->issue($draft, null, 'No series', $this->operator))->toThrow(RuntimeException::class, 'No open invoice number series');
    $other = billingMarket($this->operator, 'IN-TWO', 'INR', 'MARKEDGE-IN-TEST', 'en_IN');
    $elsewhere = draftInvoice($this->tenant, $other, $this->operator);
    invoiceSeries($this->operator, 'MARKEDGE-IN-TEST', 'TS2/', '2027-04-01', '2027-12-31');
    expect(fn () => $this->invoices->issue($elsewhere, null, 'Other market', $this->operator))->toThrow(RuntimeException::class, 'another market');

    // Every refusal rolled back: no number was consumed, nothing was taxed.
    expect(InvoiceNumberSeries::query()->where('prefix', 'TS2/')->sole()->next_sequence)->toBe(1)
        ->and(inTenant($this->tenant, fn () => InvoiceTaxLine::query()->count()))->toBe(0)
        ->and($draft->fresh()->status)->toBe(InvoiceStatus::Draft);
    $issued = $this->invoices->issue($draft, null, 'Now it can be issued', $this->operator);
    expect($issued->number)->toBe('TS2/000001');
});

it('never edits an issued invoice: amounts, tax, currency, lines and tax lines are final; drafts are discarded, not deleted', function () {
    billingProfile($this->tenant, $this->setup['market'], $this->operator);
    $issued = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), null, 'Issued', $this->operator);
    foreach (['total_minor' => 1, 'tax_minor' => 0, 'subtotal_minor' => 1, 'currency' => 'USD', 'number' => 'X', 'snapshot' => [], 'status' => 'draft', 'issue_date' => '2027-01-01'] as $field => $value) {
        expect(fn () => inTenant($this->tenant, fn () => $issued->fresh()->forceFill([$field => $value])->save()))->toThrow(RuntimeException::class, 'never changes');
    }
    expect(fn () => inTenant($this->tenant, fn () => InvoiceLine::query()->first()->forceFill(['amount_minor' => 1])->save()))->toThrow(RuntimeException::class)
        ->and(fn () => inTenant($this->tenant, fn () => InvoiceTaxLine::query()->first()->forceFill(['tax_minor' => 0])->save()))->toThrow(RuntimeException::class)
        ->and(fn () => inTenant($this->tenant, fn () => InvoiceTaxLine::query()->first()->delete()))->toThrow(RuntimeException::class)
        ->and(fn () => inTenant($this->tenant, fn () => $issued->fresh()->delete()))->toThrow(RuntimeException::class)
        ->and(fn () => $this->invoices->discard($issued, 'Too late', $this->operator))->toThrow(RuntimeException::class, 'credit note');

    $draft = draftInvoice($this->tenant, $this->setup['market'], $this->operator, ['5.00'], 'run-2027-04:alpha');
    expect(draftInvoice($this->tenant, $this->setup['market'], $this->operator, ['5.00'], 'run-2027-04:alpha')->id)->toBe($draft->id);   // idempotent draft
    $this->invoices->discard($draft, 'Not needed', $this->operator);
    expect($draft->fresh()->status)->toBe(InvoiceStatus::Discarded)
        ->and(fn () => $this->invoices->issue($draft, null, 'Revive', $this->operator))->toThrow(RuntimeException::class, 'discarded');
});

it('keeps each invoice in its currency, with generic tax lines for VAT and sales tax and the regime-specific rows only where they apply', function () {
    useTestRegimes();
    $this->invoices = app(Invoices::class);
    [$operator, $verifier] = [$this->operator, $this->setup['verifier']];
    $rules = app(TaxRules::class);
    foreach ([[TaxRegime::GbVat, 'GB', null, ['domestic' => [['type' => 'VAT', 'rate' => '20']], 'reverse_charge' => []]],
        [TaxRegime::UsSalesTax, 'US', 'US-TX', ['state' => [['type' => 'STATE', 'rate' => '6.25']]]]] as [$regime, $country, $sub, $outcomes]) {
        $rule = $rules->draft($regime, $country, $sub, 'peopleos.subscription', '2027-04-01', $outcomes, 'half_up', null, 'Test-only rule', $operator);
        $rules->submit($rule, 'Review please', $operator);
        $rules->verify($rule, 'TEST-ONLY', null, $verifier);
    }
    $supplier = fn (string $entity, string $country, ?string $sub, ?string $type, ?string $id) => supplierVersion($entity, ['legal_name' => "Markedge {$country} Test Ltd",
        'address_line1' => '1 Test Way', 'city' => 'Testville', 'country' => $country, 'subdivision' => $sub, 'tax_id_type' => $type, 'tax_id_value' => $id], '2027-04-01', $operator);
    $supplier('MARKEDGE-GB-TEST', 'GB', null, 'GB_VAT', 'GB000000000');
    $supplier('MARKEDGE-US-TEST', 'US', 'US-TX', 'US_SALES_TAX_PERMIT', 'TX-00000');
    $gbp = billingMarket($operator, 'GB-TEST', 'GBP', 'MARKEDGE-GB-TEST', 'en_GB', ['GB']);
    $eur = billingMarket($operator, 'EU-FROM-GB', 'EUR', 'MARKEDGE-GB-TEST', 'de_DE', ['DE']);
    $usd = billingMarket($operator, 'US-TEST', 'USD', 'MARKEDGE-US-TEST', 'en_US', ['US']);
    invoiceSeries($operator, 'MARKEDGE-GB-TEST', 'GB-');
    invoiceSeries($operator, 'MARKEDGE-US-TEST', 'US-');

    $uk = provisionTenant('UK Customer');
    billingProfile($uk, $gbp, $operator, ['country' => 'GB', 'subdivision' => null, 'tax_registration' => 'unregistered', 'tax_id_type' => null, 'tax_id_value' => null]);
    $de = provisionTenant('German Customer');
    billingProfile($de, $eur, $operator, ['country' => 'DE', 'subdivision' => null, 'tax_id_type' => 'EU_VAT_ID', 'tax_id_value' => 'DE000000000']);
    $us = provisionTenant('Texas Customer');
    policyDefault('billing.b2b_only', false);   // B2C stays representable (B-5 launches B2B only)
    billingProfile($us, $usd, $operator, ['customer_type' => 'consumer', 'country' => 'US', 'subdivision' => 'US-TX', 'tax_registration' => 'not_applicable', 'tax_id_type' => null, 'tax_id_value' => null]);

    $gbInvoice = $this->invoices->issue(draftInvoice($uk, $gbp, $operator, ['1250.00']), null, 'UK invoice', $operator);
    $deInvoice = $this->invoices->issue(draftInvoice($de, $eur, $operator, ['1250.00']), null, 'EU reverse charge', $operator);
    $usInvoice = $this->invoices->issue(draftInvoice($us, $usd, $operator, ['99.99']), null, 'US invoice', $operator);
    $present = fn ($i) => app(InvoicePresentation::class)->document($i->fresh());

    expect([$gbInvoice->currency->value, $gbInvoice->tax_minor, $gbInvoice->number, $present($gbInvoice)['totals']['total'], $present($gbInvoice)['tax_summary'][0]['type']])
        ->toBe(['GBP', 25000, 'GB-000001', '£1,500.00', 'VAT'])
        ->and([$deInvoice->currency->value, $deInvoice->tax_treatment, $deInvoice->tax_minor, $present($deInvoice)['tax_summary'], $present($deInvoice)['totals']['total']])
        ->toBe(['EUR', 'reverse_charge', 0, [], "1.250,00\u{a0}€"])
        ->and($present($deInvoice)['treatment'])->toBe('Reverse charge')
        ->and([$usInvoice->currency->value, $usInvoice->tax_minor, $present($usInvoice)['totals']['total']])->toBe(['USD', 625, '$106.24'])
        ->and($present($usInvoice)['regime_rows'])->toBe([])                                          // no GST rows on a US invoice
        ->and(collect([$gbInvoice, $usInvoice])->every(fn ($i) => inTenant(Tenant::query()->find($i->tenant_id), fn () => InvoiceTaxLine::query()->where('invoice_id', $i->id)->pluck('tax_type')->intersect(['CGST', 'SGST', 'IGST', 'UTGST'])->isEmpty())))->toBeTrue();

    // An Indian invoice carries the GST rows; amounts are shown in its own locale.
    billingProfile($this->tenant, $this->setup['market'], $operator);
    $inInvoice = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $operator, ['100000.00']), null, 'India invoice', $operator);
    expect(collect($present($inInvoice)['regime_rows'])->pluck('label')->all())->toBe(['Supplier GSTIN', 'Customer GSTIN', 'Place of supply', 'Supply type', 'SAC'])
        ->and($present($inInvoice)['totals']['total'])->toBe('₹1,07,500.00');

    // Mixed currencies cannot be drafted.
    expect(fn () => $this->invoices->draft($this->tenant, $this->setup['market'], [new InvoiceLineInput('USD line', 1, Money::parse('1', 'USD'))], 'Mixed currency', $operator))
        ->toThrow(RuntimeException::class, 'bills in INR');
});
