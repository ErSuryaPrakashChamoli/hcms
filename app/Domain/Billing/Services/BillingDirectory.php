<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\TenantBillingProfile;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * SaaS.7: the operators' cross-tenant billing read models (Platform › Billing accounts, Invoices). The only place
 * tenant billing records are read without a bound tenant (allow-listed): invoice headers and billing-profile
 * names, a bounded number of rows, never lines, HCM data or another domain's records.
 */
final class BillingDirectory
{
    /** @return Collection<int, Invoice> newest first, each with `tenant_name` set */
    public function invoices(?InvoiceStatus $status = null, int $limit = 100): Collection
    {
        $invoices = Invoice::query()->withoutTenancy()->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->limit($limit)->get();

        return $this->named($invoices);
    }

    public function invoiceByReference(string $reference): ?Invoice
    {
        return Invoice::query()->withoutTenancy()->where('reference', $reference)->first();
    }

    /** @return Collection<int, array{tenant: Tenant, profile: ?TenantBillingProfile, open_invoices: int}> */
    public function accounts(string $day): Collection
    {
        $profiles = TenantBillingProfile::query()->withoutTenancy()->with('market')->whereDate('effective_from', '<=', $day)
            ->orderBy('effective_from')->orderBy('version')->get()->keyBy('tenant_id');
        $open = Invoice::query()->withoutTenancy()->where('status', InvoiceStatus::Issued)->selectRaw('tenant_id, count(*) as open_count')
            ->groupBy('tenant_id')->pluck('open_count', 'tenant_id');

        return Tenant::query()->orderBy('name')->get()->map(fn (Tenant $t) => ['tenant' => $t, 'profile' => $profiles->get($t->id), 'open_invoices' => (int) ($open[$t->id] ?? 0)]);
    }

    /** @param  Collection<int, Invoice>  $invoices  @return Collection<int, Invoice> */
    private function named(Collection $invoices): Collection
    {
        $names = Tenant::query()->whereKey($invoices->pluck('tenant_id')->unique()->all())->pluck('name', 'id');

        return $invoices->each(fn (Invoice $i) => $i->setAttribute('tenant_name', $names[$i->tenant_id] ?? "#{$i->tenant_id}"));
    }
}
