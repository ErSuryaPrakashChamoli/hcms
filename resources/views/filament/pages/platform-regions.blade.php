<x-filament-panels::page>
    <x-filament::section heading="Region list" description="Each country's states, provinces, emirates or other regions, pre-filled from ISO 3166-2. Holiday library uploads may only use these codes (with or without the country prefix), and take the names shown here. Tenants' locations are matched to them by code or name, so the holiday picker can start on the region their offices are in.">
        <label class="block max-w-sm text-sm">Country
            <select wire:model.live="country" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                @foreach ($this->countries() as $code => $name)
                    <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                @endforeach
            </select>
        </label>

        @php($regions = $this->regions())
        @php($counts = $this->listCounts())
        @if ($regions->isEmpty())
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">This country has no regions on the list: its holidays can only be uploaded as a national list. Use Add region if it needs regional holidays.</p>
        @else
            <div class="mt-4 overflow-x-auto" tabindex="0" role="region" aria-label="Regions">
                <table class="w-full text-sm">
                    <caption class="sr-only">Regions of the selected country</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Code</th><th scope="col" class="pe-3">Name</th><th scope="col" class="pe-3">Source</th><th scope="col" class="pe-3">Status</th><th scope="col" class="pe-3">Holiday lists</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($regions as $region)
                            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                <td class="py-1 pe-3 whitespace-nowrap font-mono">{{ $region->isoCode() }}</td>
                                <td class="pe-3">{{ $region->name }}</td>
                                <td class="pe-3 text-xs">{{ $region->source === \App\Domain\Attendance\Models\CountryRegion::SOURCE_ISO ? 'ISO 3166-2' : 'Added by the platform' }}</td>
                                <td class="pe-3"><x-filament::badge :color="$region->isActive() ? 'success' : 'gray'">{{ ucfirst($region->status) }}</x-filament::badge></td>
                                <td class="pe-3 tabular-nums">{{ $counts[$region->code] ?? 0 }}</td>
                                <td class="space-x-1 whitespace-nowrap">
                                    {{ ($this->renameAction)(['region' => $region->id]) }}
                                    @if ($region->isActive()){{ ($this->retireAction)(['region' => $region->id]) }}@else{{ ($this->restoreAction)(['region' => $region->id]) }}@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
