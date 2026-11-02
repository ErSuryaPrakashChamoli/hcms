<x-filament-panels::page>
    @php($modules = $this->modules)
    @php($searching = trim($term) !== '')
    <label class="pos-search pos-search-lg" style="max-width: none">
        <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5 pos-muted" />
        <span class="sr-only">Find a setting</span>
        <input type="search" wire:model.live.debounce.300ms="term" placeholder="working hours · designation · notice period · pf · letter template…" autocomplete="off" autofocus />
    </label>

    <x-pos.section :title="$searching ? 'Matches' : 'All modules'" :count="array_sum(array_map(fn ($m) => count($m['items']), $modules))">
        @if ($modules === [])
            <x-pos.state variant="filtered" size="inline" title="Nothing matches “{{ trim($term) }}”." why="Try another word, or clear the search to see every module. Only settings you have access to are shown." />
        @else
            {{-- A new search starts afresh on its first area (the key replaces the browser instead of morphing it). --}}
            <div wire:key="config-browse-{{ md5(trim($term)) }}">
                <x-pos.browse label="Configuration modules" :remember="$searching ? null : 'configuration-finder'" :groups="$this->browseGroups()" />
            </div>
        @endif
    </x-pos.section>
</x-filament-panels::page>
