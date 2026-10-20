{{--
    UX.16: a list of role signals (RoleSignals): a count when there is one, one sentence, the reason, one verb.
    UX.17: on phones the whole row is the link (same URL and accessible name) and long lists show three rows first.
    Expects: $items (list of signals), $title, optional $sub, $link, $linkLabel, $emptyTitle, $emptyWhy, $verb.
--}}
@if ($items !== [] || isset($emptyTitle))
    <x-pos.section :title="$title" :count="count($items) ?: null" :sub="$sub ?? null" :link="$link ?? null" :link-label="$linkLabel ?? null">
        @if ($items === [])
            <x-pos.state variant="caught-up" size="inline" :title="$emptyTitle" :why="$emptyWhy ?? null" />
        @else
            <x-pos.phone-cap :total="count($items)">
            <div class="pos-panel pos-stream">
                @foreach ($items as $s)
                    <div class="pos-stream-row pos-signal-row" data-tone="{{ $s['severity'] }}" wire:key="sig-{{ md5($s['key']) }}">
                        @if ($s['count'] !== null)
                            <span class="pos-figure-value pos-signal-count" aria-hidden="true">{{ number_format($s['count']) }}</span>
                        @else
                            <span class="pos-stream-mark" aria-hidden="true"></span>
                        @endif
                        <div class="pos-stream-body">
                            <p class="pos-stream-title">@if ($s['count'] !== null)<span class="sr-only">{{ number_format($s['count']) }} </span>@endif{{ $s['title'] }}</p>
                            <p class="pos-stream-meta">{{ $s['why'] }}</p>
                        </div>
                        <div class="pos-stream-end">
                            @if ($s['url'])<a href="{{ $s['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm pos-signal-link"><span class="pos-signal-verb">{{ $verb ?? 'Open' }}<span class="sr-only">: {{ $s['title'] }}</span></span><x-filament::icon icon="heroicon-m-chevron-right" class="pos-signal-chevron size-5" /></a>@endif
                        </div>
                    </div>
                @endforeach
            </div>
            </x-pos.phone-cap>
        @endif
    </x-pos.section>
@endif
