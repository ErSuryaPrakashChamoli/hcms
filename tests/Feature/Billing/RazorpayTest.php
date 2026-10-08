<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Services\CreditNotes;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Exceptions\PaymentProviderException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Providers\RazorpayProvider;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\ProviderRegistry;
use App\Domain\Payments\Services\Refunds;
use App\Domain\Payments\Support\PaymentStart;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/BillingTestHelpers.php';

/*
| SaaS.7 completion (B-10): the Razorpay adapter, in TEST MODE ONLY. It is enabled only with rzp_test_ keys and a
| webhook secret, never in production and never with live keys. Orders are created idempotently by receipt; a
| payment is confirmed only by a signed webhook (HMAC-SHA256 of the raw body) or a server-side fetch; each event id
| is applied once; a failed attempt never closes the order; refunds go through the API keyed by the refund.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    $this->setup = indiaBilling();
    $this->op = $this->setup['operator'];
    $this->tenant = provisionTenant('Alpha');
    billingProfile($this->tenant, $this->setup['market'], $this->op);
    $this->invoice = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->setup['market'], $this->op, ['1000.00']), null, 'April invoice', $this->op);
    $this->payments = app(Payments::class);
});

it('is available only in test mode: test keys, a webhook secret, never production, never live keys (critical 24)', function () {
    $keys = fn () => array_keys(app(ProviderRegistry::class)->all());
    expect($keys())->toBe(['manual']);                                           // off by default
    razorpayTestMode();
    expect($keys())->toBe(['manual', 'razorpay'])->and(app(ProviderRegistry::class)->get('razorpay')->label())->toBe('Razorpay (test mode)');
    razorpayTestMode(['key_id' => 'rzp_live_FICTIONAL0001']);
    expect($keys())->toBe(['manual'])->and(fn () => new RazorpayProvider)->toThrow(PaymentProviderException::class, 'test mode only');
    razorpayTestMode(['webhook_secret' => 'short']);
    expect($keys())->toBe(['manual']);
    razorpayTestMode();
    app()->detectEnvironment(fn () => 'production');
    expect($keys())->toBe(['manual']);
    app()->detectEnvironment(fn () => 'testing');
    expect(config('peopleos.billing.razorpay.base_url'))->toBe('https://api.razorpay.com/v1');
});

it('creates one order per payment, idempotently, and confirms it only from a signed webhook or a server-side fetch (critical 24)', function () {
    razorpayTestMode();
    $api = fakeRazorpay();
    $payment = $this->payments->initiate($this->invoice, 'razorpay', 'Payment link', $this->op);
    expect([$payment->status, $payment->provider_reference, $payment->amount_minor])->toBe([PaymentStatus::Pending, 'order_FAKE000001', 107500])
        ->and($api['orders'][0])->toMatchArray(['amount' => 107500, 'currency' => 'INR', 'receipt' => $payment->reference])
        ->and(collect($api['requests'])->every(fn ($r) => $r[2] === 'auth'))->toBeTrue();
    // Asking again for the same payment finds the order by its receipt instead of creating a second one.
    $provider = app(ProviderRegistry::class)->get('razorpay');
    expect($provider->start(new PaymentStart($payment->reference, $payment->idempotency_key, $payment->amount(), 'Retry'))->providerReference)
        ->toBe('order_FAKE000001')->and($api['orders'])->toHaveCount(1);

    // A forged or unsigned notification is refused and changes nothing.
    [$body, $headers] = razorpayWebhook('evt_FAKE_1', 'payment.captured', razorpayPayment('order_FAKE000001', 107500, 'INR'));
    postWebhook($this, $body, ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'wrong-secret-wrong-secret'), 'X-Razorpay-Event-Id' => 'evt_FAKE_1'], 'razorpay')->assertStatus(401);
    postWebhook($this, $body, ['X-Razorpay-Event-Id' => 'evt_FAKE_1'], 'razorpay')->assertStatus(401);
    postWebhook($this, str_replace('107500', '1', $body), $headers, 'razorpay')->assertStatus(401);            // body changed after signing
    expect([$payment->fresh()->status, PaymentProviderEvent::query()->count()])->toBe([PaymentStatus::Pending, 0]);

    // A failed attempt keeps the order open; a capture settles it.
    [$failedBody, $failedHeaders] = razorpayWebhook('evt_FAKE_0', 'payment.failed', razorpayPayment('order_FAKE000001', 107500, 'INR', null, 'pay_FAKE_FAILED', 'failed'));
    postWebhook($this, $failedBody, $failedHeaders, 'razorpay')->assertOk();
    expect([$payment->fresh()->status, $this->invoice->fresh()->status])->toBe([PaymentStatus::Pending, InvoiceStatus::Issued]);
    postWebhook($this, $body, $headers, 'razorpay')->assertOk();
    expect([$payment->fresh()->status, $payment->fresh()->provider_transaction_reference, $this->invoice->fresh()->status, $payment->fresh()->settlement_currency])
        ->toBe([PaymentStatus::Succeeded, 'pay_FAKE000001', InvoiceStatus::Paid, 'INR']);

    // Server-side fetch: the order's captured payment.
    $second = app(Invoices::class)->issue(draftInvoice($this->tenant, $this->setup['market'], $this->op, ['200.00']), null, 'May invoice', $this->op);
    $other = $this->payments->initiate($second, 'razorpay', 'Second link', $this->op);
    expect($this->payments->refresh($other, 'Customer says paid', $this->op))->toBe('duplicate');    // still pending: nothing captured
    $api['payments'] = [$other->provider_reference => [razorpayPayment($other->provider_reference, 21500, 'INR', null, 'pay_FAKE000002')]];
    expect($this->payments->refresh($other->fresh(), 'Check again', $this->op))->toBe('applied')->and($second->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('applies each Razorpay event once: duplicates, a capture and its order.paid, and replays change nothing (critical 20)', function () {
    razorpayTestMode();
    fakeRazorpay();
    $payment = $this->payments->initiate($this->invoice, 'razorpay', 'Payment link', $this->op);
    $captured = razorpayPayment($payment->provider_reference, 107500, 'INR');
    [$body, $headers] = razorpayWebhook('evt_FAKE_DUP', 'payment.captured', $captured);
    postWebhook($this, $body, $headers, 'razorpay')->assertOk()->assertJson(['status' => 'accepted']);
    postWebhook($this, $body, $headers, 'razorpay')->assertOk()->assertJson(['status' => 'duplicate']);
    // The same event id with another body is a conflict, never applied.
    [$forged] = razorpayWebhook('evt_FAKE_DUP', 'payment.captured', razorpayPayment($payment->provider_reference, 1, 'INR'));
    postWebhook($this, $forged, ['X-Razorpay-Signature' => hash_hmac('sha256', $forged, (string) config('peopleos.billing.razorpay.webhook_secret')), 'X-Razorpay-Event-Id' => 'evt_FAKE_DUP'], 'razorpay')
        ->assertStatus(409);
    // order.paid follows payment.captured for the same capture: recorded, applied as a duplicate.
    $orderPaid = json_encode(['entity' => 'event', 'event' => 'order.paid', 'payload' => ['payment' => ['entity' => $captured], 'order' => ['entity' => ['id' => $payment->provider_reference, 'status' => 'paid']]]]);
    postWebhook($this, $orderPaid, ['X-Razorpay-Signature' => hash_hmac('sha256', $orderPaid, (string) config('peopleos.billing.razorpay.webhook_secret')), 'X-Razorpay-Event-Id' => 'evt_FAKE_ORDER'], 'razorpay')->assertOk();
    $events = PaymentProviderEvent::query()->orderBy('id')->get();
    expect($events->map(fn ($e) => [$e->event_id, $e->status, $e->outcome])->all())
        ->toBe([['evt_FAKE_DUP', ProviderEventStatus::Applied, 'applied'], ['evt_FAKE_ORDER', ProviderEventStatus::Ignored, 'duplicate']])
        ->and(app(TenantContext::class)->runAs($this->tenant, fn () => Payment::query()->count()))->toBe(1)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'INVOICE_PAID')->whereNotNull('tenant_id')->count())->toBe(1);
    // Events PeopleOS does not handle are recorded as ignored.
    [$other, $otherHeaders] = razorpayWebhook('evt_FAKE_X', 'settlement.processed', $captured);
    postWebhook($this, $other, $otherHeaders, 'razorpay')->assertOk()->assertJson(['status' => 'ignored']);
});

it('refunds a Razorpay payment through the API against an approved credit note, once, whatever is retried (critical 24, 17)', function () {
    razorpayTestMode();
    $api = fakeRazorpay();
    app(InvoiceSeries::class)->create('MARKEDGE-IN-TEST', 'CN/', '2027-04-01', '2028-03-31', 6, 'Credit note series', $this->op, 'credit_note');
    $payment = $this->payments->initiate($this->invoice, 'razorpay', 'Payment link', $this->op);
    [$body, $headers] = razorpayWebhook('evt_FAKE_R1', 'payment.captured', razorpayPayment($payment->provider_reference, 107500, 'INR'));
    postWebhook($this, $body, $headers, 'razorpay')->assertOk();
    $desk = app(ApprovalDesk::class);
    $desk->approve(app(CreditNotes::class)->request($this->invoice->fresh(), [1 => '100.00'], 'Downtime credit', $this->op), 'Agreed', $this->setup['verifier']);
    $note = app(TenantContext::class)->runAs($this->tenant, fn () => CreditNote::query()->sole());
    $desk->approve(app(Refunds::class)->request($note, $payment->fresh(), '107.50', 'Refund the credit', $this->op), 'Agreed', $this->setup['verifier']);
    $refund = app(TenantContext::class)->runAs($this->tenant, fn () => Refund::query()->sole());
    expect([$refund->status, $refund->provider_refund_reference, $refund->amount_minor])->toBe([Refund::SUCCEEDED, 'rfnd_FAKE1', 10750])
        ->and($api['refunds'][0])->toMatchArray(['payment_id' => 'pay_FAKE000001', 'amount' => 10750, 'receipt' => $refund->reference]);
    // A retried send finds the refund by its receipt instead of refunding twice.
    expect(app(Refunds::class)->send($refund, null)->provider_refund_reference)->toBe('rfnd_FAKE1')->and($api['refunds'])->toHaveCount(1)
        ->and(app(Refunds::class)->refresh($refund, 'Check status', $this->op)->status)->toBe(Refund::SUCCEEDED);
    Http::assertSentCount(count($api['requests']));
});
