<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Services\Entitlements;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Jobs\ApplyProviderEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Providers\SandboxProvider;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\ProviderEvents;
use App\Domain\Payments\Services\ProviderRegistry;
use App\Domain\Platform\Services\TenantSuspensions;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/BillingTestHelpers.php';

/*
| SaaS.7: payments. A payment succeeds only on a verified provider event, a server-side provider fetch or an
| operator-recorded bank transfer, never on a browser. Webhooks are signature-verified, stored once per event id,
| replay-safe and idempotent; the tenant comes only from the verified provider reference. An invoice is settled
| only by an exact amount in its currency; anything else is a reconciliation exception. Payment failure never
| touches HR access, entitlements or subscriptions.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    config(['peopleos.billing.sandbox.enabled' => true, 'peopleos.billing.sandbox.webhook_secret' => 'sandbox-test-secret-0123456789']);
    $this->setup = indiaBilling();
    $this->operator = $this->setup['operator'];
    $this->tenant = provisionTenant('Alpha');
    billingProfile($this->tenant, $this->setup['market'], $this->operator);
    $this->invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), '2027-04-15', 'April invoice', $this->operator);
    $this->payments = app(Payments::class);
});

function sandboxEvent(string $id, string $type, string $reference, int $amountMinor, string $currency = 'INR'): string
{
    return json_encode(['id' => $id, 'type' => $type, 'data' => ['reference' => $reference, 'amount_minor' => $amountMinor, 'currency' => $currency, 'method' => 'card']]);
}

function paymentsOf($tenant): \Illuminate\Support\Collection
{
    return app(TenantContext::class)->runAs($tenant, fn () => Payment::query()->orderBy('id')->get());
}

it('settles an invoice from a recorded bank transfer of the exact amount, and flags every other transfer', function () {
    $paid = $this->payments->recordBankTransfer($this->invoice, '1075.00', 'INR', 'utr-0001', '2027-04-01', 'NEFT received', $this->operator);
    expect([$paid->status, $paid->reconciliation_status, $paid->provider, $paid->provider_reference, $this->invoice->fresh()->status])
        ->toBe([PaymentStatus::Succeeded, ReconciliationStatus::Matched, 'manual', 'UTR-0001', InvoiceStatus::Paid])
        ->and($this->invoice->fresh()->paid_by_payment_id)->toBe($paid->id)
        ->and(fn () => $this->payments->recordBankTransfer($this->invoice, '1075.00', 'INR', 'UTR-0001', '2027-04-01', 'Same transfer again', $this->operator))->toThrow(RuntimeException::class, 'already recorded');

    $twice = $this->payments->recordBankTransfer($this->invoice, '1075.00', 'INR', 'UTR-0002', '2027-04-01', 'Customer paid twice', $this->operator);
    expect([$twice->reconciliation_status, $twice->reconciliation_code])->toBe([ReconciliationStatus::Exception, 'invoice_already_paid']);

    $second = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), null, 'May invoice', $this->operator);
    $short = $this->payments->recordBankTransfer($second, '1000.00', 'INR', 'UTR-0003', '2027-04-01', 'Short payment (withholding?)', $this->operator);
    $usd = $this->payments->recordBankTransfer($second, '1075.00', 'USD', 'UTR-0004', '2027-04-01', 'Paid in dollars', $this->operator);
    expect([$short->reconciliation_code, $short->amount_minor, $usd->reconciliation_code, $usd->currency->value, $second->fresh()->status])
        ->toBe(['amount_mismatch', 100000, 'currency_mismatch', 'USD', InvoiceStatus::Issued]);

    // B-13: an exception is written off (or accepted) only with a second operator's approval.
    $request = $this->payments->requestExceptionResolution($short, 'write_off', 'Refunded by bank transfer outside PeopleOS', $this->operator);
    expect($short->fresh()->reconciliation_status)->toBe(ReconciliationStatus::Exception);
    approveAs($request);
    expect([$short->fresh()->reconciliation_status, $second->fresh()->status])->toBe([ReconciliationStatus::Resolved, InvoiceStatus::Issued])
        ->and(fn () => $this->payments->requestExceptionResolution($paid, 'write_off', 'Nothing to resolve', $this->operator))->toThrow(RuntimeException::class, 'Only a reconciliation exception')
        ->and(fn () => $this->payments->recordBankTransfer($this->invoice, '1', 'INR', 'UTR-0005', '2027-04-02', 'Future date', $this->operator))->toThrow(RuntimeException::class, 'today or earlier');
});

it('confirms a provider payment only from a verified webhook, once, whatever is replayed or forged', function () {
    $payment = $this->payments->initiate($this->invoice, 'sandbox', 'Payment link sent', $this->operator);
    expect([$payment->status, $payment->amount_minor, $payment->currency->value])->toBe([PaymentStatus::Pending, 107500, 'INR'])
        ->and(fn () => $this->payments->initiate($this->invoice, 'sandbox', 'Second link', $this->operator))->toThrow(RuntimeException::class, 'open payment')
        ->and(fn () => $this->payments->initiate($this->invoice, 'manual', 'Manual cannot start', $this->operator))->toThrow(RuntimeException::class, 'record the transfer');

    $body = sandboxEvent('evt_1', 'payment.succeeded', $payment->provider_reference, 107500);
    postWebhook($this, $body, ['X-Sandbox-Timestamp' => (string) now()->getTimestamp(), 'X-Sandbox-Signature' => 'v1='.str_repeat('0', 64)])->assertStatus(401);
    postWebhook($this, $body, [])->assertStatus(401);                                                             // unsigned
    postWebhook($this, $body, SandboxProvider::signedHeaders($body, now()->subMinutes(10)->getTimestamp()))->assertStatus(401);   // stale (replay window)
    postWebhook($this, $body, SandboxProvider::signedHeaders($body, null, 'a-different-secret-0123456789'))->assertStatus(401);
    postWebhook($this, $body, null, 'stripe')->assertStatus(404);                                                 // no such provider
    postWebhook($this, $body, null, 'manual')->assertStatus(404);                                                 // takes no webhooks
    expect(PaymentProviderEvent::query()->count())->toBe(0)->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    postWebhook($this, $body)->assertOk()->assertJson(['status' => 'accepted']);
    expect([$payment->fresh()->status, $payment->fresh()->reconciliation_status, $this->invoice->fresh()->status])
        ->toBe([PaymentStatus::Succeeded, ReconciliationStatus::Matched, InvoiceStatus::Paid]);
    postWebhook($this, $body)->assertOk()->assertJson(['status' => 'duplicate']);                                  // a duplicate delivery
    postWebhook($this, $body, SandboxProvider::signedHeaders($body, now()->addSeconds(30)->getTimestamp()))->assertOk()->assertJson(['status' => 'duplicate']);  // a re-signed replay
    postWebhook($this, sandboxEvent('evt_1', 'payment.succeeded', $payment->provider_reference, 1))->assertStatus(409);   // another body under a known id
    $late = sandboxEvent('evt_2', 'payment.failed', $payment->provider_reference, 107500);
    postWebhook($this, $late)->assertOk();                                                                         // failure after success: ignored
    expect(PaymentProviderEvent::query()->where('event_id', 'evt_2')->sole()->status)->toBe(ProviderEventStatus::Ignored)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'PAYMENT_SUCCEEDED')->whereNotNull('tenant_id')->count())->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'INVOICE_PAID')->whereNotNull('tenant_id')->count())->toBe(1)
        ->and(DB::table('payment_provider_events')->where('event_id', 'evt_1')->value('payload'))->not->toContain($payment->provider_reference);   // encrypted at rest

    // A browser can never confirm a payment: there is no return or confirmation route at all.
    // (Outside the operators' admin panel, the only billing or payment route is the signed provider webhook.)
    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => ! str_starts_with($r->uri(), 'admin/') && preg_match('/billing|payment|checkout/', $r->uri()) === 1)
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->values()->all())->toBe(['POST webhooks/billing/{provider}']);
});

it('flags provider amounts and currencies that differ, records failures, and lets a new attempt follow', function () {
    $payment = $this->payments->initiate($this->invoice, 'sandbox', 'First attempt', $this->operator);
    postWebhook($this, sandboxEvent('evt_f', 'payment.failed', $payment->provider_reference, 107500))->assertOk();
    expect([$payment->fresh()->status, $this->invoice->fresh()->status])->toBe([PaymentStatus::Failed, InvoiceStatus::Issued]);

    $retry = $this->payments->initiate($this->invoice, 'sandbox', 'Second attempt', $this->operator);
    expect($retry->idempotency_key)->toEndWith(':2')->and($retry->provider_reference)->not->toBe($payment->provider_reference);
    postWebhook($this, sandboxEvent('evt_m', 'payment.succeeded', $retry->provider_reference, 100000))->assertOk();   // less than invoiced
    expect([$retry->fresh()->status, $retry->fresh()->reconciliation_code, $this->invoice->fresh()->status])->toBe([PaymentStatus::Succeeded, 'amount_mismatch', InvoiceStatus::Issued]);

    $third = $this->payments->initiate($this->invoice, 'sandbox', 'Third attempt', $this->operator);
    postWebhook($this, sandboxEvent('evt_c', 'payment.succeeded', $third->provider_reference, 107500, 'USD'))->assertOk();
    expect($third->fresh()->reconciliation_code)->toBe('currency_mismatch')->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
});

it('resolves an event that arrives before its payment is known, through the scheduled sweep', function () {
    $reference = 'sbx_'.substr(hash('sha256', $this->invoice->reference.':1'), 0, 24);   // what the sandbox will assign
    postWebhook($this, sandboxEvent('evt_early', 'payment.succeeded', $reference, 107500))->assertOk();
    expect(PaymentProviderEvent::query()->sole()->only(['status', 'outcome', 'resolved_tenant_id']))->toBe(['status' => ProviderEventStatus::Exception, 'outcome' => 'unknown_reference', 'resolved_tenant_id' => null]);

    $payment = $this->payments->initiate($this->invoice, 'sandbox', 'Link sent', $this->operator);
    expect($payment->provider_reference)->toBe($reference);
    $this->artisan('peopleos:billing:provider-events')->assertSuccessful();
    expect([PaymentProviderEvent::query()->sole()->status, PaymentProviderEvent::query()->sole()->resolved_tenant_id, $payment->fresh()->status, $this->invoice->fresh()->status])
        ->toBe([ProviderEventStatus::Applied, $this->tenant->id, PaymentStatus::Succeeded, InvoiceStatus::Paid]);
    $this->artisan('peopleos:billing:provider-events')->assertSuccessful();                                            // idempotent
    expect(AuditEvent::query()->withoutTenancy()->where('action', 'INVOICE_PAID')->count())->toBe(2);                   // one per chain, once
});

it('confirms through a server-side fetch, retries jobs harmlessly, and still reconciles for a suspended tenant', function () {
    $payment = $this->payments->initiate($this->invoice, 'sandbox', 'Link sent', $this->operator);
    expect(fn () => $this->payments->refresh($payment, 'Nothing yet', $this->operator))->not->toThrow(Exception::class);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    SandboxProvider::simulate($payment->provider_reference, PaymentStatus::Succeeded, Money::ofMinor(107500, 'INR'));
    expect($this->payments->refresh($payment, 'Customer says paid', $this->operator))->toBe('applied')
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    // A suspended tenant: money moved, so the provider event is still applied (commercial records only).
    $second = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->setup['market'], $this->operator), null, 'May invoice', $this->operator);
    $pending = $this->payments->initiate($second, 'sandbox', 'Link sent', $this->operator);
    app(TenantSuspensions::class)->suspend($this->tenant, 'Security investigation', $this->operator);
    postWebhook($this, sandboxEvent('evt_s', 'payment.succeeded', $pending->provider_reference, 107500))->assertOk();
    expect([$pending->fresh()->status, $second->fresh()->status, $this->tenant->fresh()->status->value])->toBe([PaymentStatus::Succeeded, InvoiceStatus::Paid, 'suspended']);

    // Re-running the job for an applied event does nothing.
    $event = PaymentProviderEvent::query()->where('event_id', 'evt_s')->sole();
    ApplyProviderEvent::dispatchSync($this->tenant->id, $event->id);
    app(ProviderEvents::class)->apply($event->id);
    expect(paymentsOf($this->tenant)->where('status', PaymentStatus::Succeeded)->count())->toBe(2)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'INVOICE_PAID')->whereNotNull('tenant_id')->count())->toBe(2);
});

it('never enables the sandbox in production', function () {
    app()->detectEnvironment(fn () => 'production');
    expect(app(ProviderRegistry::class)->get('sandbox'))->toBeNull()->and(array_keys(app(ProviderRegistry::class)->all()))->toBe(['manual']);
    $body = sandboxEvent('evt_p', 'payment.succeeded', 'sbx_x', 1);
    postWebhook($this, $body)->assertStatus(404);
    expect(PaymentProviderEvent::query()->count())->toBe(0);
});

it('never lets a payment failure or an unpaid invoice touch HR access, entitlements or subscriptions', function () {
    actAsTenant($this->tenant);
    $hr = tenantUser($this->tenant, ['payroll.*', 'employee.*']);
    $decisions = fn () => collect(Capability::cases())->map(fn (Capability $c) => app(Entitlements::class)->evaluate($c, '2027-04-01')->toArray())->all();
    $before = $decisions();
    $payment = $this->payments->initiate($this->invoice, 'sandbox', 'Link sent', $this->operator);
    postWebhook($this, sandboxEvent('evt_x', 'payment.failed', $payment->provider_reference, 107500))->assertOk();
    $this->travelTo('2027-06-01 09:00:00');                                                                          // long overdue
    actAsTenant($this->tenant);
    expect($this->invoice->fresh()->isOverdue())->toBeTrue()
        ->and($decisions())->toBe($before)
        ->and($hr->fresh()->hasPermission('payroll.calculate'))->toBeTrue()
        ->and(\App\Domain\Subscriptions\Models\TenantSubscription::query()->count())->toBe(0)
        ->and($this->tenant->fresh()->status->value)->toBe('active');
});

it('keeps provider-specific data out of payments and invoices', function () {
    $columns = array_merge(Schema::getColumnListing('payments'), Schema::getColumnListing('invoices'));
    expect(collect($columns)->filter(fn ($c) => preg_match('/(^|_)(razorpay|stripe|sandbox|upi|netbanking|card|gstin|cgst|sgst|igst)(_|$)/i', $c))->all())->toBe([]);
});
