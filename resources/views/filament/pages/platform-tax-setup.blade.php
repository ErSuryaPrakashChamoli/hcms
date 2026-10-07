<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300 max-w-4xl">Technical support is not legal compliance. A jurisdiction is at most <strong>configured</strong> here (determination built and a verified rule in force); "supported" needs a tax and legal approval outside PeopleOS. An invoice is refused whenever its tax cannot be determined safely: no default tax is ever applied.</p>
    </x-filament::section>

    <x-filament::section heading="Jurisdiction support" description="What PeopleOS can say about each jurisdiction it describes. Countries not listed have no regime here and are not supported.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Jurisdiction support">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Jurisdiction</th><th scope="col" class="pe-4">Currency</th><th scope="col" class="pe-4">Tax regime</th><th scope="col" class="pe-4">Registration</th><th scope="col" class="pe-4">Customers (B2B / B2C)</th><th scope="col" class="pe-4">Determination</th><th scope="col" class="pe-4">Invoice requirements</th><th scope="col" class="pe-4">Payments</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @foreach ($this->matrix() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4 font-medium">{{ $row['name'] }}</td><td class="pe-4">{{ $row['currency'] }}</td><td class="pe-4">{{ $row['regime'] }}</td>
                        <td class="pe-4">{{ $row['registration'] }}</td><td class="pe-4">{{ $row['customers'] }}</td><td class="pe-4">{{ $row['determination'] }}</td>
                        <td class="pe-4">{{ $row['invoice_requirements'] }}</td><td class="pe-4">{{ $row['payments'] }}</td>
                        <td><x-filament::badge size="sm" :color="$row['status']->color()">{{ $row['status']->label() }}</x-filament::badge></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Markedge selling entities" description="Versioned; an invoice copies the version in force on its issue date.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Selling entities">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Entity</th><th scope="col" class="pe-4">Version</th><th scope="col" class="pe-4">Legal name</th><th scope="col" class="pe-4">Jurisdiction</th><th scope="col" class="pe-4">Tax registration</th><th scope="col">From</th></tr></thead>
            <tbody>
                @forelse ($this->suppliers() as $supplier)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><code>{{ $supplier->entity_code }}</code></td><td class="pe-4">v{{ $supplier->version }}</td><td class="pe-4">{{ $supplier->legal_name }}</td>
                        <td class="pe-4">{{ $supplier->subdivision ?? $supplier->country }}</td><td class="pe-4">{{ $supplier->tax_id_type?->label() }} {{ $supplier->tax_id_value ?? 'none' }}</td><td>{{ $supplier->effective_from->toDateString() }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No selling entity recorded. Markedge's entities and registrations are a business decision.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tax rules" description="Draft → review → verified by a second operator (or retired). Only verified rules apply, from their date.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tax rules">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Rule</th><th scope="col" class="pe-4">Status</th><th scope="col" class="pe-4">From</th><th scope="col" class="pe-4">Outcomes</th><th scope="col" class="pe-4">Rounding</th><th scope="col">Verification</th></tr></thead>
            <tbody>
                @forelse ($this->rules() as $rule)
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4"><code>{{ $rule->label() }}</code></td>
                        <td class="pe-4"><x-filament::badge size="sm" :color="$rule->status->color()">{{ $rule->status->value }}</x-filament::badge></td>
                        <td class="pe-4">{{ $rule->effective_from->toDateString() }}</td>
                        <td class="pe-4">@foreach ($rule->outcomes as $key => $components)<div>{{ $key }}: {{ $components === [] ? 'no tax' : collect($components)->map(fn ($c) => $c['type'].' '.$c['rate'].' %')->implode(' + ') }}</div>@endforeach</td>
                        <td class="pe-4">{{ $rule->rounding_mode->value }} per {{ $rule->rounding_stage }}</td>
                        <td>{{ $rule->verification_reference ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No tax rule. Rates and classifications come from a tax review; none is shipped.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Invoice number series" description="Numbers are allocated inside the issuing transaction: unique, consecutive, gap-free.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Invoice number series">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Entity</th><th scope="col" class="pe-4">Series</th><th scope="col" class="pe-4">Maximum length</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($this->series() as $series)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><code>{{ $series->supplier_entity }}</code></td><td class="pe-4">{{ $series->label() }}</td><td class="pe-4">{{ $series->max_length ?? '—' }}</td><td>{{ $series->status }}</td></tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">No series. The numbering format is a tax decision.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
