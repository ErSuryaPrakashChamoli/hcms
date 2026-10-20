<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Services\BillingAudit;
use App\Domain\Billing\Services\FinancialApprovals;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentProviderException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Support\ProviderRefund;
use App\Domain\Payments\Support\RefundStart;
use App\Domain\Platform\Models\Tenant;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7 completion (B-12, B-13): refunds, only against an issued credit note, from a succeeded payment of the same
 * invoice, never more than what is left of either. One operator requests, another approves; the approved execution
 * records the refund (processing) and, after commit, asks the provider (idempotently, keyed by the refund's own
 * reference). A provider that cannot refund (a bank transfer) leaves it processing until an operator records the
 * outgoing transfer's reference. A failed refund frees its amount for a new request. No automatic refund exists.
 */
final class Refunds
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly FinancialApprovals $approvals, private readonly BillingAudit $audit,
        private readonly TenantContext $tenants) {}

    public function request(CreditNote $note, Payment $payment, string $amount, string $reason, User $maker): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'refunds');
        $tenant = Tenant::query()->findOrFail($note->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $note, $payment, $amount, $reason, $maker) {
            [$note, $payment, $money] = $this->validated(CreditNote::query()->findOrFail($note->id), Payment::query()->findOrFail($payment->id), $amount);
            $attempt = Refund::query()->where('credit_note_id', $note->id)->count() + 1;

            return $this->approvals->request(ApprovalAction::Refund, $note, $tenant, [
                'credit_note_reference' => $note->reference, 'credit_note_number' => $note->number, 'payment_reference' => $payment->reference, 'tenant' => $tenant->name,
                'provider' => $payment->provider, 'amount_minor' => $money->minor, 'currency' => $money->currency->value, 'amount' => "{$money->currency->value} {$money->toDecimal()}",
            ], ['refunded' => "{$money->currency->value} ".$this->refunded($note->id, 'credit_note_id')->toDecimal()],
                ['refunded' => "{$money->currency->value} ".$this->refunded($note->id, 'credit_note_id')->plus($money)->toDecimal()],
                "{$note->reference}:{$payment->reference}:{$attempt}:{$money->minor}", $reason, $maker);
        });
    }

    /** Records an approved refund (called by the approval desk, inside its transaction); the provider is asked after commit. */
    public function execute(FinancialApproval $approval): Refund
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::Refund);
        $tenant = Tenant::query()->findOrFail($approval->subject_tenant_id);
        $checker = User::query()->findOrFail($approval->checker_id);

        $refund = $this->tenants->runAs($tenant, function () use ($tenant, $approval, $checker) {
            $note = CreditNote::query()->findOrFail($approval->subject_id);
            $payment = Payment::query()->where('reference', $approval->payload['payment_reference'])->lockForUpdate()->firstOrFail();
            [, , $money] = $this->validated($note, $payment, Money::ofMinor((int) $approval->payload['amount_minor'], (string) $approval->payload['currency'])->toDecimal());
            $refund = Refund::query()->create(['credit_note_id' => $note->id, 'payment_id' => $payment->id, 'provider' => $payment->provider, 'amount_minor' => $money->minor,
                'currency' => $money->currency, 'status' => Refund::PROCESSING, 'approval_id' => $approval->id, 'requested_by' => $approval->maker_id, 'approved_by' => $checker->id]);
            $this->audit->both(AuditAction::RefundStarted, 'payments', $tenant, $refund, "refund {$refund->reference}",
                [['field' => 'refund', 'before' => 'none', 'after' => "{$money->currency->value} {$money->toDecimal()} against credit note {$note->number}"]],
                (string) $approval->checker_reason, $checker, ['refund_reference' => $refund->reference, 'payment_reference' => $payment->reference, 'credit_note' => $note->number,
                    'approval' => $approval->reference, 'maker_id' => $approval->maker_id, 'checker_id' => $checker->id, 'correlation_id' => $approval->correlation_key,
                    'idempotency_key' => $refund->reference]);
            $this->approvals->executed($approval, "Refund {$refund->reference}");

            return $refund;
        });
        if ($this->providers->get($refund->provider)?->supportsRefunds() ?? false) {
            DB::afterCommit(fn () => $this->send($refund->fresh(), null));
        }

        return $refund;
    }

    /** Asks the provider to make (or, idempotently, report) the refund, outside any lock, and records the outcome. */
    public function send(Refund $refund, ?User $actor): Refund
    {
        $provider = $this->providers->get($refund->provider) ?? throw new RuntimeException("The payment provider {$refund->provider} is not enabled.");
        $tenant = Tenant::query()->findOrFail($refund->tenant_id);
        $payment = $this->tenants->runAs($tenant, fn () => Payment::query()->findOrFail($refund->payment_id));
        try {
            $result = $provider->refund(new RefundStart($refund->reference, (string) $payment->provider_reference, $payment->provider_transaction_reference, $refund->amount()));
        } catch (PaymentProviderException $e) {
            return $this->record($refund, new ProviderRefund('', 'failed', 'provider_refused'), $actor, mb_substr($e->getMessage(), 0, 300));
        }

        return $this->record($refund, $result, $actor);
    }

    /** Asks the provider, server-side, what happened to a processing refund. */
    public function refresh(Refund $refund, string $reason, User $actor): Refund
    {
        OperatorChange::assert($actor, $reason, 'refunds');
        $provider = $this->providers->get($refund->provider) ?? throw new RuntimeException("The payment provider {$refund->provider} is not enabled.");
        if ($refund->provider_refund_reference === null) {
            return $this->send($refund, $actor);
        }
        $payment = $this->tenants->runAs(Tenant::query()->findOrFail($refund->tenant_id), fn () => Payment::query()->findOrFail($refund->payment_id));
        $result = $provider->fetchRefund((string) $payment->provider_reference, $payment->provider_transaction_reference, $refund->provider_refund_reference)
            ?? throw new RuntimeException('The provider does not know this refund.');

        return $this->record($refund, $result, $actor);
    }

    /** A refund of a bank transfer is made outside PeopleOS; an operator records the outgoing transfer's reference. */
    public function recordManual(Refund $refund, string $bankReference, string $reason, User $actor): Refund
    {
        OperatorChange::assert($actor, $reason, 'refunds');
        if ($this->providers->get($refund->provider)?->supportsRefunds() ?? false) {
            throw new RuntimeException("{$refund->provider} refunds are made through the provider.");
        }
        $bankReference = strtoupper(trim($bankReference));
        if (preg_match('/^[A-Z0-9 ._\/-]{4,64}$/', $bankReference) !== 1) {
            throw new RuntimeException('The bank reference of the refund transfer is 4 to 64 letters, digits, space, dot, slash, dash or underscore.');
        }

        return $this->record($refund, new ProviderRefund($bankReference, Refund::SUCCEEDED), $actor, null, $reason);
    }

    /** @return Collection<int, Refund> */
    public function forCreditNote(CreditNote $note): Collection
    {
        return $this->tenants->runAs(Tenant::query()->findOrFail($note->tenant_id), fn () => Refund::query()->where('credit_note_id', $note->id)->orderBy('id')->get());
    }

    private function record(Refund $refund, ProviderRefund $result, ?User $actor, ?string $message = null, ?string $reason = null): Refund
    {
        $tenant = Tenant::query()->findOrFail($refund->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $refund, $result, $actor, $message, $reason) {
            try {
                return DB::transaction(function () use ($tenant, $refund, $result, $actor, $message, $reason) {
                    $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
                    if ($locked->status !== Refund::PROCESSING) {
                        return $locked; // already final: a late or repeated answer changes nothing
                    }
                    $reference = $result->providerRefundReference === '' ? [] : ($locked->provider_refund_reference === null ? ['provider_refund_reference' => $result->providerRefundReference] : []);
                    if ($result->status === Refund::PROCESSING) {
                        if ($reference !== []) {
                            $locked->forceFill($reference)->save();
                        }

                        return $locked;
                    }
                    $locked->forceFill($reference + ['status' => $result->status, 'completed_at' => now()]
                        + ($result->status === Refund::FAILED ? ['failure_code' => $result->failureCode ?? 'failed', 'failure_message' => $message] : []))->save();
                    $this->audit->both($result->status === Refund::SUCCEEDED ? AuditAction::RefundSucceeded : AuditAction::RefundFailed, 'payments', $tenant, $locked,
                        "refund {$locked->reference}", [['field' => 'status', 'before' => 'processing', 'after' => $result->status]],
                        $reason ?? ($result->status === Refund::SUCCEEDED ? "Refund confirmed by {$locked->provider}" : "Refund failed at {$locked->provider}"), $actor,
                        ['refund_reference' => $locked->reference, 'provider_refund_reference' => $locked->provider_refund_reference, 'trigger' => $actor === null ? 'provider' : 'operator']);

                    return $locked;
                });
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('That refund reference is already recorded.');
            }
        });
    }

    /** @return array{0: CreditNote, 1: Payment, 2: Money} */
    private function validated(CreditNote $note, Payment $payment, string $amount): array
    {
        if ($payment->invoice_id !== $note->invoice_id || $payment->status !== PaymentStatus::Succeeded) {
            throw new RuntimeException('A refund returns money from a succeeded payment of the credited invoice.');
        }
        if ($payment->currency !== $note->currency) {
            throw new RuntimeException('The payment and the credit note are in different currencies.');
        }
        try {
            $money = Money::parse($amount, $note->currency);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $leftOnNote = $note->total()->minus($this->refunded($note->id, 'credit_note_id'));
        $leftOnPayment = $payment->amount()->minus($this->refunded($payment->id, 'payment_id'));
        if ($money->isNegative() || $money->isZero() || $money->minor > $leftOnNote->minor || $money->minor > $leftOnPayment->minor) {
            throw new RuntimeException("A refund is more than zero and at most what is left on the credit note ({$leftOnNote->toDecimal()}) and the payment ({$leftOnPayment->toDecimal()}).");
        }

        return [$note, $payment, $money];
    }

    /** Refunded or being refunded (failed refunds do not count). */
    private function refunded(int $id, string $column): Money
    {
        $currency = $column === 'credit_note_id' ? CreditNote::query()->findOrFail($id)->currency : Payment::query()->findOrFail($id)->currency;

        // A locking read: the latest committed refunds, not the transaction's snapshot (MySQL repeatable read).
        return Money::ofMinor((int) Refund::query()->where($column, $id)->where('status', '<>', Refund::FAILED)->sharedLock()->sum('amount_minor'), $currency);
    }
}
