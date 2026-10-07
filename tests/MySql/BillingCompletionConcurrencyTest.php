<?php

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingPeriod;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Services\BillingPeriods;
use App\Domain\Billing\Services\BillingTerms;
use App\Domain\Billing\Services\CreditNotes;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\Refunds;
use App\Domain\Payments\Services\TdsSettlement;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Subscriptions\Services\CommercialSubscriptions;
use App\Domain\Tax\Models\TaxRule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ConcurrencyHelpers.php';
require_once __DIR__.'/../Feature/Billing/BillingTestHelpers.php';
require_once __DIR__.'/../Feature/Entitlements/PlanTestHelpers.php';

/*
 | SaaS.7 completion: concurrent billing operations on MySQL. Whatever the interleaving: a billing period and its
 | draft exist once; an approval executes once; credit-note numbers stay unique and gap-free; a declared TDS and a
 | payment settle the invoice once; refunds never exceed their credit note; both audit chains stay valid.
 */

beforeEach(function () {
    $database = (string) env('PEOPLEOS_MYSQL_CONCURRENCY_DB', '');
    if ($database === '' || ! str_contains($database, 'concurrency') || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Set PEOPLEOS_MYSQL_CONCURRENCY_DB to a disposable MySQL database (name containing "concurrency") and enable pcntl.');
    }
    config(['database.connections.concurrency' => array_merge(config('database.connections.mysql'), ['database' => $database]), 'database.default' => 'concurrency',
        'queue.default' => 'sync', 'cache.default' => 'database', 'cache.stores.database.connection' => 'concurrency']);
    DB::purge('concurrency');
    if (! ($GLOBALS['peopleos_concurrency_migrated'] ?? false)) {
        Artisan::call('migrate:fresh', ['--database' => 'concurrency', '--force' => true]);
        $GLOBALS['peopleos_concurrency_migrated'] = true;
    }
    $suffix = substr(uniqid(), -6);
    [$this->op, $this->checker] = billingOperators();
    $this->checker2 = platformAdmin();
    $this->entity = "ME-CMP-{$suffix}";
    indiaSupplier($this->op, $this->entity);
    $this->market = billingMarket($this->op, "C{$suffix}", 'INR', $this->entity);
    invoiceSeries($this->op, $this->entity, "I{$suffix}/");
    app(InvoiceSeries::class)->create($this->entity, "N{$suffix}/", now()->toDateString(), now()->addYear()->toDateString(), 6, 'Credit note series', $this->op, 'credit_note');
    if (! TaxRule::query()->where('status', 'verified')->exists()) {
        verifiedIndiaRule($this->op, $this->checker);
    }
    $this->tenant = provisionTenant('Completion race '.$suffix);
    billingProfile($this->tenant, $this->market, $this->op);
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    actAsTenant($this->tenant);
    $series = InvoiceNumberSeries::query()->where(['supplier_entity' => $this->entity, 'document_type' => 'credit_note'])->sole();
    $sequences = CreditNote::query()->where('series_id', $series->id)->orderBy('sequence')->pluck('sequence')->all();
    expect($sequences)->toBe($sequences === [] ? [] : range(1, count($sequences)))->and($series->fresh()->next_sequence)->toBe(count($sequences) + 1);
    foreach (Invoice::query()->whereNotNull('number')->get() as $invoice) {
        expect((int) CreditNote::query()->where('invoice_id', $invoice->id)->sum('total_minor'))->toBeLessThanOrEqual($invoice->total_minor);
    }
    foreach (CreditNote::query()->get() as $note) {
        expect((int) Refund::query()->where('credit_note_id', $note->id)->where('status', '<>', Refund::FAILED)->sum('amount_minor'))->toBeLessThanOrEqual($note->total_minor);
    }
    expect(FinancialApproval::query()->where('status', ApprovalStatus::Approved)->whereNull('executed_at')->count())->toBe(0)       // approved means carried out
        ->and(app(AuditIntegrityVerifier::class)->verify($this->tenant->id)['valid'])->toBeTrue()
        ->and(app(AuditIntegrityVerifier::class)->verify(null)['valid'])->toBeTrue();
});

function completionCall(object $test, Closure $call, ?User $actor = null): Closure
{
    return function () use ($test, $call, $actor) {
        $call(Tenant::query()->findOrFail($test->tenant->id), ($actor ?? $test->op)->fresh());
    };
}

it('7. calculates a billing period and drafts its invoice once when two billing runs overlap', function () {
    $plan = publishedPlan($this->op, 'cmp-'.substr(uniqid(), -6), ['leave' => true], now()->toDateString());
    $price = pepmPrice($plan, $this->market, 'month', '100.00', now()->toDateString(), $this->op, $this->checker);
    $sub = app(CommercialSubscriptions::class)->start($this->tenant, $plan, now()->toDateString(), null, 'Race contract', $this->op);
    app(BillingTerms::class)->set($sub, $price, now()->toDateString(), 'Race terms', $this->op);
    staff($this->tenant, now()->subMonths(3)->toDateString());
    $asOf = now()->addMonths(2)->startOfMonth()->toDateString();
    $run = completionCall($this, fn (Tenant $t) => app(BillingPeriods::class)->run($t, $asOf));
    expect(race([$run, $run], slow: ['eloquent.creating: '.BillingPeriod::class]))->toBe(['ok', 'ok']);

    actAsTenant($this->tenant);
    $periods = BillingPeriod::query()->get();
    expect($periods->groupBy(fn ($p) => $p->kind->value.' '.$p->period_start->toDateString())->every(fn ($g) => $g->count() === 1))->toBeTrue()
        ->and($periods)->not->toBeEmpty()
        ->and(Invoice::query()->count())->toBe($periods->where('status', BillingPeriod::DRAFTED)->count())
        ->and($periods->where('status', BillingPeriod::DRAFTED)->every(fn ($p) => $p->invoice_id !== null))->toBeTrue();
});

it('8. carries out an approval once when two checkers approve it at the same moment', function () {
    $invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['1000.00']), null, 'Issued', $this->op);
    $request = app(CreditNotes::class)->request($invoice, null, 'Cancel it', $this->op);
    $results = race([
        completionCall($this, fn () => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($request->id), 'Checker one', $this->checker->fresh())),
        completionCall($this, fn () => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($request->id), 'Checker two', $this->checker2->fresh())),
    ], slow: ['eloquent.creating: '.CreditNote::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('already approved');
    actAsTenant($this->tenant);
    expect(CreditNote::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Credited)
        ->and($request->fresh()->executed_at)->not->toBeNull();
});

it('9. numbers two credit notes approved at the same moment consecutively, without a gap or a duplicate', function () {
    $a = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['100.00']), null, 'First', $this->op);
    $b = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['200.00']), null, 'Second', $this->op);
    [$ra, $rb] = [app(CreditNotes::class)->request($a, null, 'Cancel A', $this->op), app(CreditNotes::class)->request($b, null, 'Cancel B', $this->op)];
    $results = race([
        completionCall($this, fn () => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($ra->id), 'Approve A', $this->checker->fresh())),
        completionCall($this, fn () => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($rb->id), 'Approve B', $this->checker2->fresh())),
    ], slow: ['eloquent.creating: '.CreditNote::class]);

    expect($results)->toBe(['ok', 'ok']);
    actAsTenant($this->tenant);
    expect(CreditNote::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2]);
});

it('10. settles an invoice once when a TDS declaration races the net payment', function () {
    $invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['1000.00']), null, 'Issued', $this->op);   // 1075.00
    $results = race([
        completionCall($this, fn () => app(TdsSettlement::class)->declare(Invoice::query()->withoutTenancy()->findOrFail($invoice->id), '20.00', 'TDS-RACE-1', 'Form 16A received', $this->op->fresh())),
        completionCall($this, fn () => app(Payments::class)->recordBankTransfer(Invoice::query()->withoutTenancy()->findOrFail($invoice->id), '1055.00', 'INR', 'UTR-TDS-'.$invoice->id,
            now()->toDateString(), 'Net of TDS', $this->op->fresh())),
    ], slow: ['eloquent.updating: '.Invoice::class, 'eloquent.creating: '.Payment::class]);

    expect($results)->toBe(['ok', 'ok']);
    actAsTenant($this->tenant);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::query()->where(['invoice_id' => $invoice->id, 'reconciliation_status' => ReconciliationStatus::Matched])->count())->toBe(1);
});

it('11. never refunds more than the credit note when two refunds of it are approved at once', function () {
    $invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->market, $this->op, ['1000.00']), null, 'Issued', $this->op);
    $payment = app(Payments::class)->recordBankTransfer($invoice, '1075.00', 'INR', 'UTR-RF-'.$invoice->id, now()->toDateString(), 'Paid in full', $this->op);
    app(ApprovalDesk::class)->approve(app(CreditNotes::class)->request($invoice->fresh(), [1 => '100.00'], 'Credit', $this->op), 'Agreed', $this->checker);
    actAsTenant($this->tenant);
    $note = CreditNote::query()->where('invoice_id', $invoice->id)->sole();
    actAsTenant(null);
    // Two separate requests that together exceed the credit note (each fits alone): 60.00 + 50.00 > 107.50.
    $first = app(Refunds::class)->request($note, $payment, '60.00', 'Refund one', $this->op);
    $second = app(Refunds::class)->request($note, $payment, '50.00', 'Refund two', $this->checker2);
    expect($second->id)->not->toBe($first->id)
        ->and(app(Refunds::class)->request($note, $payment, '60.00', 'Asked again', $this->checker2)->id)->toBe($first->id);   // the same request, once
    $results = race([
        completionCall($this, fn () => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($first->id), 'Approve one', $this->checker->fresh())),
        completionCall($this, fn () => app(ApprovalDesk::class)->approve(FinancialApproval::query()->findOrFail($second->id), 'Approve two', $this->checker->fresh())),
    ], slow: ['eloquent.creating: '.Refund::class]);

    expect(collect($results)->filter(fn ($r) => $r === 'ok'))->toHaveCount(1)
        ->and(collect($results)->first(fn ($r) => $r !== 'ok'))->toContain('at most what is left');
    actAsTenant($this->tenant);
    expect(Refund::query()->where('credit_note_id', $note->id)->count())->toBe(1);
});
