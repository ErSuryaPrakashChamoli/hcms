<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceTdsClaim;
use App\Domain\Billing\Services\BillingAudit;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.7: applies a payment outcome (verified provider event, server-side fetch, or operator-recorded transfer) to
 * a payment and its invoice, in one transaction under the invoice and payment locks (always in that order).
 * States only move forward; the same outcome twice is a no-op; an outcome arriving after a final one is ignored.
 * A success settles the invoice only when the amount and currency match the payment exactly and equal the amount
 * due (the total less credit notes and declared customer TDS, B-11) on an open invoice; it is then paid, or
 * partially paid while that TDS awaits its certificate. Anything else is a reconciliation exception for an operator
 * (a short payment is never assumed to be TDS; no other partial payment, no overpayment, no automatic refund). The
 * provider's settlement (usually INR, B-14) is recorded once beside the payment and never changes its amount or the
 * invoice's. Nothing here touches subscriptions, entitlements or authorisation.
 */
final class PaymentReconciler
{
    public function __construct(private readonly Invoices $invoices, private readonly BillingAudit $audit, private readonly TenantContext $tenants) {}

    /** @return string the outcome: applied | pending | failed | cancelled | duplicate | out_of_order | exception:<code> */
    public function apply(Payment $payment, ProviderPaymentUpdate $update, string $trigger, ?User $actor, ?string $correlation): string
    {
        $tenant = Tenant::query()->findOrFail($payment->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $payment, $update, $trigger, $actor, $correlation) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->provider_reference !== null && $update->providerReference !== $locked->provider_reference) {
                return 'out_of_order';
            }
            if ($locked->status === $update->status) {
                return 'duplicate';
            }
            if (! $locked->status->canMoveTo($update->status)) {
                return 'out_of_order';
            }
            $before = $locked->status->value;
            $meta = ['payment_reference' => $locked->reference, 'invoice_reference' => $invoice->reference, 'provider' => $locked->provider,
                'provider_reference' => $locked->provider_reference, 'trigger' => $trigger, 'correlation_id' => $correlation, 'idempotency_key' => $locked->idempotency_key];
            if ($update->status !== PaymentStatus::Succeeded) {
                $locked->forceFill(['status' => $update->status] + ($update->status->isFinal() ? ['completed_at' => now(), 'failure_code' => $update->failureCode,
                    'failure_message' => $update->failureMessage] : []))->save();
                $action = match ($update->status) {
                    PaymentStatus::Pending => AuditAction::PaymentPending,
                    PaymentStatus::Failed => AuditAction::PaymentFailed,
                    default => AuditAction::PaymentCancelled,
                };
                $this->audit->both($action, 'payments', $tenant, $locked, "payment {$locked->reference}", [['field' => 'status', 'before' => $before, 'after' => $update->status->value]],
                    "Provider reported {$update->status->value}".($update->failureCode ? " ({$update->failureCode})" : ''), $actor, $meta);

                return $update->status->value;
            }

            $code = $this->mismatch($locked, $invoice, $update);
            $locked->forceFill(['status' => PaymentStatus::Succeeded, 'completed_at' => now(), 'method' => $update->method ?? $locked->method,
                'reconciliation_status' => $code === null ? ReconciliationStatus::Matched : ReconciliationStatus::Exception, 'reconciliation_code' => $code]
                + ($locked->provider_transaction_reference === null && $update->transactionReference !== null ? ['provider_transaction_reference' => $update->transactionReference] : [])
                + $this->settlement($locked, $update))->save();
            $received = $update->amount === null ? 'unknown amount' : "{$update->amount->currency->value} {$update->amount->toDecimal()}";
            $this->audit->both(AuditAction::PaymentSucceeded, 'payments', $tenant, $locked, "payment {$locked->reference}",
                [['field' => 'status', 'before' => $before, 'after' => 'succeeded'], ['field' => 'received', 'before' => null, 'after' => $received]],
                $trigger === 'operator' ? 'Bank transfer recorded by an operator' : "Confirmed by {$trigger}", $actor, $meta);
            if ($locked->settlement_recorded_at !== null && $update->settlement !== null) {
                $this->audit->both(AuditAction::PaymentSettlementRecorded, 'payments', $tenant, $locked, "payment {$locked->reference}",
                    [['field' => 'settlement', 'before' => null, 'after' => "{$update->settlement->currency->value} {$update->settlement->toDecimal()}"]],
                    "Settlement reported by {$locked->settlement_fx_source}", $actor, $meta + ['implied_rate' => $locked->settlement_fx_rate]);
            }
            if ($code === null) {
                $tdsPending = InvoiceTdsClaim::query()->where(['invoice_id' => $invoice->id, 'status' => InvoiceTdsClaim::PENDING])->sharedLock()->exists();
                $this->invoices->markPaid($invoice, $locked->id, $actor, $trigger, "Paid by payment {$locked->reference}", $correlation, $tdsPending);

                return 'applied';
            }
            $this->audit->both(AuditAction::PaymentReconciliationException, 'payments', $tenant, $locked, "payment {$locked->reference}",
                [['field' => 'reconciliation', 'before' => 'unreconciled', 'after' => "exception: {$code}"]],
                "Payment not applied to invoice {$invoice->label()}: {$code}", $actor, $meta + ['expected' => "{$locked->currency->value} {$locked->amount()->toDecimal()}", 'received' => $received]);

            return "exception:{$code}";
        }));
    }

    /** Why a successful payment cannot settle its invoice, or null when it can. */
    public function mismatch(Payment $payment, Invoice $invoice, ProviderPaymentUpdate $update): ?string
    {
        return match (true) {
            $update->amount === null => 'amount_missing',
            $update->amount->currency !== $payment->currency || $payment->currency !== $invoice->currency => 'currency_mismatch',
            $invoice->status === InvoiceStatus::Paid || $invoice->status === InvoiceStatus::PartiallyPaid => 'invoice_already_paid',
            $update->amount->minor !== $payment->amount_minor || ($invoice->status === InvoiceStatus::Issued && $payment->amount_minor !== $this->invoices->amountDue($invoice)->minor) => 'amount_mismatch',
            $invoice->status !== InvoiceStatus::Issued => 'invoice_not_payable',
            default => null,
        };
    }

    /**
     * B-14: what the provider or bank credited Markedge, recorded once, with the rate it implies (settlement ÷ amount,
     * for reporting only: nothing is ever converted with it).
     *
     * @return array<string, mixed>
     */
    private function settlement(Payment $payment, ProviderPaymentUpdate $update): array
    {
        if ($update->settlement === null || $payment->settlement_recorded_at !== null || $update->amount === null || $update->amount->isZero()) {
            return [];
        }
        $rate = BigDecimal::ofUnscaledValue($update->settlement->minor, $update->settlement->currency->minorUnits())
            ->dividedBy(BigDecimal::ofUnscaledValue($update->amount->minor, $update->amount->currency->minorUnits()), 10, RoundingMode::HalfUp);

        return ['settlement_amount_minor' => $update->settlement->minor, 'settlement_currency' => $update->settlement->currency->value,
            'settlement_fx_rate' => (string) $rate, 'settlement_fx_source' => mb_substr($update->settlementSource ?? 'provider', 0, 64), 'settlement_recorded_at' => now()];
    }

    /**
     * Settles an open invoice with a payment held as an amount_mismatch exception once a declared TDS makes it exact
     * (B-11). Runs inside the caller's transaction with the invoice locked.
     */
    public function rematch(Invoice $locked, ?User $actor, string $reason): ?Payment
    {
        if (DB::transactionLevel() === 0 || $locked->status !== InvoiceStatus::Issued) {
            return null;
        }
        $due = $this->invoices->amountDue($locked);
        $payment = Payment::query()->where(['invoice_id' => $locked->id, 'status' => PaymentStatus::Succeeded, 'reconciliation_status' => ReconciliationStatus::Exception,
            'reconciliation_code' => 'amount_mismatch', 'currency' => $locked->currency->value, 'amount_minor' => $due->minor])->orderBy('id')->lockForUpdate()->first();
        if ($payment === null) {
            return null;
        }
        $tenant = Tenant::query()->findOrFail($locked->tenant_id);
        $payment->forceFill(['reconciliation_status' => ReconciliationStatus::Matched, 'reconciliation_note' => mb_substr($reason, 0, 500)])->save();
        $this->audit->both(AuditAction::PaymentExceptionResolved, 'payments', $tenant, $payment, "payment {$payment->reference}",
            [['field' => 'reconciliation', 'before' => 'exception: amount_mismatch', 'after' => 'matched (with declared TDS)']], $reason, $actor,
            ['payment_reference' => $payment->reference, 'invoice_reference' => $locked->reference, 'amount_due' => "{$due->currency->value} {$due->toDecimal()}"]);
        $tdsPending = InvoiceTdsClaim::query()->where(['invoice_id' => $locked->id, 'status' => InvoiceTdsClaim::PENDING])->sharedLock()->exists();
        $this->invoices->markPaid($locked, $payment->id, $actor, 'tds_declared', "Paid by payment {$payment->reference} with declared TDS", null, $tdsPending);

        return $payment;
    }
}
