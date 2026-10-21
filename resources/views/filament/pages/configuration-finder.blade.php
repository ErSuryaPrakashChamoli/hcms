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
            <div class="pos-manage-grid">
                @foreach ($modules as $module)
                    <div class="pos-panel pos-panel-pad pos-manage" x-data="{ all: false }" wire:key="config-module-{{ $module['key'] }}">
                        <div class="pos-manage-head">
                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$module['icon']" class="size-4" /></span>
                            <div class="min-w-0">
                                <h3 class="pos-stream-title font-semibold">{{ $module['label'] }}</h3>
                                <p class="pos-stream-meta">{{ $module['why'] }}</p>
                            </div>
                        </div>
                        <ul class="pos-manage-list">
                            @foreach ($module['items'] as $i => $item)
                                <li @if (! $searching && $i >= 6) x-show="all" x-cloak @endif>
                                    <a href="{{ $item['url'] }}" wire:navigate class="pos-module-link">{{ $item['label'] }}@if ($item['setting'])<span class="pos-meta ms-auto">Setting</span>@endif</a>
                                </li>
                            @endforeach
                        </ul>
                        @if (! $searching && count($module['items']) > 6)
                            <button type="button" class="pos-link" x-on:click="all = ! all" :aria-expanded="all.toString()"><span x-text="all ? 'Show fewer' : 'All {{ count($module['items']) }}'"></span></button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-pos.section>
</x-filament-panels::page>
