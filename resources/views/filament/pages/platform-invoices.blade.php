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

        @if ($invoice->status->isIssuedDocument())
            @php($due = $this->amountDue())
            @php($tds = $this->tdsClaim())
            <x-filament::section heading="Settlement" description="Amount due = total less credit notes and declared customer TDS. Credit notes, write-offs and refunds need a second operator's approval.">
                <dl class="grid gap-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd><x-filament::badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->label() }}</x-filament::badge></dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Amount due</dt><dd class="font-medium">{{ $due->currency->value }} {{ $due->toDecimal() }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">Customer TDS</dt><dd>@if ($tds){{ $tds->currency->value }} {{ $tds->amount()->toDecimal() }} · {{ $tds->status === 'certified' ? 'certificate '.$tds->certificate_reference : 'certificate pending' }}@else none declared @endif</dd></div>
                </dl>
                <div class="mt-3 overflow-x-auto" tabindex="0" role="region" aria-label="Credit notes and refunds">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Credit note</th><th scope="col" class="pe-4">Issued</th><th scope="col" class="pe-4">Kind</th><th scope="col" class="pe-4">Taxable + tax = total</th><th scope="col">Refunds</th></tr></thead>
                    <tbody>
                        @php($refunds = $this->refunds())
                        @forelse ($this->creditNotes() as $note)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-1 pe-4 font-medium">{{ $note->number }}</td><td class="pe-4">{{ $note->issue_date->toDateString() }}</td><td class="pe-4">{{ $note->kind }}</td>
                                <td class="pe-4">{{ $note->subtotal()->toDecimal() }} + {{ $note->tax()->toDecimal() }} = {{ $note->currency->value }} {{ $note->total()->toDecimal() }}</td>
                                <td>@forelse ($refunds->where('credit_note_id', $note->id) as $refund)<div>{{ $refund->currency->value }} {{ $refund->amount()->toDecimal() }} · {{ $refund->provider }} · <x-filament::badge size="sm" :color="$refund->status === 'succeeded' ? 'success' : ($refund->status === 'failed' ? 'danger' : 'warning')">{{ $refund->status }}</x-filament::badge> {{ $refund->provider_refund_reference }}</div>@empty <span class="text-gray-500 dark:text-gray-400">none</span>@endforelse</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No credit note.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </x-filament::section>
        @endif

        @php($evidenced = $this->lines()->filter(fn ($l) => $l->quantity_evidence !== null))
        @if ($evidenced->isNotEmpty())
            <x-filament::section heading="Quantity evidence" description="Frozen when the billing period was calculated; later HR changes never alter it (a correction is a credit note).">
                <ul class="space-y-1 text-sm">
                    @foreach ($evidenced as $line)
                        <li>Line {{ $line->line_no }}: {{ $line->quantity_evidence['calculation'] ?? '' }}@if (isset($line->quantity_evidence['peak_day'])) · peak day {{ $line->quantity_evidence['peak_day'] }} · {{ $line->quantity_evidence['employee_count'] ?? 0 }} employees (SHA-256 {{ substr($line->quantity_evidence['employee_ids_sha256'] ?? '', 0, 12) }}…) · method {{ $line->quantity_evidence['method'] ?? '' }}@endif</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

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
            <p class="text-gray-600 dark:text-gray-300 max-w-3xl">Invoices are drafted by the billing run from calculated billing periods; none is drafted by hand. Issue needs a verified tax rule. An unpaid or overdue invoice never restricts a tenant.</p>
        </div>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="All invoices">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Invoice</th><th scope="col" class="pe-4">Tenant</th><th scope="col" class="pe-4">Status</th><th scope="col" class="pe-4">Issued</th><th scope="col">Total</th></tr></thead>
            <tbody>
                @forelse ($this->invoices() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformInvoicesPage::getUrl(['invoice' => $row->reference]) }}">{{ $row->label() }}</a></td>
                        <td class="pe-4">{{ $row->tenant_name }}</td>
                        <td class="pe-4"><x-filament::badge size="sm" :color="$row->status->color()">{{ $row->status->label() }}</x-filament::badge>@if ($row->isOverdue()) <x-filament::badge size="sm" color="danger">overdue</x-filament::badge>@endif</td>
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
