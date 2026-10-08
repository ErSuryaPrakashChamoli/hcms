<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Models\InvoiceTaxLine;
use App\Domain\Billing\Models\PlanPrice;
use App\Domain\Billing\Models\PlanPriceVersion;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Providers\SandboxProvider;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\ProviderEvents;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Billing/BillingTestHelpers.php';
require_once __DIR__.'/../Feature/Entitlements/PlanTestHelpers.php';

/*
 | SaaS.7: concurrent billing on MySQL. Whatever the interleaving: invoice numbers stay unique, consecutive and
 | gap-free; a draft is issued once; a duplicate webhook is applied once; an invoice is paid once; a price has one
 | draft; profile versions never collide; totals always equal lines plus tax; both audit chains stay valid.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'cache.default' => 'database', 'cache.stores.database.connection' => 'concurrency',
        'peopleos.billing.sandbox.enabled' => true, 'peopleos.billing.sandbox.webhook_secret' => 'sandbox-race-secret-0123456789']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $suffix = substr(uniqid(), -6);
    [$this->op, $this->checker] = billingOperators();
    $this->entity = "ME-RACE-{$suffix}";
    indiaSupplier($this->op, $this->entity);
    $this->market = billingMarket($this->op, "R{$suffix}", 'INR', $this->entity);
    invoiceSeries($this->op, $this->entity, "R{$suffix}/");
    if (! \App\Domain\Tax\Models\TaxRule::query()->where('status', 'verified')->exists()) {
        verifiedIndiaRule($this->op, $this->checker);
    }
    $this->tenant = provisionTenant('Billing race '.$suffix);
    billingProfile($this->tenant, $this->market, $this->op);
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    actAsTenant($this->tenant);
    $series = InvoiceNumberSeries::query()->where('supplier_entity', $this->entity)->sole();
    $sequences = Invoice::query()->where('series_id', $series->id)->orderBy('sequence')->pluck('sequence')->all();
    expect($sequences)->toBe($sequences === [] ? [] : range(1, count($sequences)))                         // gap-free and unique
        ->and($series->fresh()->next_sequence)->toBe(count($sequences) + 1);
    foreach (Invoice::query()->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Paid])->get() as $invoice) {
        $tax = (int) InvoiceTaxLine::query()->where('invoice_id', $invoice->id)->sum('tax_minor');
        expect($invoice->total_minor)->toBe($invoice->subtotal_minor + $tax)->and($invoice->tax_minor)->toBe($tax);
        $matched = Payment::query()->where(['invoice_id' => $invoice->id, 'reconciliation_status' => ReconciliationStatus::Matched])->count();
        expect($matched)->toBe($invoice->status === InvoiceStatus::Paid ? 1 : 0);
    }
    expect(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

/** Runs $call in a forked process with fresh instances. */
function billingCall(object $test, Closure $call): Closure
{
    return function () use ($test, $call) {
        $call(Tenant::query()->findOrFail($test->tenant->id), $test->op->fresh());
    };
}

it('1. numbers two invoices issued at the same moment consecutively, without a gap or a duplicate', function () {
    $a = draftInvoice($this->tenant, $this->market, $this->op, ['100.00']);
    $b = draftInvoice($this->tenant, $this->market, $this->op, ['200.00']);
    $results = race([
        billingCall($this, fn ($t, $op) => app(Invoices::class)->issue(Invoice::query()->withoutTenancy()->findOrFail($a->id), null, 'First', $op)),
        billingCall($this, fn ($t, $op) => app(Invoices::class)->issue(Invoice::query()->withoutTenancy()->findOrFail($b->id), null, 'Second', $op)),
    ], slow: ['eloquent.creating: '.InvoiceTaxLine::class]);

    expect($results)->toBe(['ok', 'ok']);
    actAsTenant($this->tenant);
    expect(Invoice::query()->whereNotNull('number')->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
});

it('2. issues the same draft once when two operators issue it at once', function () {
    $draft = draftInvoice($this->tenant, $this->market, $this->op, ['100.00']);
    $issue = billingCall($this, fn ($t, $op) => app(Invoices::class)->issue(Invoice::query()->withoutTenancy()->findOrFail($draft->id), null, 'Issue it', $op));
    expect(race([$issue, $issue], slow: ['eloquent.creating: '.InvoiceTaxLine::class]))->toBe(['ok', 'ok']);

    actAsTenant($this->tenant);
    expect(InvoiceTaxLine::query()->where('invoice_id', $draft->id)->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where(['tenant_id' => $this->tenant->id, 'action' => 'INVOICE_ISSUED'])->count())->toBe(1);
});

it('3. applies a duplicate webhook delivered twice at the same moment once', function () {
    $invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['100.00']), null, 'Issued', $this->op);
    $payment = app(Payments::class)->initiate($invoice, 'sandbox', 'Link sent', $this->op);
    $body = json_encode(['id' => 'evt_race_'.$payment->id, 'type' => 'payment.succeeded', 'data' => ['reference' => $payment->provider_reference, 'amount_minor' => $invoice->total_minor, 'currency' => 'INR']]);
    $headers = array_change_key_case(array_map(fn ($v) => [$v], SandboxProvider::signedHeaders($body)), CASE_LOWER);
    $deliver = fn () => app(ProviderEvents::class)->receive('sandbox', $body, $headers);
    expect(race([$deliver, $deliver], slow: ['eloquent.creating: '.PaymentProviderEvent::class]))->toBe(['ok', 'ok']);

    actAsTenant($this->tenant);
    expect(PaymentProviderEvent::query()->where('provider', 'sandbox')->where('event_id', 'evt_race_'.$payment->id)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(AuditEvent::query()->withoutTenancy()->where(['tenant_id' => $this->tenant->id, 'action' => 'PAYMENT_SUCCEEDED'])->count())->toBe(1);
});

it('4. pays an invoice once when a provider confirmation races a recorded bank transfer', function () {
    $invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['100.00']), null, 'Issued', $this->op);
    $payment = app(Payments::class)->initiate($invoice, 'sandbox', 'Link sent', $this->op);
    $body = json_encode(['id' => 'evt_both_'.$payment->id, 'type' => 'payment.succeeded', 'data' => ['reference' => $payment->provider_reference, 'amount_minor' => $invoice->total_minor, 'currency' => 'INR']]);
    $headers = array_change_key_case(array_map(fn ($v) => [$v], SandboxProvider::signedHeaders($body)), CASE_LOWER);
    $results = race([
        fn () => app(ProviderEvents::class)->receive('sandbox', $body, $headers),
        billingCall($this, fn ($t, $op) => app(Payments::class)->recordBankTransfer(Invoice::query()->withoutTenancy()->findOrFail($invoice->id), $invoice->total()->toDecimal(), 'INR', 'UTR-RACE-'.$payment->id, now()->toDateString(), 'Also paid by bank', $op)),
    ], slow: ['eloquent.updating: '.Invoice::class]);

    expect($results)->toBe(['ok', 'ok']);
    actAsTenant($this->tenant);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::query()->where('invoice_id', $invoice->id)->where('reconciliation_status', ReconciliationStatus::Exception)->pluck('reconciliation_code')->all())->toBe(['invoice_already_paid'])
        ->and(AuditEvent::query()->withoutTenancy()->where(['tenant_id' => $this->tenant->id, 'action' => 'INVOICE_PAID'])->count())->toBe(1);
});

it('5. keeps one draft when two operators draft the same price at once', function () {
    $price = app(BillingCatalog::class)->createPrice(publishedPlan($this->op, 'race-'.substr(uniqid(), -6), ['leave' => true], now()->toDateString()), $this->market, 'month', 'flat', 'Race price', $this->op);
    $draft = billingCall($this, fn ($t, $op) => app(BillingCatalog::class)->draftPriceVersion(PlanPrice::query()->findOrFail($price->id), '10.00', 'Concurrent draft', $op));
    $results = race([$draft, $draft], slow: ['eloquent.creating: '.PlanPriceVersion::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already has a draft')
        ->and(PlanPriceVersion::query()->where('plan_price_id', $price->id)->count())->toBe(1);
});

it('6. never gives two billing profile versions the same number', function () {
    $record = fn (string $name) => billingCall($this, fn ($t, $op) => billingProfile($t, \App\Domain\Billing\Models\BillingMarket::query()->findOrFail($this->market->id), $op, ['legal_name' => $name]));
    $results = race([$record('Version A Ltd'), $record('Version B Ltd')], slow: ['eloquent.creating: '.TenantBillingProfile::class]);

    actAsTenant($this->tenant);
    $versions = TenantBillingProfile::query()->orderBy('version')->pluck('version')->all();
    expect($versions)->toBe(range(1, count($versions)))->and(count($versions))->toBe(1 + collect($results)->filter(fn ($r) => $r === 'ok')->count())
        ->and(collect($results)->every(fn ($r) => $r === 'ok' || str_contains($r, 'Deadlock') || str_contains($r, 'Duplicate')))->toBeTrue();
});
