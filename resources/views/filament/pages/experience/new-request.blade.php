<x-filament-panels::page>
    @php($c = $this->catalogue)
    <div class="pos-ws pos-new-request">
        <div class="pos-toolbar">
            <label class="pos-search">
                <span class="sr-only">Search requests</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search: leave, loan, travel, asset…" autocomplete="off" />
            </label>
        </div>

        @foreach ($c['locks'] as $lock)
            <x-pos.state variant="denied" size="inline" title="Some requests are locked for now" :why="$lock" />
        @endforeach

        @if ($c['favourites'] !== [])
            <x-pos.section title="Favourites" :count="count($c['favourites'])" sub="The requests you starred, first.">
                <ul class="pos-panel pos-panel-pad pos-action-grid md:grid-cols-2 lg:grid-cols-3" aria-label="Favourite requests">
                    @foreach ($c['favourites'] as $entry)
                        @include('filament.pages.experience.partials.request-tile', ['entry' => $entry, 'star' => true])
                    @endforeach
                </ul>
            </x-pos.section>
        @endif

        @forelse ($c['groups'] as $module => $entries)
            <x-pos.section :title="$module" :count="count($entries)">
                <ul class="pos-panel pos-panel-pad pos-action-grid md:grid-cols-2 lg:grid-cols-3" aria-label="{{ $module }} requests">
                    @foreach ($entries as $entry)
                        @include('filament.pages.experience.partials.request-tile', ['entry' => $entry, 'star' => true])
                    @endforeach
                </ul>
            </x-pos.section>
        @empty
            @if (trim($this->search) !== '')
                <x-pos.state variant="filtered" title="No request matches your search." why="Try another word, or clear the search to see everything you can request." />
            @elseif ($c['behalf'] === [])
                <x-pos.state variant="empty" title="There is nothing you can request here right now." why="Requests appear once your role and your organisation's policies allow them." />
            @endif
        @endforelse

        @if ($c['behalf'] !== [])
            <x-pos.section title="Raise for someone" :count="count($c['behalf'])" sub="Each form asks whom the request is for; the module's own rules for requests made on someone's behalf apply.">
                <ul class="pos-panel pos-panel-pad pos-action-grid md:grid-cols-2 lg:grid-cols-3" aria-label="Requests on someone's behalf">
                    @foreach ($c['behalf'] as $entry)
                        @include('filament.pages.experience.partials.request-tile', ['entry' => $entry, 'star' => false])
                    @endforeach
                </ul>
            </x-pos.section>
        @endif
    </div>
</x-filament-panels::page>
