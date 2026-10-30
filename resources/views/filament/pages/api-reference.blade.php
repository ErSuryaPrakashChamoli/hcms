<x-filament-panels::page>
    @php($spec = $this->openApi())
    <x-filament::section :heading="($spec['info']['title'] ?? 'API') . ' ' . ($spec['info']['version'] ?? '')" description="Base URL: this PeopleOS address. Authenticate with a tenant API key in X-Api-Key (or Authorization: Bearer)." collapsible>
        <div class="space-y-4 text-sm">
            @foreach ($this->introduction() as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </div>
    </x-filament::section>

    <div>
        <x-filament::input.wrapper>
            <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Search paths, summaries or scopes" aria-label="Search the API reference" />
        </x-filament::input.wrapper>
    </div>

    @forelse ($this->operationGroups() as $group => $operations)
        <x-filament::section :heading="ucfirst(str_replace(['-', '_'], ' ', $group))" :description="count($operations) . ' operation(s)'" collapsible>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ ucfirst($group) }} operations">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs uppercase text-gray-500">
                            <th class="py-2 pe-3">Method</th>
                            <th class="py-2 pe-3">Path</th>
                            <th class="py-2 pe-3">What it does</th>
                            <th class="py-2 pe-3">Scope</th>
                            <th class="py-2 pe-3">Parameters</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($operations as $op)
                            <tr class="align-top">
                                <td class="py-2 pe-3 whitespace-nowrap"><x-filament::badge :color="$op['method'] === 'GET' ? 'gray' : 'warning'">{{ $op['method'] }}</x-filament::badge></td>
                                <td class="py-2 pe-3 font-mono text-xs break-all">{{ $op['path'] }}</td>
                                <td class="py-2 pe-3">{{ $op['summary'] }}</td>
                                <td class="py-2 pe-3 whitespace-nowrap">
                                    <span class="font-mono text-xs">{{ $op['scope'] ?? '—' }}</span>
                                    @if ($op['sensitive'])
                                        <x-filament::badge color="danger" size="sm">sensitive</x-filament::badge>
                                    @endif
                                </td>
                                <td class="py-2 pe-3 text-xs text-gray-500">{{ $op['parameters'] === [] ? '—' : implode(', ', $op['parameters']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500">No operation matches “{{ $search }}”.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
