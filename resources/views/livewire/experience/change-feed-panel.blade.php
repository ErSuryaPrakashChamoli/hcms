<section class="pos-card pos-enter-3" aria-labelledby="pos-changes-title">
    <header class="pos-card-head">
        <div>
            <p class="pos-label">What changed</p>
            <h2 id="pos-changes-title" class="pos-h3 mt-1">Last 30 days</h2>
        </div>
        @if (count($types) > 1)
            <div class="pos-chips" role="group" aria-label="Filter changes">
                <button type="button" class="pos-chip" aria-pressed="{{ $type === 'all' ? 'true' : 'false' }}" wire:click="filter('all')">All</button>
                @foreach ($types as $key => $label)
                    <button type="button" class="pos-chip" aria-pressed="{{ $type === $key ? 'true' : 'false' }}" wire:click="filter('{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
        @endif
    </header>

    @if ($items->isEmpty())
        <x-pos.empty class="mt-4" icon="heroicon-o-arrows-right-left" :title="$type === 'all' ? 'No changes in the last 30 days' : 'No changes of this type'"
            :why="$type === 'all' ? 'Joiners, moves, reporting changes and announcements you can see will appear here.' : 'Try another filter.'" />
    @else
        <ol class="pos-feed mt-4">
            @foreach ($items as $item)
                <li class="pos-feed-item" data-tone="{{ $item['tone'] }}">
                    <span class="pos-feed-icon" aria-hidden="true"><x-filament::icon :icon="$item['icon']" class="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="pos-body">
                            @if ($item['subject'])<span class="font-medium">{{ $item['subject'] }}</span> · @endif{{ $item['title'] }}
                        </p>
                        @if ($item['detail'])<p class="pos-caption pos-muted line-clamp-2">{{ $item['detail'] }}</p>@endif
                        <p class="pos-caption pos-muted mt-0.5"><span class="pos-feed-type">{{ $item['label'] }}</span> · <time datetime="{{ $item['at']->toDateString() }}">{{ $item['at']->isToday() ? 'Today' : ($item['at']->isFuture() ? 'From '.$item['at']->format('d M') : $item['at']->diffForHumans()) }}</time></p>
                    </div>
                    @if ($item['url'])
                        <a href="{{ $item['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm" aria-label="Open {{ $item['subject'] ?? $item['title'] }}">Open</a>
                    @elseif ($item['subject_id'])
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $item['subject_id'] }} })">Preview</button>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($hasMore)
            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm mt-3" wire:click="more">Show more</button>
        @endif
    @endif
</section>
