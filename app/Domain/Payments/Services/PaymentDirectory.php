<?php

namespace App\Domain\Payments\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * SaaS.7: the operators' cross-tenant payment read models (Platform › Payments). The only place payments are read
 * without a bound tenant (allow-listed): payment headers, invoice numbers and tenant names, a bounded number of
 * rows. Provider events are platform records; their payloads are never shown.
 */
final class PaymentDirectory
{
    /** @return Collection<int, Payment> newest first, each with `tenant_name` and `invoice_number` set */
    public function payments(bool $exceptionsOnly = false, int $limit = 100): Collection
    {
        $payments = Payment::query()->withoutTenancy()->when($exceptionsOnly, fn ($q) => $q->where('reconciliation_status', ReconciliationStatus::Exception))
            ->orderByDesc('id')->limit($limit)->get();
        $names = Tenant::query()->whereKey($payments->pluck('tenant_id')->unique()->all())->pluck('name', 'id');
        $numbers = Invoice::query()->withoutTenancy()->whereKey($payments->pluck('invoice_id')->unique()->all())->get(['id', 'number', 'reference'])->keyBy('id');

        return $payments->each(function (Payment $p) use ($names, $numbers) {
            $p->setAttribute('tenant_name', $names[$p->tenant_id] ?? "#{$p->tenant_id}");
            $p->setAttribute('invoice_number', $numbers[$p->invoice_id]->number ?? null);
            $p->setAttribute('invoice_reference', $numbers[$p->invoice_id]->reference ?? null);
        });
    }

    public function paymentByReference(string $reference): ?Payment
    {
        return Payment::query()->withoutTenancy()->where('reference', $reference)->first();
    }

    public function invoiceReference(Payment $payment): ?string
    {
        return Invoice::query()->withoutTenancy()->whereKey($payment->invoice_id)->value('reference');
    }

    /** @return Collection<int, PaymentProviderEvent> newest first */
    public function events(int $limit = 50): Collection
    {
        return PaymentProviderEvent::query()->orderByDesc('id')->limit($limit)->get();
    }
}
