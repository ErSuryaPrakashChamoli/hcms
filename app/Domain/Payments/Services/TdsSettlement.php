<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceTdsClaim;
use App\Domain\Billing\Services\BillingAudit;
use App\Domain\Billing\Services\CommercialConfiguration;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Commercial\OperatorChange;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * SaaS.7 completion (B-11): TDS-aware settlement. Indian business customers may deduct income-tax TDS from what they
 * pay. An operator declares the deduction on an invoice (the amount the customer's statement shows; no rate is
 * assumed or computed), optionally with its certificate reference. The amount due becomes the total less that TDS:
 * a payment of exactly the remainder settles the invoice (paid, or partially paid until the certificate is
 * recorded), including a payment already received and held as an amount_mismatch exception. A short payment is
 * never treated as TDS without this declaration. Only where the TDS policy allows it (B-11: an Indian supplier and
 * customer, in INR); no withholding rate is configured or computed.
 */
final class TdsSettlement
{
    public function __construct(private readonly Invoices $invoices, private readonly PaymentReconciler $reconciler, private readonly BillingAudit $audit,
        private readonly TenantContext $tenants) {}

    public function declare(Invoice $invoice, string $amount, ?string $certificateReference, string $reason, User $actor): InvoiceTdsClaim
    {
        OperatorChange::assert($actor, $reason, 'payments');
        $certificate = $this->certificate($certificateReference, required: false);
        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        return $this->tenants->runAs($tenant, function () use ($tenant, $invoice, $amount, $certificate, $reason, $actor) {
            try {
                return DB::transaction(function () use ($tenant, $invoice, $amount, $certificate, $reason, $actor) {
                    $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                    if ($locked->status !== InvoiceStatus::Issued) {
                        throw new RuntimeException("TDS is declared on an unpaid issued invoice; {$locked->label()} is {$locked->status->value}.");
                    }
                    // Where customers may deduct withholding is Markedge policy (B-11: India, INR), configurable.
                    $where = collect((array) app(CommercialConfiguration::class)->required(ConfigurationKey::TdsJurisdictions));
                    $country = $locked->snapshot['customer']['country'] ?? null;
                    if ($country !== ($locked->snapshot['supplier']['country'] ?? null) || ! $where->contains(fn (array $j) => $j['country'] === $country && $j['currency'] === $locked->currency->value)) {
                        throw new RuntimeException('Customer withholding (TDS) applies only where Markedge policy allows it: a supplier and customer in '
                            .($where->map(fn (array $j) => "{$j['country']} ({$j['currency']})")->implode(', ') ?: 'no country').'.');
                    }
                    try {
                        $money = Money::parse($amount, $locked->currency);
                    } catch (InvalidArgumentException $e) {
                        throw new RuntimeException($e->getMessage());
                    }
                    $due = $this->invoices->amountDue($locked);
                    if ($money->isNegative() || $money->isZero() || $money->minor >= $due->minor) {
                        throw new RuntimeException("The TDS deducted is more than zero and less than the amount due ({$due->currency->value} {$due->toDecimal()}).");
                    }
                    $claim = InvoiceTdsClaim::query()->create(['invoice_id' => $locked->id, 'amount_minor' => $money->minor, 'currency' => $locked->currency,
                        'status' => $certificate === null ? InvoiceTdsClaim::PENDING : InvoiceTdsClaim::CERTIFIED, 'certificate_reference' => $certificate,
                        'reason' => $reason, 'recorded_by' => $actor->id] + ($certificate === null ? [] : ['certified_by' => $actor->id, 'certified_at' => now()]));
                    $remaining = $this->invoices->amountDue($locked);
                    $this->audit->both(AuditAction::TdsClaimRecorded, 'payments', $tenant, $claim, "TDS on invoice {$locked->number}",
                        [['field' => 'tds', 'before' => 'none', 'after' => "{$money->currency->value} {$money->toDecimal()} ".($certificate === null ? '(certificate pending)' : "(certificate {$certificate})")],
                            ['field' => 'amount_due', 'before' => "{$due->currency->value} {$due->toDecimal()}", 'after' => "{$remaining->currency->value} {$remaining->toDecimal()}"]],
                        $reason, $actor, ['invoice_reference' => $locked->reference, 'certificate_reference' => $certificate]);
                    $this->reconciler->rematch($locked, $actor, "Payment matches the amount due after TDS of {$money->currency->value} {$money->toDecimal()}");

                    return $claim;
                });
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('TDS is already declared on this invoice.');
            }
        });
    }

    /** Records the certificate of a declared TDS; an invoice partially paid only for want of it becomes paid. */
    public function certify(InvoiceTdsClaim $claim, string $certificateReference, string $reason, User $actor): InvoiceTdsClaim
    {
        OperatorChange::assert($actor, $reason, 'payments');
        $certificate = $this->certificate($certificateReference, required: true);
        $tenant = Tenant::query()->findOrFail($claim->tenant_id);

        return $this->tenants->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $claim, $certificate, $reason, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($claim->invoice_id);
            $locked = InvoiceTdsClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($locked->status === InvoiceTdsClaim::CERTIFIED) {
                return $locked;
            }
            $locked->forceFill(['status' => InvoiceTdsClaim::CERTIFIED, 'certificate_reference' => $certificate, 'certified_by' => $actor->id, 'certified_at' => now()])->save();
            $this->audit->both(AuditAction::TdsClaimCertified, 'payments', $tenant, $locked, "TDS on invoice {$invoice->number}",
                [['field' => 'certificate', 'before' => 'pending', 'after' => $certificate]], $reason, $actor, ['invoice_reference' => $invoice->reference]);
            if ($invoice->status === InvoiceStatus::PartiallyPaid) {
                $this->invoices->markPaid($invoice, null, $actor, 'tds_certified', "TDS certificate {$certificate} recorded", null);
            }

            return $locked;
        }));
    }

    private function certificate(?string $reference, bool $required): ?string
    {
        $reference = $reference === null ? '' : strtoupper(trim($reference));
        if ($reference === '' && ! $required) {
            return null;
        }
        if (preg_match('/^[A-Z0-9 .\/_-]{4,100}$/', $reference) !== 1) {
            throw new RuntimeException('The TDS certificate (or statement) reference is 4 to 100 letters, digits, space, dot, slash, dash or underscore.');
        }

        return $reference;
    }
}
