<?php

namespace App\Domain\Payments\Services;

use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Billing\Services\BillingCatalog;
use App\Domain\Billing\Services\CreditNotes;
use App\Domain\Billing\Services\FinancialApprovals;
use App\Domain\Billing\Services\Invoices;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SaaS.7 completion (B-13): the checker's side of maker-checker. Approving marks the request approved by this
 * (second) operator and carries it out in the same transaction, so an execution that fails (the invoice was paid
 * meanwhile, the price window moved) leaves the request pending with nothing changed. It lives with payments because
 * it executes both billing and payment operations, and the dependency runs Payments → Billing.
 */
final class ApprovalDesk
{
    public function __construct(private readonly FinancialApprovals $approvals, private readonly BillingCatalog $catalog, private readonly CreditNotes $creditNotes,
        private readonly Invoices $invoices, private readonly Payments $payments, private readonly Refunds $refunds) {}

    public function approve(FinancialApproval $approval, string $reason, User $checker): FinancialApproval
    {
        return DB::transaction(function () use ($approval, $reason, $checker) {
            $approved = $this->approvals->approve($approval, $reason, $checker);
            match ($approved->action) {
                ApprovalAction::PricePublication => $this->catalog->executePublication($approved),
                ApprovalAction::CreditNote => $this->creditNotes->execute($approved),
                ApprovalAction::InvoiceWriteOff => $this->invoices->executeWriteOff($approved),
                ApprovalAction::ExceptionResolution => $this->payments->executeExceptionResolution($approved),
                ApprovalAction::Refund => $this->refunds->execute($approved),
            };

            return $approved->fresh();
        });
    }

    public function reject(FinancialApproval $approval, string $reason, User $checker): FinancialApproval
    {
        return $this->approvals->reject($approval, $reason, $checker);
    }

    public function withdraw(FinancialApproval $approval, string $reason, User $maker): FinancialApproval
    {
        return $this->approvals->withdraw($approval, $reason, $maker);
    }
}
