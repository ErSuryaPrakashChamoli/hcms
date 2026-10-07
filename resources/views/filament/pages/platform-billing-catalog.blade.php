<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300 max-w-4xl">Prices are per market, in the market's currency, and versioned on their own: changing one market's price never touches another's (no price is ever derived from another market's by an exchange rate), and a new version never re-prices a subscriber (billing terms pin a version; an increase needs 30 days' notice). A per-employee amount is per employee per month. Publishing needs a second operator's approval. No market, price or amount is created by PeopleOS: they are commercial decisions. Price is never read by entitlements or authorisation.</p>
    </x-filament::section>

    <x-filament::section heading="Markets" description="One currency each; the selling Markedge entity; how amounts are displayed.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Markets">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Code</th><th scope="col" class="pe-4">Name</th><th scope="col" class="pe-4">Currency</th><th scope="col" class="pe-4">Countries</th><th scope="col" class="pe-4">Selling entity</th><th scope="col">Display locale</th></tr></thead>
            <tbody>
                @forelse ($this->markets() as $market)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4 font-medium">{{ $market->code }}</td><td class="pe-4">{{ $market->name }}</td><td class="pe-4"><code>{{ $market->currency->value }}</code></td><td class="pe-4">{{ implode(', ', $market->countries) }}</td><td class="pe-4"><code>{{ $market->supplier_entity }}</code></td><td>{{ $market->locale }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500 dark:text-gray-400">No market yet. Markets, currencies and prices are business decisions; none is created by default.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Prices" description="Each price: one published plan version, one market, one interval. Versions: draft → published (from a date, never changed) → retired.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Prices">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Plan version</th><th scope="col" class="pe-4">Market</th><th scope="col" class="pe-4">Basis and interval</th><th scope="col" class="pe-4">On sale today</th><th scope="col">Versions</th></tr></thead>
            <tbody>
                @forelse ($this->prices() as $price)
                    @php($current = $this->onSaleToday($price))
                    <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-1 pe-4"><code>{{ $price->planVersion->label() }}</code></td>
                        <td class="pe-4">{{ $price->market->code }}</td>
                        <td class="pe-4">{{ $price->basis->label() }}, {{ $price->interval->label() }}</td>
                        <td class="pe-4 font-medium">{{ $current ? $this->money($current, $price->market) : 'not on sale' }}</td>
                        <td>
                            <ul class="space-y-0.5">
                                @foreach ($price->versions as $version)
                                    <li>v{{ $version->version }} · <x-filament::badge size="sm" :color="$version->status->getColor()">{{ $version->status->value }}</x-filament::badge> {{ $this->money($version, $price->market) }}{{ $version->minimum_quantity > 0 ? ' · minimum '.$version->minimum_quantity : '' }}{{ $version->effective_from ? ' from '.$version->effective_from->toDateString() : '' }}</li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No price yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Currency catalogue" description="ISO 4217 codes with their decimals. Listed is not sold: a currency is used only through a market." collapsible collapsed>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Currency catalogue">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Code</th><th scope="col" class="pe-4">Name</th><th scope="col">Decimals</th></tr></thead>
            <tbody>
                @foreach (\App\Support\Money\Currency::cases() as $currency)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4"><code>{{ $currency->value }}</code></td><td class="pe-4">{{ $currency->label() }}</td><td>{{ $currency->minorUnits() }}</td></tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
