<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingAudit;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.7: applies a payment outcome (verified provider event, server-side fetch, or operator-recorded transfer) to
 * a payment and its invoice, in one transaction under the invoice and payment locks (always in that order).
 * States only move forward; the same outcome twice is a no-op; an outcome arriving after a final one is ignored.
 * A success settles the invoice only when the amount and currency match the payment exactly and the invoice is
 * issued and unpaid; otherwise the payment is a reconciliation exception for an operator (no partial payment, no
 * overpayment, no automatic refund). Nothing here touches subscriptions, entitlements or authorisation.
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
                'settlement_amount_minor' => $update->settlement?->minor, 'settlement_currency' => $update->settlement?->currency,
                'reconciliation_status' => $code === null ? ReconciliationStatus::Matched : ReconciliationStatus::Exception, 'reconciliation_code' => $code])->save();
            $received = $update->amount === null ? 'unknown amount' : "{$update->amount->currency->value} {$update->amount->toDecimal()}";
            $this->audit->both(AuditAction::PaymentSucceeded, 'payments', $tenant, $locked, "payment {$locked->reference}",
                [['field' => 'status', 'before' => $before, 'after' => 'succeeded'], ['field' => 'received', 'before' => null, 'after' => $received]],
                $trigger === 'operator' ? 'Bank transfer recorded by an operator' : "Confirmed by {$trigger}", $actor, $meta);
            if ($code === null) {
                $this->invoices->markPaid($invoice, $locked->id, $actor, $trigger, "Paid by payment {$locked->reference}", $correlation);

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
            $update->amount->minor !== $payment->amount_minor || $payment->amount_minor !== $invoice->total_minor => 'amount_mismatch',
            $invoice->status === InvoiceStatus::Paid => 'invoice_already_paid',
            $invoice->status !== InvoiceStatus::Issued => 'invoice_not_payable',
            default => null,
        };
    }
}
