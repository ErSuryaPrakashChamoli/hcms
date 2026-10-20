<section class="pos-card" aria-labelledby="pos-wc-title">
    <header class="pos-card-head">
        <h2 id="pos-wc-title" class="pos-h3">What changed?</h2>
        @if ($hasMore)<button type="button" class="pos-link" wire:click="more">See more <span aria-hidden="true">→</span></button>@endif
    </header>
    @if ($items->isEmpty())
        <p class="pos-body-sm mt-3">No changes you can see in the last 30 days. Joiners, moves and announcements appear here.</p>
    @else
        <ol class="pos-wc mt-3">
            @foreach ($items as $item)
                <li class="pos-wc-row" data-tone="{{ $item['tone'] }}">
                    <time class="pos-wc-time pos-num" datetime="{{ $item['at']->toDateString() }}">{{ $item['at']->isToday() ? 'Today' : ($item['at']->isYesterday() ? 'Yday' : $item['at']->format('d M')) }}</time>
                    <span class="pos-wc-dot" aria-hidden="true"></span>
                    @if ($item['subject'])
                        <x-pos.avatar :name="$item['subject']" size="sm" />
                    @else
                        <x-pos.tile-icon :tone="$item['tone'] === 'warning' ? 'amber' : ($item['tone'] === 'accent' ? 'gold' : 'violet')" :icon="$item['icon']" size="sm" />
                    @endif
                    <div class="min-w-0 flex-1">
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" wire:navigate class="pos-body-sm font-medium text-pos-text hover:underline">@if ($item['subject']){{ $item['subject'] }} · @endif{{ $item['title'] }}</a>
                        @elseif ($item['subject_id'])
                            <button type="button" class="pos-body-sm font-medium text-pos-text text-start hover:underline" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $item['subject_id'] }} })">{{ $item['subject'] }} · {{ $item['title'] }}</button>
                        @else
                            <p class="pos-body-sm font-medium text-pos-text">{{ $item['title'] }}</p>
                        @endif
                        <p class="pos-caption">{{ $item['label'] }}@if ($item['detail']) · {{ \Illuminate\Support\Str::limit($item['detail'], 48) }}@endif</p>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
