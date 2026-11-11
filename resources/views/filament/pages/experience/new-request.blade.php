<x-filament-panels::page>
    @php($c = $this->catalogue)
    <div class="pos-ws pos-new-request">
        {{-- The masthead: a finder over everything this person may request, with plain-word examples under it --}}
        <x-pos.masthead icon="heroicon-m-plus" eyebrow="New request" title="What do you need?" :brief="$this->getSubheading()">
            <div class="pos-adm-find pos-mast-find">
                <label class="pos-adm-search">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5" />
                    <span class="sr-only">Search requests</span>
                    <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search: leave, loan, travel, asset…" autocomplete="off" />
                    <x-filament::loading-indicator wire:loading wire:target="search" class="size-4 pos-muted" />
                </label>
                <div class="pos-adm-try">
                    <span class="pos-adm-try-label">Try</span>
                    @foreach (['leave', 'attendance', 'expense', 'loan', 'asset'] as $word)
                        <button type="button" class="pos-adm-chip" wire:click="$set('search', @js($word))">{{ $word }}</button>
                    @endforeach
                    <x-filament::actions :actions="$this->getCachedHeaderActions()" class="pos-mast-actions ms-auto" />
                </div>
            </div>
        </x-pos.masthead>

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
