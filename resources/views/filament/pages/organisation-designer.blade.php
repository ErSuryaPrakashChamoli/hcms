<x-filament-panels::page>
    <style>
        .pos-tree ul { list-style: none; margin: 0; padding-left: 1.25rem; border-left: 1px dashed rgb(var(--gray-300)); }
        .pos-tree > ul { padding-left: 0; border-left: 0; }
        .pos-node { display: flex; align-items: center; gap: .5rem; padding: .375rem .5rem; border-radius: .5rem; }
        .pos-node:hover { background: rgb(var(--gray-100)); }
        .dark .pos-node:hover { background: rgb(var(--gray-800)); }
        .pos-node .pos-actions { margin-left: auto; display: flex; gap: .125rem; opacity: 0; }
        .pos-node:hover .pos-actions, .pos-node:focus-within .pos-actions { opacity: 1; }
        .pos-node.is-inactive .pos-name { text-decoration: line-through; opacity: .6; }
        .pos-node.is-match { background: rgb(var(--primary-100)); }
        .dark .pos-node.is-match { background: rgb(var(--primary-900)); }
        .pos-name { font-weight: 600; }
        .pos-code { font-size: .75rem; opacity: .7; }
        .pos-toggle { width: 1.25rem; text-align: center; cursor: pointer; opacity: .6; }
        .pos-empty { padding: 2rem; text-align: center; opacity: .7; }

        .pos-chart { overflow-x: auto; padding: 1rem 0; }
        .pos-chart ul { display: flex; justify-content: center; padding-top: 1.5rem; position: relative; list-style: none; margin: 0; }
        .pos-chart > ul { padding-top: 0; }
        .pos-chart li { position: relative; padding: 1.5rem .5rem 0; text-align: center; }
        .pos-chart > ul > li { padding-top: 0; }
        .pos-chart li::before, .pos-chart li::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 1.5rem; border-top: 1px solid rgb(var(--gray-400)); }
        .pos-chart li::after { right: auto; left: 50%; border-left: 1px solid rgb(var(--gray-400)); }
        .pos-chart li:only-child::before, .pos-chart li:only-child::after { border: 0; }
        .pos-chart li:only-child { padding-top: 1.5rem; }
        .pos-chart > ul > li:only-child { padding-top: 0; }
        .pos-chart li:only-child::after { border-left: 1px solid rgb(var(--gray-400)); border-top: 0; height: 1.5rem; }
        .pos-chart > ul > li:only-child::after { border: 0; }
        .pos-chart li:first-child::before, .pos-chart li:last-child::after { border: 0; }
        .pos-chart li:last-child::before { border-right: 1px solid rgb(var(--gray-400)); border-radius: 0 .5rem 0 0; }
        .pos-chart li:first-child::after { border-radius: .5rem 0 0 0; }
        .pos-chart ul ul::before { content: ''; position: absolute; top: 0; left: 50%; border-left: 1px solid rgb(var(--gray-400)); height: 1.5rem; }
        .pos-card { display: inline-block; min-width: 9rem; padding: .5rem .75rem; border: 1px solid rgb(var(--gray-300)); border-radius: .75rem; background: white; }
        .dark .pos-card { background: rgb(var(--gray-900)); border-color: rgb(var(--gray-700)); }
        .pos-card.is-inactive { opacity: .5; }
        .pos-card .pos-type { display: block; font-size: .65rem; text-transform: uppercase; letter-spacing: .05em; opacity: .7; }
    </style>

    <div x-data="{ mode: @entangle('mode') }" class="fi-section-content-ctn">
        <x-filament::section>
            <div style="display:flex; gap:.75rem; align-items:center; flex-wrap:wrap;">
                <div style="flex:1; min-width: 16rem;">
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Search units by name or code" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button size="sm" color="gray" x-on:click="mode = 'tree'" x-bind:outlined="mode !== 'tree'">Tree</x-filament::button>
                <x-filament::button size="sm" color="gray" x-on:click="mode = 'chart'" x-bind:outlined="mode !== 'chart'">Chart</x-filament::button>
            </div>
        </x-filament::section>

        @php($tree = $this->tree)

        <x-filament::section>
            @if ($tree->isEmpty())
                <div class="pos-empty">
                    @if (filled($search))
                        No units match “{{ $search }}”.
                    @else
                        No organisation units placed yet. Start with <strong>Add root unit</strong> and place a company at the top.
                    @endif
                </div>
            @else
                <div class="pos-tree" x-show="mode === 'tree'">
                    <ul>
                        @foreach ($tree as $node)
                            @include('filament.pages.partials.organisation-node', ['node' => $node])
                        @endforeach
                    </ul>
                </div>
                <div class="pos-chart" x-show="mode === 'chart'" x-cloak>
                    <ul>
                        @foreach ($tree as $node)
                            @include('filament.pages.partials.organisation-chart-node', ['node' => $node])
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
