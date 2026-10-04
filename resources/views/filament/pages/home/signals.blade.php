{{--
    UX.16: a list of role signals (RoleSignals): a count when there is one, one sentence, the reason, one verb.
    Expects: $items (list of signals), $title, optional $sub, $link, $linkLabel, $emptyTitle, $emptyWhy, $verb.
--}}
@if ($items !== [] || isset($emptyTitle))
    <x-pos.section :title="$title" :count="count($items) ?: null" :sub="$sub ?? null" :link="$link ?? null" :link-label="$linkLabel ?? null">
        @if ($items === [])
            <x-pos.state variant="caught-up" size="inline" :title="$emptyTitle" :why="$emptyWhy ?? null" />
        @else
            <div class="pos-panel pos-stream">
                @foreach ($items as $s)
                    <div class="pos-stream-row" data-tone="{{ $s['severity'] }}" wire:key="sig-{{ md5($s['key']) }}">
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
                            @if ($s['url'])<a href="{{ $s['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">{{ $verb ?? 'Open' }}<span class="sr-only">: {{ $s['title'] }}</span></a>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-pos.section>
@endif
