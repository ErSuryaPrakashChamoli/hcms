{{-- What changed since your last visit (business events, each opens in context) --}}
@if ($h['changes'] !== null)
    @php($c = $h['changes'])
    <x-pos.section title="What changed" :count="$c['count'] ?: null"
        :sub="$c['count'] === 0 ? null : (($c['since'] ? 'Since your last visit on '.$c['since']->format('j M') : 'In the last seven days').': '.$c['summary'].'.')">
        @if ($c['count'] === 0)
            <x-pos.state variant="caught-up" size="inline" :title="$c['since'] ? 'Nothing changed since your last visit.' : 'Nothing changed in the last seven days.'" why="Joiners, moves, reporting changes, exits and announcements you can see appear here." />
        @else
            <div class="pos-panel pos-stream">
                @foreach ($c['items'] as $item)
                    <button type="button" class="pos-stream-row" data-tone="{{ $item['tone'] === 'primary' ? 'info' : $item['tone'] }}" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'change', id: @js($item['id']) })">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$item['icon']" class="size-4" /></span>
                        <span class="pos-stream-body">
                            <span class="pos-stream-title">{{ $item['title'] }}</span>
                            <span class="pos-stream-meta">{{ $item['label'] }}@if ($item['subject']) · {{ $item['subject'] }}@endif · {{ $item['at']->isToday() ? 'today' : $item['at']->diffForHumans() }}</span>
                        </span>
                        <span aria-hidden="true" class="pos-muted">→</span>
                    </button>
                @endforeach
                @if ($c['count'] > $c['items']->count() && \App\Filament\Pages\ChangeIntelligencePage::canAccess())
                    <div class="pos-stream-more"><a href="{{ \App\Filament\Pages\ChangeIntelligencePage::getUrl() }}" wire:navigate class="pos-link">All {{ $c['count'] }} changes</a></div>
                @endif
            </div>
        @endif
    </x-pos.section>
@endif
