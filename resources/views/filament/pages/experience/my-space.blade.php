<x-filament-panels::page>
    @php($c = $this->catalogue)
    <div class="pos-ws pos-my-space">
        <div class="pos-toolbar">
            <label class="pos-search">
                <span class="sr-only">Search My space</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search: payslip, leave, goals, assets…" autocomplete="off" />
            </label>
        </div>

        @if (count($c['chips']) > 1)
            <div class="pos-chips" role="group" aria-label="Filter My space by module">
                <button type="button" class="pos-chip" aria-pressed="{{ $this->group === 'all' ? 'true' : 'false' }}" wire:click="setGroup('all')">All · {{ $c['total'] }}</button>
                @foreach ($c['chips'] as $key => $chip)
                    <button type="button" class="pos-chip" aria-pressed="{{ $this->group === $key ? 'true' : 'false' }}" wire:click="setGroup(@js($key))" data-myspace-group="{{ $key }}">{{ $chip['label'] }} · {{ $chip['count'] }}</button>
                @endforeach
            </div>
        @endif

        @if ($c['favourites'] !== [])
            <x-pos.section title="Favourites" :count="count($c['favourites'])" sub="The pages you hearted, first. They also sit at the top of your avatar menu.">
                <ul class="pos-panel pos-panel-pad pos-action-grid md:grid-cols-2 lg:grid-cols-3" aria-label="Favourite pages">
                    @foreach ($c['favourites'] as $entry)
                        @include('filament.pages.experience.partials.myspace-tile', ['entry' => $entry, 'context' => 'favourite'])
                    @endforeach
                </ul>
            </x-pos.section>
        @endif

        @forelse ($c['groups'] as $key => $group)
            <x-pos.section :title="$group['label']" :count="count($group['items'])">
                <ul class="pos-panel pos-panel-pad pos-action-grid md:grid-cols-2 lg:grid-cols-3" aria-label="{{ $group['label'] }} pages">
                    @foreach ($group['items'] as $entry)
                        @include('filament.pages.experience.partials.myspace-tile', ['entry' => $entry, 'context' => $key])
                    @endforeach
                </ul>
            </x-pos.section>
        @empty
            @if (trim($this->search) !== '' || $this->group !== 'all')
                <x-pos.state variant="filtered" title="No page matches." why="Try another word, or choose All to see every page you can open." />
            @else
                <x-pos.state variant="empty" title="There is nothing in My space for you yet." why="Your own pages appear here once your role gives you access to them." />
            @endif
        @endforelse
    </div>
</x-filament-panels::page>
