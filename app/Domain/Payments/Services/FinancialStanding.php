<?php

namespace App\Domain\Payments\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * SaaS.9: what is still open between Markedge and a customer, read for the offboarding workflow (read-only; it never
 * changes, cancels or settles anything). A tenant's data is not deleted while any of these is open: a customer leaving
 * does not silently end a financial obligation, and the records stay as Markedge's books whatever happens to HCM data.
 */
final class FinancialStanding
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<array{code: string, detail: string}> */
    public function openItems(Tenant $tenant): array
    {
        return $this->tenants->runAs($tenant, function () {
            $items = [];
            $count = fn ($query) => (int) $query->count();
            if (($n = $count(Invoice::query()->where('status', InvoiceStatus::Draft->value))) > 0) {
                $items[] = ['code' => 'DRAFT_INVOICES', 'detail' => "{$n} draft invoice(s): issue or discard them"];
            }
            if (($n = $count(Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]))) > 0) {
                $items[] = ['code' => 'UNPAID_INVOICES', 'detail' => "{$n} issued invoice(s) not fully paid, credited or written off"];
            }
            if (($n = $count(Payment::query()->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value]))) > 0) {
                $items[] = ['code' => 'PAYMENTS_IN_PROGRESS', 'detail' => "{$n} payment(s) still in progress"];
            }
            if (($n = $count(Payment::query()->where('reconciliation_status', ReconciliationStatus::Exception->value))) > 0) {
                $items[] = ['code' => 'PAYMENT_EXCEPTIONS', 'detail' => "{$n} payment reconciliation exception(s) unresolved"];
            }
            if (($n = $count(Refund::query()->where('status', Refund::PROCESSING))) > 0) {
                $items[] = ['code' => 'REFUNDS_IN_PROGRESS', 'detail' => "{$n} refund(s) still processing"];
            }

            return $items;
        });
    }
}
