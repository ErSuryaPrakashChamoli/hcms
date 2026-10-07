<x-filament-panels::page>
    @php($tenantModel = $this->selectedTenant())
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Tenant</span>
                <select wire:model.live="tenant" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">All tenants</option>
                    @foreach (\App\Domain\Platform\Models\Tenant::query()->orderBy('name')->pluck('name', 'id') as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="text-gray-600 dark:text-gray-300 max-w-3xl">A billing profile is the tenant's commercial identity (never its HR or employer records). A tenant without one cannot be invoiced; none is created by default. Unpaid invoices never restrict anything.</p>
        </div>
    </x-filament::section>

    @if ($tenantModel)
        <x-filament::section heading="{{ $tenantModel->name }}: billing profile versions" description="The version in force on a day is the latest started one; invoices copy it when issued.">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Billing profile versions">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Version</th><th scope="col" class="pe-4">From</th><th scope="col" class="pe-4">Market</th><th scope="col" class="pe-4">Customer</th><th scope="col" class="pe-4">Legal name</th><th scope="col" class="pe-4">Jurisdiction</th><th scope="col">Tax registration</th></tr></thead>
                <tbody>
                    @forelse ($this->profiles() as $profile)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1 pe-4">v{{ $profile->version }}</td><td class="pe-4">{{ $profile->effective_from->toDateString() }}</td><td class="pe-4">{{ $profile->market->code }}</td>
                            <td class="pe-4">{{ $profile->customer_type->label() }}</td><td class="pe-4">{{ $profile->legal_name }}</td><td class="pe-4">{{ $profile->subdivision ?? $profile->country }}</td>
                            <td>{{ $profile->tax_registration->label() }}{{ $profile->tax_id_value ? ' · '.$profile->tax_id_value.' ('.str_replace('_', ' ', $profile->tax_id_status).')' : '' }}{{ $profile->special_tax_status ? ' · '.$profile->special_tax_status : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-2 text-gray-500 dark:text-gray-400">No billing profile: this tenant cannot be invoiced.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Billing terms" description="The price version each subscription is billed at, from a date. Today's applicable price is shown; a plan change without a new pin is flagged, never re-priced.">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Billing terms">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Subscription</th><th scope="col" class="pe-4">Applies today</th><th scope="col">Terms</th></tr></thead>
                <tbody>
                    @forelse ($this->terms() as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-1 pe-4">#{{ $row['subscription']->id }}</td>
                            <td class="pe-4">@if ($row['applies']){{ $this->format($row['applies']['unit_amount'], $row['applies']['term']->market->locale) }} {{ $row['applies']['term']->basis->label() }} {{ $row['applies']['term']->interval->label() }}@if (! $row['applies']['consistent']) <x-filament::badge size="sm" color="warning">plan changed: re-pin needed</x-filament::badge>@endif @else <span class="text-gray-500 dark:text-gray-400">no terms</span>@endif</td>
                            <td>@foreach ($row['terms'] as $term)<div class="{{ $term->status === 'cancelled' ? 'line-through text-gray-500 dark:text-gray-400' : '' }}">{{ $term->effective_from->toDateString() }} to {{ $term->effective_to?->toDateString() ?? 'open' }} · {{ $term->currency->value }} {{ $term->priceVersion->amount()->toDecimal() }} (price v{{ $term->priceVersion->version }}) · {{ $term->reason }}</div>@endforeach</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-2 text-gray-500 dark:text-gray-400">No subscription.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Invoices and payments">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Invoices of the tenant">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Invoice</th><th scope="col" class="pe-4">Status</th><th scope="col">Total</th></tr></thead>
                    <tbody>
                        @forelse ($this->invoices() as $invoice)
                            <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformInvoicesPage::getUrl(['invoice' => $invoice->reference]) }}">{{ $invoice->label() }}</a></td>
                                <td class="pe-4"><x-filament::badge size="sm" :color="$invoice->status->color()">{{ $invoice->status->value }}</x-filament::badge></td><td>{{ $invoice->currency->value }} {{ $invoice->total()->toDecimal() }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="py-2 text-gray-500 dark:text-gray-400">No invoice.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Payments of the tenant">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Payment</th><th scope="col" class="pe-4">Status</th><th scope="col">Reconciliation</th></tr></thead>
                    <tbody>
                        @forelse ($this->payments() as $payment)
                            <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformPaymentsPage::getUrl(['payment' => $payment->reference]) }}">{{ $payment->currency->value }} {{ $payment->amount()->toDecimal() }} · {{ $payment->provider }}</a></td>
                                <td class="pe-4"><x-filament::badge size="sm" :color="$payment->status->color()">{{ $payment->status->value }}</x-filament::badge></td><td><x-filament::badge size="sm" :color="$payment->reconciliation_status->color()">{{ $payment->reconciliation_status->value }}</x-filament::badge> {{ $payment->reconciliation_code }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="py-2 text-gray-500 dark:text-gray-400">No payment.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="All tenants today">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Billing accounts of all tenants">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Tenant</th><th scope="col" class="pe-4">Market</th><th scope="col" class="pe-4">Customer</th><th scope="col" class="pe-4">Billing jurisdiction</th><th scope="col">Open invoices</th></tr></thead>
            <tbody>
                @foreach ($this->overview() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 pe-4"><button type="button" class="text-primary-600 underline dark:text-primary-400" wire:click="$set('tenant', {{ $row['tenant']->id }})">{{ $row['tenant']->name }}</button></td>
                        <td class="pe-4">{{ $row['profile']?->market?->code ?? 'no billing profile' }}</td><td class="pe-4">{{ $row['profile']?->customer_type?->label() ?? '—' }}</td>
                        <td class="pe-4">{{ $row['profile'] ? ($row['profile']->subdivision ?? $row['profile']->country) : '—' }}</td><td>{{ $row['open_invoices'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
