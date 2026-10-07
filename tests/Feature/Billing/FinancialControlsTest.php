<?php

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ApprovalStatus;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\InvoiceNumberSeries;
use App\Domain\Billing\Services\CreditNotes;
use App\Domain\Billing\Services\FinancialApprovals;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Billing\Services\InvoiceSeries;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Providers\SandboxProvider;
use App\Domain\Payments\Services\ApprovalDesk;
use App\Domain\Payments\Services\Payments;
use App\Domain\Payments\Services\Refunds;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/BillingTestHelpers.php';

/*
| SaaS.7 completion (B-12, B-13): financial controls. Credit notes (full = the invoice's cancellation, or partial at
| the original rates), refunds only against a credit note, write-offs of unpaid invoices and the acceptance or
| write-off of payment exceptions all need a second operator. The maker never approves their own request, and no
| executor runs without an approved, unexecuted request, whoever calls it.
*/

beforeEach(function () {
    $this->travelTo('2027-04-01 09:00:00');
    config(['peopleos.billing.sandbox.enabled' => true, 'peopleos.billing.sandbox.webhook_secret' => 'sandbox-test-secret-0123456789']);
    $this->setup = indiaBilling();
    [$this->maker, $this->checker] = [$this->setup['operator'], $this->setup['verifier']];
    app(InvoiceSeries::class)->create('MARKEDGE-IN-TEST', 'CN/', '2027-04-01', '2028-03-31', 6, 'Credit note series', $this->maker, 'credit_note');
    $this->tenant = provisionTenant('Alpha');
    billingProfile($this->tenant, $this->setup['market'], $this->maker);              // Karnataka customer: IGST 7.5 % (fictional)
    $this->invoices = app(Invoices::class);
    $this->invoice = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->maker, ['1000.00']), null, 'April invoice', $this->maker);
    $this->notes = app(CreditNotes::class);
    $this->desk = app(ApprovalDesk::class);
});

function notesOf($tenant): Collection
{
    return app(TenantContext::class)->runAs($tenant, fn () => CreditNote::query()->orderBy('id')->get());
}

it('carries out a financial operation only when a second operator approves, recording maker, checker, reasons, before, after and correlation (critical 18)', function () {
    $request = $this->notes->request($this->invoice, null, 'Customer cancelled the order', $this->maker);
    expect([$request->status, $request->action, $request->subject_tenant_id, $this->invoice->fresh()->status, notesOf($this->tenant)->count()])
        ->toBe([ApprovalStatus::Pending, ApprovalAction::CreditNote, $this->tenant->id, InvoiceStatus::Issued, 0])
        ->and($this->notes->request($this->invoice, null, 'Asked twice', $this->maker)->id)->toBe($request->id);   // the same request, once

    $approved = $this->desk->approve($request, 'Cancellation confirmed by the customer', $this->checker);
    $note = notesOf($this->tenant)->sole();
    expect([$approved->status, $approved->maker_id, $approved->checker_id, $approved->maker_reason, $approved->checker_reason, $approved->result])
        ->toBe([ApprovalStatus::Approved, $this->maker->id, $this->checker->id, 'Customer cancelled the order', 'Cancellation confirmed by the customer', 'Credit note CN/000001'])
        ->and($approved->requested_at)->not->toBeNull()->and($approved->decided_at)->not->toBeNull()->and($approved->executed_at)->not->toBeNull()
        ->and($approved->before)->toBe(['invoice_status' => 'issued', 'credited' => 'INR 0.00', 'amount_due' => 'INR 1075.00'])
        ->and($approved->after)->toBe(['invoice_status' => 'credited', 'credited' => 'INR 1075.00'])
        ->and($approved->correlation_key)->toStartWith('credit_note:'.$this->invoice->reference)
        ->and([$note->number, $note->kind, $note->total_minor, $note->approval_id, $note->created_by, $note->approved_by])
        ->toBe(['CN/000001', CreditNote::FULL, 107500, $approved->id, $this->maker->id, $this->checker->id])
        ->and([$this->invoice->fresh()->status, $this->invoice->fresh()->closure_approval_id, $this->invoices->amountDue($this->invoice->fresh())->minor])
        ->toBe([InvoiceStatus::Credited, $approved->id, 0]);
    foreach (['FINANCIAL_APPROVAL_REQUESTED', 'FINANCIAL_APPROVAL_APPROVED', 'CREDIT_NOTE_ISSUED', 'INVOICE_CREDITED'] as $action) {
        expect(AuditEvent::query()->withoutTenancy()->where('action', $action)->whereNotNull('tenant_id')->count())->toBe(1, $action)
            ->and(AuditEvent::query()->withoutTenancy()->where('action', $action)->whereNull('tenant_id')->count())->toBe(1, $action);
    }
    expect(AuditEvent::query()->withoutTenancy()->where('action', 'FINANCIAL_APPROVAL_APPROVED')->whereNull('tenant_id')->sole()->metadata)
        ->toMatchArray(['maker_id' => $this->maker->id, 'checker_id' => $this->checker->id, 'correlation_id' => $approved->correlation_key]);

    // The same credit note cannot be requested again; a credited invoice takes no further credit, write-off or payment.
    expect(fn () => $this->notes->request($this->invoice->fresh(), null, 'Again', $this->maker))->toThrow(RuntimeException::class)
        ->and(fn () => $this->invoices->requestWriteOff($this->invoice->fresh(), 'Write off a credited invoice', $this->maker))->toThrow(RuntimeException::class, 'unpaid')
        ->and(fn () => $note->forceFill(['total_minor' => 1])->save())->toThrow(RuntimeException::class, 'never changes')
        ->and(fn () => $note->delete())->toThrow(RuntimeException::class, 'never deleted');
});

it('never lets the maker approve, and refuses every bypass of the approval through services or models (critical 19)', function () {
    $request = $this->notes->request($this->invoice, null, 'Cancel it', $this->maker);
    expect(fn () => $this->desk->approve($request, 'My own approval', $this->maker))->toThrow(RuntimeException::class, 'cannot approve it')
        ->and(fn () => $this->desk->reject($request, 'My own rejection', $this->maker))->toThrow(RuntimeException::class, 'withdraws')
        ->and(fn () => $this->desk->withdraw($request, 'Not mine', $this->checker))->toThrow(RuntimeException::class, 'Only the operator who requested')
        ->and(fn () => $this->desk->approve($request, 'A tenant user', tenantUser($this->tenant, ['*'])))->toThrow(RuntimeException::class, 'Only platform operators')
        // Service layer: no executor runs without an approved, unexecuted request of its action, inside a transaction.
        ->and(fn () => DB::transaction(fn () => $this->notes->execute($request)))->toThrow(RuntimeException::class, 'approved by a second operator')
        ->and(fn () => $this->notes->execute($request))->toThrow(RuntimeException::class)
        ->and(fn () => DB::transaction(fn () => app(FinancialApprovals::class)->approve($request, 'Direct call by the maker', $this->maker)))->toThrow(RuntimeException::class, 'cannot approve it')
        // Model layer: no self-approval, no approved row created directly, nothing decided twice.
        ->and(fn () => $request->fresh()->forceFill(['status' => ApprovalStatus::Approved, 'checker_id' => $this->maker->id, 'checker_reason' => 'x', 'decided_at' => now()])->save())
        ->toThrow(RuntimeException::class, 'by another operator')
        ->and(fn () => FinancialApproval::query()->create(['action' => ApprovalAction::CreditNote, 'subject_type' => 'Invoice', 'subject_id' => $this->invoice->id, 'payload' => [],
            'status' => ApprovalStatus::Approved, 'correlation_key' => 'forged', 'maker_id' => $this->maker->id, 'checker_id' => $this->checker->id, 'maker_reason' => 'forged',
            'requested_at' => now()]))->toThrow(RuntimeException::class, 'starts pending')
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Issued)->and(notesOf($this->tenant))->toBeEmpty();

    $approved = $this->desk->approve($request, 'Checked', $this->checker);
    expect(fn () => DB::transaction(fn () => $this->notes->execute($approved)))->toThrow(RuntimeException::class, 'not yet carried out')       // never twice
        ->and(fn () => DB::transaction(fn () => $this->invoices->executeWriteOff($approved)))->toThrow(RuntimeException::class)               // never as another action
        ->and(fn () => $this->desk->approve($approved, 'Again', platformAdmin()))->toThrow(RuntimeException::class, 'already approved')
        ->and(fn () => $approved->fresh()->forceFill(['result' => 'Edited'])->save())->toThrow(RuntimeException::class)
        ->and(fn () => $approved->fresh()->delete())->toThrow(RuntimeException::class, 'never deleted')
        ->and(notesOf($this->tenant))->toHaveCount(1);

    // A request that can no longer be carried out leaves nothing changed and stays pending.
    $second = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->maker, ['500.00']), null, 'May invoice', $this->maker);
    $writeOff = $this->invoices->requestWriteOff($second, 'Customer insolvent', $this->maker);
    app(Payments::class)->recordBankTransfer($second, '537.50', 'INR', 'UTR-LATE-1', '2027-04-01', 'Paid after all', $this->maker);
    expect(fn () => $this->desk->approve($writeOff, 'Write it off', $this->checker))->toThrow(RuntimeException::class, 'no longer be written off')
        ->and($writeOff->fresh()->status)->toBe(ApprovalStatus::Pending)->and($second->fresh()->status)->toBe(InvoiceStatus::Paid);
    $this->desk->reject($writeOff, 'Paid meanwhile', $this->checker);
    expect([$writeOff->fresh()->status, $writeOff->fresh()->checker_id])->toBe([ApprovalStatus::Rejected, $this->checker->id])
        ->and(fn () => $this->desk->approve($writeOff, 'Too late', $this->checker))->toThrow(RuntimeException::class, 'already rejected');
});

it('credits part of an invoice at its original rates, then the rest exactly, never more than the invoice (critical 16)', function () {
    $partial = $this->desk->approve($this->notes->request($this->invoice, [1 => '100.00'], 'Service credit for downtime', $this->maker), 'Agreed', $this->checker);
    $first = notesOf($this->tenant)->sole();
    expect([$first->number, $first->kind, $first->subtotal_minor, $first->tax_minor, $first->total_minor, $this->invoice->fresh()->status])
        ->toBe(['CN/000001', CreditNote::PARTIAL, 10000, 750, 10750, InvoiceStatus::Issued])
        ->and($first->snapshot['lines'][0]['taxes'])->toBe([['type' => 'IGST', 'rate' => '7.5000', 'tax_minor' => 750]])
        ->and($first->snapshot['invoice']['number'])->toBe($this->invoice->number)
        ->and($this->invoices->amountDue($this->invoice->fresh())->toDecimal())->toBe('967.50')
        ->and($partial->result)->toBe('Credit note CN/000001');

    // A later rule with another rate never changes a credit of this invoice; a credit is never more than what is left.
    $this->travelTo('2027-04-10 09:00:00');
    verifiedIndiaRule($this->maker, $this->checker, '2027-04-10', ['intra_state' => [['type' => 'CGST', 'rate' => '1'], ['type' => 'SGST', 'rate' => '1']], 'inter_state' => [['type' => 'IGST', 'rate' => '2']]]);
    expect(fn () => $this->notes->request($this->invoice, [1 => '900.01'], 'Too much', $this->maker))->toThrow(RuntimeException::class, 'at most 900.00')
        ->and(fn () => $this->notes->request($this->invoice, [2 => '1.00'], 'No such line', $this->maker))->toThrow(RuntimeException::class, 'no line 2')
        ->and(fn () => $this->notes->request($this->invoice, [], 'Nothing chosen', $this->maker))->toThrow(RuntimeException::class, 'at least one line');
    $this->desk->approve($this->notes->request($this->invoice, [1 => '33.33'], 'Second credit', $this->maker), 'Agreed', $this->checker);
    expect(notesOf($this->tenant)->last()->tax_minor)->toBe(250);                       // 33.33 × 7.5 % = 2.49975 → 2.50, half up, the original rate

    $this->desk->approve($this->notes->request($this->invoice, null, 'Cancel the rest', $this->maker), 'Agreed', $this->checker);
    $notes = notesOf($this->tenant);
    expect($notes->pluck('number')->all())->toBe(['CN/000001', 'CN/000002', 'CN/000003'])
        ->and($notes->sum('total_minor'))->toBe($this->invoice->total_minor)               // exactly the invoice, tax included
        ->and($notes->sum('tax_minor'))->toBe($this->invoice->tax_minor)
        ->and($notes->last()->kind)->toBe(CreditNote::PARTIAL)
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Credited);

    // Credit notes have their own series; without one, the approval cannot be carried out and stays pending.
    $other = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->maker, ['200.00']), null, 'Another', $this->maker);
    app(InvoiceSeries::class)->close(InvoiceNumberSeries::query()->where('document_type', 'credit_note')->sole(), 'Year closed', $this->maker);
    $stuck = $this->notes->request($other, null, 'Cancel', $this->maker);
    expect(fn () => $this->desk->approve($stuck, 'Agreed', $this->checker))->toThrow(RuntimeException::class, 'No open credit note number series')
        ->and($stuck->fresh()->status)->toBe(ApprovalStatus::Pending)->and($other->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(fn () => app(InvoiceSeries::class)->create('MARKEDGE-IN-TEST', 'TST/', '2028-04-01', '2029-03-31', 6, 'Reuses the invoice prefix', $this->maker, 'credit_note'))
        ->toThrow(RuntimeException::class, 'already used');
});

it('refunds only against an approved credit note, from a succeeded payment, never more than either (critical 17)', function () {
    $paid = app(Payments::class)->recordBankTransfer($this->invoice, '1075.00', 'INR', 'UTR-1', '2027-04-01', 'NEFT received', $this->maker);
    $refunds = app(Refunds::class);
    $this->desk->approve($this->notes->request($this->invoice->fresh(), [1 => '100.00'], 'Downtime credit', $this->maker), 'Agreed', $this->checker);
    $note = notesOf($this->tenant)->sole();
    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(fn () => $refunds->request($note, $paid, '107.51', 'More than the credit', $this->maker))->toThrow(RuntimeException::class, 'at most what is left')
        ->and(fn () => $refunds->request($note, $paid, '0', 'Nothing', $this->maker))->toThrow(RuntimeException::class);

    $request = $refunds->request($note, $paid, '107.50', 'Refund the credit', $this->maker);
    expect(app(TenantContext::class)->runAs($this->tenant, fn () => Refund::query()->count()))->toBe(0);   // nothing before approval
    $this->desk->approve($request, 'Refund agreed', $this->checker);
    $refund = app(TenantContext::class)->runAs($this->tenant, fn () => Refund::query()->sole());
    expect([$refund->status, $refund->provider, $refund->amount_minor, $refund->credit_note_id, $refund->payment_id, $refund->requested_by, $refund->approved_by])
        ->toBe([Refund::PROCESSING, 'manual', 10750, $note->id, $paid->id, $this->maker->id, $this->checker->id])
        ->and(fn () => $refunds->request($note, $paid, '0.01', 'Nothing left', $this->maker))->toThrow(RuntimeException::class, 'at most what is left');
    $done = $refunds->recordManual($refund, 'NEFT-OUT-77', 'Refund sent by bank', $this->maker);
    expect([$done->status, $done->provider_refund_reference])->toBe([Refund::SUCCEEDED, 'NEFT-OUT-77'])
        ->and(fn () => $done->fresh()->forceFill(['amount_minor' => 1])->save())->toThrow(RuntimeException::class)
        ->and(AuditEvent::query()->withoutTenancy()->where('action', 'REFUND_SUCCEEDED')->whereNotNull('tenant_id')->count())->toBe(1);

    // Through a provider: refunded after the approving transaction commits, idempotently keyed by the refund.
    $second = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->maker, ['200.00']), null, 'May invoice', $this->maker);
    $payment = app(Payments::class)->initiate($second, 'sandbox', 'Card link', $this->maker);
    SandboxProvider::simulate($payment->provider_reference, PaymentStatus::Succeeded, Money::parse('215.00', 'INR'));
    app(Payments::class)->refresh($payment, 'Checked with the provider', $this->maker);
    expect($second->fresh()->status)->toBe(InvoiceStatus::Paid);
    $this->desk->approve($this->notes->request($second->fresh(), null, 'Cancel May', $this->maker), 'Agreed', $this->checker);
    $card = notesOf($this->tenant)->last();
    $this->desk->approve($refunds->request($card, $payment->fresh(), '215.00', 'Full refund', $this->maker), 'Agreed', $this->checker);
    $sandboxRefund = app(TenantContext::class)->runAs($this->tenant, fn () => Refund::query()->where('provider', 'sandbox')->sole());
    expect([$sandboxRefund->status, $sandboxRefund->amount_minor])->toBe([Refund::SUCCEEDED, 21500])
        ->and($sandboxRefund->provider_refund_reference)->toStartWith('rfnd_sbx_')
        ->and($refunds->refresh($sandboxRefund, 'Check again', $this->maker)->status)->toBe(Refund::SUCCEEDED)
        ->and(fn () => $refunds->recordManual($sandboxRefund, 'X-REF-1', 'Not a bank refund', $this->maker))->toThrow(RuntimeException::class, 'through the provider')
        // A refund never comes from a payment of another invoice.
        ->and(fn () => $refunds->request($card, $paid, '1.00', 'Wrong payment', $this->maker))->toThrow(RuntimeException::class, 'credited invoice');
});

it('writes off an unpaid invoice and accepts or writes off a payment exception only with a second operator', function () {
    $writeOff = $this->invoices->requestWriteOff($this->invoice, 'Customer insolvent', $this->maker);
    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Issued);
    $this->desk->approve($writeOff, 'Insolvency confirmed', $this->checker);
    expect([$this->invoice->fresh()->status, $this->invoice->fresh()->closure_approval_id])->toBe([InvoiceStatus::WrittenOff, $writeOff->id])
        ->and($writeOff->fresh()->result)->toBe("Invoice {$this->invoice->number} written off");

    $second = $this->invoices->issue(draftInvoice($this->tenant, $this->setup['market'], $this->maker, ['1000.00']), null, 'Second', $this->maker);
    $short = app(Payments::class)->recordBankTransfer($second, '1070.00', 'INR', 'UTR-SHORT', '2027-04-01', 'Short by bank charges', $this->maker);
    expect([$short->reconciliation_code, $second->fresh()->status])->toBe(['amount_mismatch', InvoiceStatus::Issued])
        ->and(fn () => app(Payments::class)->requestExceptionResolution($short, 'ignore', 'Unknown outcome', $this->maker))->toThrow(RuntimeException::class, 'accepted or written off');
    $this->desk->approve(app(Payments::class)->requestExceptionResolution($short, 'accept', 'Bank charges: accept', $this->maker), 'Agreed', $this->checker);
    expect([$short->fresh()->reconciliation_status, $second->fresh()->status, $second->fresh()->paid_by_payment_id])->toBe([ReconciliationStatus::Resolved, InvoiceStatus::Paid, $short->id]);
});
