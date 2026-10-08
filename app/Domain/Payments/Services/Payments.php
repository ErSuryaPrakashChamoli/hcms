<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingAudit;
use App\Domain\Billing\Services\FinancialApprovals;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Exceptions\PaymentProviderException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentStart;
use App\Domain\Payments\Support\ProviderPaymentUpdate;
use App\Domain\Platform\Models\Tenant;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7: operator payment operations. Initiation (a provider payment for an issued invoice's exact amount due, one
 * open payment per invoice, idempotent at the provider), confirmation (only through PaymentReconciler: a verified
 * event, a server-side fetch or a recorded bank transfer; never a browser redirect), and resolution of
 * reconciliation exceptions: accepting a payment as settling its invoice, or writing the exception off, each by
 * maker-checker (B-13). There is no public checkout and no tenant-facing payment page in SaaS.7.
 */
final class Payments
{
    public const ACCEPT = 'accept';

    public const WRITE_OFF = 'write_off';

    public function __construct(private readonly ProviderRegistry $providers, private readonly PaymentReconciler $reconciler, private readonly BillingAudit $audit,
        private readonly TenantContext $tenants, private readonly Invoices $invoices, private readonly FinancialApprovals $approvals) {}

    public function initiate(Invoice $invoice, string $providerKey, string $reason, User $actor): Payment
    {
        OperatorChange::assert($actor, $reason, 'payments');
        $provider = $this->providers->get($providerKey) ?? throw new RuntimeException("The payment provider {$providerKey} is not enabled.");
        if (! $provider->startsPayments()) {
            throw new RuntimeException("{$provider->label()} is not started from PeopleOS: record the transfer when it is received.");
        }
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        $payment = $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $invoice, $provider, $reason, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== InvoiceStatus::Issued) {
                throw new RuntimeException("Only an issued, unpaid invoice can be paid; this one is {$locked->status->value}.");
            }
            $due = $this->invoices->amountDue($locked);
            if ($due->isNegative() || $due->isZero()) {
                throw new RuntimeException("Nothing is due on invoice {$locked->number}.");
            }
            if (! $provider->supportsCurrency($locked->currency)) {
                throw new RuntimeException("{$provider->label()} does not take {$locked->currency->value}.");
            }
            $payments = Payment::query()->where('invoice_id', $locked->id)->get();
            if ($payments->contains(fn (Payment $p) => $p->provider !== 'manual' && in_array($p->status, [PaymentStatus::Initiated, PaymentStatus::Pending], true))) {
                throw new RuntimeException("Invoice {$locked->number} already has an open payment.");
            }
            $payment = Payment::query()->create(['invoice_id' => $locked->id, 'provider' => $provider->key(), 'idempotency_key' => "{$locked->reference}:".($payments->count() + 1),
                'amount_minor' => $due->minor, 'currency' => $locked->currency, 'status' => PaymentStatus::Initiated, 'initiated_at' => now(),
                'reconciliation_status' => ReconciliationStatus::Unreconciled, 'reason' => $reason, 'created_by' => $actor->id]);
            $this->audit->both(AuditAction::PaymentInitiated, 'payments', $tenant, $payment, "payment {$payment->reference}",
                [['field' => 'status', 'before' => 'none', 'after' => 'initiated'], ['field' => 'amount', 'before' => null, 'after' => "{$payment->currency->value} {$payment->amount()->toDecimal()}"]],
                $reason, $actor, ['invoice_reference' => $locked->reference, 'provider' => $provider->key(), 'idempotency_key' => $payment->idempotency_key]);

            return $payment;
        }));

        return $this->start($payment, $actor);
    }

    /** Asks the provider to start (or, idempotently, return) the payment's session, outside any database lock. */
    public function start(Payment $payment, ?User $actor): Payment
    {
        $provider = $this->providers->get($payment->provider) ?? throw new RuntimeException("The payment provider {$payment->provider} is not enabled.");
        $invoiceNumber = $this->tenants->runAs(Tenant::query()->findOrFail($payment->tenant_id), fn () => (string) Invoice::query()->whereKey($payment->invoice_id)->value('number'));
        try {
            $checkout = $provider->start(new PaymentStart($payment->reference, $payment->idempotency_key, $payment->amount(), "Invoice {$invoiceNumber}"));
        } catch (PaymentProviderException $e) {
            $this->reconciler->apply($payment, new ProviderPaymentUpdate($payment->provider_reference ?? '', PaymentStatus::Failed, null, null, 'start_failed', mb_substr($e->getMessage(), 0, 300)),
                'provider_start', $actor, null);

            return $payment->fresh();
        }
        $tenant = Tenant::query()->findOrFail($payment->tenant_id);
        $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($payment, $checkout) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->provider_reference === null) {
                $locked->forceFill(['provider_reference' => $checkout->providerReference])->save();
            }
        }));

        $this->reconciler->apply($payment->fresh(), new ProviderPaymentUpdate($checkout->providerReference, PaymentStatus::Pending), 'provider_start', $actor, null);

        return $payment->fresh();
    }

    /**
     * Records a bank transfer received outside PeopleOS (controlled operator reconciliation). The payment holds what
     * was actually received; it settles the invoice only if that is exactly the amount due in the invoice's currency.
     * When the bank credited another currency (a foreign payment converted to INR, B-14), $settlementAmount and
     * $settlementCurrency record what reached Markedge's account, beside the payment, never instead of it.
     */
    public function recordBankTransfer(Invoice $invoice, string $amount, string $currency, string $bankReference, string $receivedOn, string $reason, User $actor,
        ?string $settlementAmount = null, ?string $settlementCurrency = null): Payment
    {
        OperatorChange::assert($actor, $reason, 'payments');
        $bankReference = strtoupper(trim($bankReference));
        if (preg_match('/^[A-Z0-9 ._\/-]{4,64}$/', $bankReference) !== 1) {
            throw new RuntimeException('The bank reference (UTR, SWIFT or SEPA reference) is 4 to 64 letters, digits, space, dot, slash, dash or underscore.');
        }
        try {
            $money = Money::parse($amount, strtoupper(trim($currency)));
            $day = Carbon::createFromFormat('!Y-m-d', $receivedOn)->toDateString();
        } catch (InvalidArgumentException|\Throwable $e) {
            throw new RuntimeException($e instanceof InvalidArgumentException ? $e->getMessage() : "{$receivedOn} is not a date (YYYY-MM-DD).");
        }
        if ($day > now()->toDateString() || $money->isNegative() || $money->isZero()) {
            throw new RuntimeException('A recorded transfer has a positive amount and was received today or earlier.');
        }
        $settlement = null;
        if (filled($settlementAmount)) {
            try {
                $settlement = Money::parse((string) $settlementAmount, strtoupper(trim((string) $settlementCurrency)));
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException($e->getMessage());
            }
            if ($settlement->isNegative() || $settlement->isZero()) {
                throw new RuntimeException('The amount credited to Markedge is positive.');
            }
        }
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);
        // Recorded and reconciled in one transaction: a transfer is never left half-recorded.
        $payment = $this->tenants->runAs($tenant, function () use ($tenant, $invoice, $money, $bankReference, $day, $reason, $actor, $settlement) {
            try {
                return DB::transaction(function () use ($tenant, $invoice, $money, $bankReference, $day, $reason, $actor, $settlement) {
                    $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                    if (! $locked->status->isIssuedDocument()) {
                        throw new RuntimeException("A transfer is recorded against an issued invoice; this one is {$locked->status->value}.");
                    }
                    $payment = Payment::query()->create(['invoice_id' => $locked->id, 'provider' => 'manual', 'provider_reference' => $bankReference,
                        'idempotency_key' => "manual:{$bankReference}", 'method' => PaymentMethod::BankTransfer, 'amount_minor' => $money->minor,
                        'currency' => $money->currency, 'status' => PaymentStatus::Initiated, 'initiated_at' => now(), 'reconciliation_status' => ReconciliationStatus::Unreconciled,
                        'reason' => $reason, 'created_by' => $actor->id]);
                    $this->audit->both(AuditAction::PaymentRecorded, 'payments', $tenant, $payment, "payment {$payment->reference}",
                        [['field' => 'received', 'before' => null, 'after' => "{$money->currency->value} {$money->toDecimal()} on {$day}"]], $reason, $actor,
                        ['invoice_reference' => $locked->reference, 'bank_reference' => $bankReference, 'received_on' => $day, 'idempotency_key' => $payment->idempotency_key]);
                    $this->reconciler->apply($payment, new ProviderPaymentUpdate($bankReference, PaymentStatus::Succeeded, $money, PaymentMethod::BankTransfer, settlement: $settlement,
                        settlementSource: $settlement === null ? null : 'bank_advice'), 'operator', $actor, "manual:{$bankReference}");

                    return $payment;
                });
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException("The bank reference {$bankReference} is already recorded.");
            }
        });

        return $payment->fresh();
    }

    /** Asks the provider, server-side, what happened to a payment, and applies it (never trusting a browser). */
    public function refresh(Payment $payment, string $reason, User $actor): string
    {
        OperatorChange::assert($actor, $reason, 'payments');
        $provider = $this->providers->get($payment->provider) ?? throw new RuntimeException("The payment provider {$payment->provider} is not enabled.");
        if ($payment->provider_reference === null) {
            throw new RuntimeException('The provider has not accepted this payment yet.');
        }
        $update = $provider->fetch($payment->provider_reference) ?? throw new RuntimeException('The provider does not know this payment.');

        return $this->reconciler->apply($payment, $update, 'provider_fetch', $actor, "fetch:{$payment->provider_reference}");
    }

    /**
     * Asks for a reconciliation exception to be closed (B-13: executed only when another operator approves it):
     * "accept" settles the payment's open invoice with it (the difference is written off); "write_off" closes the
     * exception without applying the payment (e.g. refunded or returned outside PeopleOS).
     */
    public function requestExceptionResolution(Payment $payment, string $outcome, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'payments');
        if (! in_array($outcome, [self::ACCEPT, self::WRITE_OFF], true)) {
            throw new RuntimeException('An exception is accepted or written off.');
        }
        $tenant = Tenant::query()->findOrFail($payment->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $payment, $outcome, $reason, $maker) {
            $payment = Payment::query()->findOrFail($payment->id);
            $invoice = Invoice::query()->findOrFail($payment->invoice_id);
            $this->assertResolvable($payment, $invoice, $outcome);
            $due = $this->invoices->amountDue($invoice);

            return $this->approvals->request(ApprovalAction::ExceptionResolution, $payment, $tenant, [
                'payment_reference' => $payment->reference, 'invoice_number' => $invoice->number, 'tenant' => $tenant->name, 'outcome' => $outcome,
                'code' => $payment->reconciliation_code, 'received' => "{$payment->currency->value} {$payment->amount()->toDecimal()}",
                'amount_due' => "{$due->currency->value} {$due->toDecimal()}",
            ], ['reconciliation' => "exception: {$payment->reconciliation_code}", 'invoice' => $invoice->status->value],
                ['reconciliation' => 'resolved', 'invoice' => $outcome === self::ACCEPT ? 'paid' : $invoice->status->value],
                "{$payment->reference}:{$outcome}", $reason, $maker);
        });
    }

    /** Executes an approved resolution (called by the approval desk, inside its transaction). */
    public function executeExceptionResolution(FinancialApproval $approval): Payment
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::ExceptionResolution);
        $tenant = Tenant::query()->findOrFail($approval->subject_tenant_id);
        $checker = User::query()->findOrFail($approval->checker_id);
        $outcome = (string) $approval->payload['outcome'];

        return $this->tenants->runAs($tenant, function () use ($tenant, $approval, $checker, $outcome) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail(Payment::query()->findOrFail($approval->subject_id)->invoice_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($approval->subject_id);
            $this->assertResolvable($locked, $invoice, $outcome);
            $note = mb_substr(($outcome === self::ACCEPT ? 'Accepted' : 'Written off')." ({$approval->reference}): {$approval->checker_reason}", 0, 500);
            $locked->forceFill(['reconciliation_status' => ReconciliationStatus::Resolved, 'reconciliation_note' => $note, 'resolved_by' => $checker->id, 'resolved_at' => now()])->save();
            $this->audit->both(AuditAction::PaymentExceptionResolved, 'payments', $tenant, $locked, "payment {$locked->reference}",
                [['field' => 'reconciliation', 'before' => "exception: {$locked->reconciliation_code}", 'after' => "resolved ({$outcome})"]], (string) $approval->checker_reason, $checker,
                ['payment_reference' => $locked->reference, 'code' => $locked->reconciliation_code, 'outcome' => $outcome, 'approval' => $approval->reference,
                    'maker_id' => $approval->maker_id, 'checker_id' => $checker->id, 'correlation_id' => $approval->correlation_key]);
            if ($outcome === self::ACCEPT) {
                $this->invoices->markPaid($invoice, $locked->id, $checker, 'exception_accepted', "Payment {$locked->reference} accepted ({$approval->reference})", $approval->correlation_key);
            }
            $this->approvals->executed($approval, "Payment {$locked->reference} exception {$outcome}");

            return $locked;
        });
    }

    private function assertResolvable(Payment $payment, Invoice $invoice, string $outcome): void
    {
        if ($payment->reconciliation_status !== ReconciliationStatus::Exception) {
            throw new RuntimeException('Only a reconciliation exception can be resolved.');
        }
        if ($outcome === self::ACCEPT && ($invoice->status !== InvoiceStatus::Issued || $payment->currency !== $invoice->currency || $payment->status !== PaymentStatus::Succeeded)) {
            throw new RuntimeException('A payment is accepted only for an unpaid invoice, in the invoice\'s currency; write the exception off instead.');
        }
    }
}
