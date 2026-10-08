<x-filament-panels::page>
    @php($payment = $this->selected())
    @if ($payment)
        <x-filament::section heading="Payment {{ $payment->reference }} · {{ $this->tenantName() }}">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-gray-500 dark:text-gray-400">Amount</dt><dd class="font-medium">{{ $payment->currency->value }} {{ $payment->amount()->toDecimal() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Provider</dt><dd>{{ $payment->provider }} · {{ $payment->provider_reference ?? 'no reference yet' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd><x-filament::badge size="sm" :color="$payment->status->color()">{{ $payment->status->value }}</x-filament::badge> {{ $payment->failure_code }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Reconciliation</dt><dd><x-filament::badge size="sm" :color="$payment->reconciliation_status->color()">{{ $payment->reconciliation_status->value }}</x-filament::badge> {{ $payment->reconciliation_code }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Method</dt><dd>{{ $payment->method?->value ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Initiated</dt><dd>{{ $payment->initiated_at?->toDateTimeString() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Completed</dt><dd>{{ $payment->completed_at?->toDateTimeString() ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Settlement to Markedge</dt><dd>@if ($payment->settlement_recorded_at){{ $payment->settlement_currency }} {{ \App\Support\Money\Money::ofMinor($payment->settlement_amount_minor, $payment->settlement_currency)->toDecimal() }} · rate {{ rtrim(rtrim((string) $payment->settlement_fx_rate, '0'), '.') }} ({{ $payment->settlement_fx_source }}, reporting only)@else — @endif</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Provider transaction</dt><dd>{{ $payment->provider_transaction_reference ?? '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Invoice</dt><dd>@if ($ref = $this->invoiceReference())<a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformInvoicesPage::getUrl(['invoice' => $ref]) }}">open invoice</a>@endif</dd></div>
                <div class="sm:col-span-2 lg:col-span-4"><dt class="text-gray-500 dark:text-gray-400">Note</dt><dd>{{ $payment->reconciliation_note ?? $payment->reason }}</dd></div>
            </dl>
        </x-filament::section>
    @endif

    <x-filament::section heading="Payments" description="A payment settles its invoice only when a verified amount matches the amount due exactly (total less credit notes and declared TDS). Other short payments, overpayments and duplicates are exceptions, accepted or written off with a second operator's approval.">
        <div class="mb-3 text-sm"><label class="inline-flex items-center gap-2"><input type="checkbox" wire:model.live="exceptions" class="fi-checkbox-input rounded"> <span>Exceptions only</span></label></div>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Payments">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Payment</th><th scope="col" class="pe-4">Tenant</th><th scope="col" class="pe-4">Invoice</th><th scope="col" class="pe-4">Provider</th><th scope="col" class="pe-4">Status</th><th scope="col">Reconciliation</th></tr></thead>
            <tbody>
                @forelse ($this->payments() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformPaymentsPage::getUrl(['payment' => $row->reference]) }}">{{ $row->currency->value }} {{ $row->amount()->toDecimal() }}</a></td>
                        <td class="pe-4">{{ $row->tenant_name }}</td><td class="pe-4">{{ $row->invoice_number ?? 'draft' }}</td><td class="pe-4">{{ $row->provider }}</td>
                        <td class="pe-4"><x-filament::badge size="sm" :color="$row->status->color()">{{ $row->status->value }}</x-filament::badge></td>
                        <td><x-filament::badge size="sm" :color="$row->reconciliation_status->color()">{{ $row->reconciliation_status->value }}</x-filament::badge> {{ $row->reconciliation_code }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No payment.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Provider events" description="Verified notifications from payment providers: status and outcome only (payloads are encrypted and never shown). Chargebacks (disputes) are not processed yet (B-12, with the provider's activation): such an event is flagged here and logged, and is handled manually." collapsible>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Provider events">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Received</th><th scope="col" class="pe-4">Provider</th><th scope="col" class="pe-4">Event</th><th scope="col" class="pe-4">Type</th><th scope="col" class="pe-4">Status</th><th scope="col">Outcome</th></tr></thead>
            <tbody>
                @forelse ($this->events() as $event)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4">{{ $event->received_at->toDateTimeString() }}</td><td class="pe-4">{{ $event->provider }}</td><td class="pe-4"><code>{{ $event->event_id }}</code></td>
                        <td class="pe-4">{{ $event->type }}</td><td class="pe-4">{{ $event->status->value }}</td><td>@if ($event->outcome === 'chargeback_not_processed')<x-filament::badge size="sm" color="danger">chargeback not processed: handle manually</x-filament::badge>@else{{ $event->outcome ?? '—' }}@endif {{ $event->attempts ? '· '.$event->attempts.' attempt(s)' : '' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No provider event received.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
