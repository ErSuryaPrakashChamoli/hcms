<x-filament-panels::page>
    <div class="pos-toolbar">
        <div class="pos-segmented" role="group" aria-label="View">
            <button type="button" wire:click="$set('mode', 'people')" aria-pressed="{{ $mode === 'people' ? 'true' : 'false' }}">Reporting lines</button>
            <button type="button" wire:click="$set('mode', 'departments')" aria-pressed="{{ $mode === 'departments' ? 'true' : 'false' }}">Departments</button>
        </div>
        @if ($mode === 'people')
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <label class="pos-search">
                    <span class="sr-only">Find a person in the map</span>
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                    <input type="search" wire:model.live.debounce.250ms="find" x-on:focus="open = true" x-on:input="open = true" placeholder="Find and focus a person" autocomplete="off" />
                </label>
                @if (count($this->matches))
                    <ul class="pos-popover" x-show="open" role="listbox" aria-label="Matches">
                        @foreach ($this->matches as $m)
                            <li><button type="button" role="option" class="pos-popover-item" wire:click="focusOn({{ $m['id'] }})" x-on:click="open = false"><x-pos.avatar :name="$m['name']" size="xs" />{{ $m['name'] }}<span class="pos-caption pos-muted">{{ $m['meta'] }}</span></button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="ms-auto flex items-center gap-1">
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="expandAll">Expand all</button>
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="collapseAll">Collapse</button>
            </div>
        @endif
    </div>

    @if ($mode === 'departments')
        @php($depts = $this->departments)
        @if ($depts === [])
            <x-pos.empty class="mt-6" icon="heroicon-o-building-office" title="No departments to show" why="People you can see are not placed in departments yet." />
        @else
            @php($max = max(array_column($depts, 'headcount')) ?: 1)
            <ul class="pos-dept-grid mt-4">
                @foreach ($depts as $d)
                    <li class="pos-card">
                        <p class="pos-body font-medium">{{ $d['name'] }}</p>
                        <p class="pos-metric pos-num mt-1">{{ $d['headcount'] }} <span class="pos-caption pos-muted">{{ \Illuminate\Support\Str::plural('person', $d['headcount']) }}</span></p>
                        <div class="pos-meter mt-2" aria-hidden="true"><span style="width: {{ round($d['headcount'] / $max * 100) }}%"></span></div>
                        <div class="mt-3 flex items-center justify-between gap-2">
                            @if ($d['open'] !== null)
                                <x-pos.status :tone="$d['open'] > 0 ? 'warning' : 'neutral'" :label="$d['open'].' open '.\Illuminate\Support\Str::plural('position', $d['open'])" />
                            @else
                                <span></span>
                            @endif
                            @if (\App\Filament\Pages\People::canAccess())
                                <a href="{{ \App\Filament\Pages\People::getUrl(['department' => $d['id']]) }}" wire:navigate class="pos-link pos-caption">People <span aria-hidden="true">→</span></a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        @php($roots = $this->roots())
        @if ($roots === [])
            <x-pos.empty class="mt-6" icon="heroicon-o-rectangle-group" title="No reporting lines to show" why="People you can see have no current reporting relationships yet." />
        @else
            <div class="pos-org-canvas mt-4" x-data="posPanZoom()" x-init="$nextTick(() => { $el.scrollLeft = Math.max(0, ($el.scrollWidth - $el.clientWidth) / 2) })" tabindex="0" role="application" aria-label="Organisation map. Use arrow keys to pan, plus and minus to zoom, zero to reset."
                x-on:mousedown="start($event)" x-on:mousemove.window="drag($event)" x-on:mouseup.window="end()"
                x-on:touchstart.passive="start($event)" x-on:touchmove.passive="drag($event)" x-on:touchend="end()" x-on:wheel="wheel($event)" x-on:keydown="key($event)"
                x-on:pos-org-focus.window="$nextTick(() => { reset(); document.querySelector('[data-org-id=\'' + $event.detail.id + '\']')?.scrollIntoView({ block: 'center', inline: 'center' }) })"
                :class="dragging && 'is-dragging'">
                <div class="pos-org-zoom" role="group" aria-label="Zoom">
                    <button type="button" class="pos-icon-btn" x-on:click="zoom(0.1)" aria-label="Zoom in"><x-filament::icon icon="heroicon-m-plus" class="size-4" /></button>
                    <span class="pos-caption pos-num w-10 text-center" x-text="Math.round(scale * 100) + '%'"></span>
                    <button type="button" class="pos-icon-btn" x-on:click="zoom(-0.1)" aria-label="Zoom out"><x-filament::icon icon="heroicon-m-minus" class="size-4" /></button>
                    <button type="button" class="pos-icon-btn" x-on:click="reset()" aria-label="Reset view"><x-filament::icon icon="heroicon-m-arrows-pointing-in" class="size-4" /></button>
                </div>
                <div class="pos-org-stage" :style="style">
                    @php($rootNodes = $this->nodes(array_slice($roots, 0, 40)))
                    <ul class="pos-org-tree" role="tree" aria-label="Reporting lines">
                        @foreach (array_slice($roots, 0, 40) as $root)
                            @include('filament.pages.experience.org-node', ['id' => $root, 'nodes' => $rootNodes, 'level' => 1])
                        @endforeach
                    </ul>
                    @if (count($roots) > 40)
                        <p class="pos-caption pos-muted mt-4">{{ count($roots) - 40 }} more people without a visible manager. Use “Find and focus” to open them.</p>
                    @endif
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
