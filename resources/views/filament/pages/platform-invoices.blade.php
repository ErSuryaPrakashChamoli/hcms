<x-filament-panels::page>
    @php($invoice = $this->selected())
    @if ($invoice)
        <x-filament::section heading="Invoice {{ $invoice->label() }} · {{ $this->tenantName() }}">
            @if ($invoice->status === \App\Domain\Billing\Enums\InvoiceStatus::Draft)
                @php($readiness = $this->readiness())
                <div class="mb-4 rounded-lg border p-3 text-sm {{ $readiness['ready'] ? 'border-success-300 dark:border-success-700' : 'border-warning-300 dark:border-warning-700' }}" role="status">
                    @if ($readiness['ready'])
                        This draft can be issued today.
                    @else
                        <p class="font-medium">This draft cannot be issued today:</p>
                        <ul class="list-disc ps-5">@foreach ($readiness['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                    @endif
                </div>
            @endif
            @include('filament.pages.billing.invoice-document', ['doc' => $this->document()])
        </x-filament::section>

        <x-filament::section heading="Payments of this invoice">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Payments of this invoice">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Payment</th><th scope="col" class="pe-4">Provider</th><th scope="col" class="pe-4">Status</th><th scope="col">Reconciliation</th></tr></thead>
                <tbody>
                    @forelse ($this->invoicePayments() as $payment)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformPaymentsPage::getUrl(['payment' => $payment->reference]) }}">{{ $payment->currency->value }} {{ $payment->amount()->toDecimal() }}</a></td>
                            <td class="pe-4">{{ $payment->provider }} {{ $payment->provider_reference }}</td>
                            <td class="pe-4"><x-filament::badge size="sm" :color="$payment->status->color()">{{ $payment->status->value }}</x-filament::badge></td>
                            <td><x-filament::badge size="sm" :color="$payment->reconciliation_status->color()">{{ $payment->reconciliation_status->value }}</x-filament::badge> {{ $payment->reconciliation_code }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">No payment yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="All invoices">
        <div class="mb-3 flex flex-wrap items-end gap-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Status</span>
                <select wire:model.live="status" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Any</option>
                    @foreach (\App\Domain\Billing\Enums\InvoiceStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->value }}</option>@endforeach
                </select>
            </label>
            <p class="text-gray-600 dark:text-gray-300 max-w-3xl">Invoices are created by the billing calculation once pricing decisions exist; none is drafted by hand. An unpaid or overdue invoice never restricts a tenant.</p>
        </div>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="All invoices">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Invoice</th><th scope="col" class="pe-4">Tenant</th><th scope="col" class="pe-4">Status</th><th scope="col" class="pe-4">Issued</th><th scope="col">Total</th></tr></thead>
            <tbody>
                @forelse ($this->invoices() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformInvoicesPage::getUrl(['invoice' => $row->reference]) }}">{{ $row->label() }}</a></td>
                        <td class="pe-4">{{ $row->tenant_name }}</td>
                        <td class="pe-4"><x-filament::badge size="sm" :color="$row->status->color()">{{ $row->status->value }}</x-filament::badge>@if ($row->isOverdue()) <x-filament::badge size="sm" color="danger">overdue</x-filament::badge>@endif</td>
                        <td class="pe-4">{{ $row->issue_date?->toDateString() ?? '—' }}</td>
                        <td>{{ $row->currency->value }} {{ $row->total()->toDecimal() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No invoice.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
