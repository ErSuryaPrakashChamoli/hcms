<x-filament-panels::page>
    <x-filament::section compact>
        <x-filament::input.wrapper>
            <x-filament::input type="search" wire:model.live.debounce.300ms="term" placeholder="working hours · designation · notice period · pf · letter template…" autofocus />
        </x-filament::input.wrapper>
    </x-filament::section>
    <x-filament::section>
        @php($results = $this->getResults())
        @if ($this->term === '')
            <div class="text-sm text-gray-500">Type what you are trying to set up. Examples: "working hours" finds shifts, work schedules and attendance policies; "designation" finds designations, levels, grades and job families.</div>
        @else
            @forelse ($results as $r)
                <div class="py-2 border-b border-gray-100 dark:border-gray-800 last:border-0"><x-filament::link :href="$r['url']">{{ $r['label'] }}</x-filament::link></div>
            @empty
                <div class="text-sm text-gray-500">Nothing matches "{{ $this->term }}". Try a different word.</div>
            @endforelse
        @endif
    </x-filament::section>
</x-filament-panels::page>
