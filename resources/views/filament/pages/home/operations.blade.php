{{-- People operations (HR): the lens's headline figures as tiles, then what needs operational attention, most severe first --}}
@if (($h['operations'] ?? null) !== null)
    <x-pos.section title="People operations" :count="count($h['operations']) ?: null">
        <div class="pos-panel pos-home-card">
            <div class="pos-home-tiles">
                @foreach (['attention', 'joining', 'on_leave', 'approvals', 'requests'] as $k)
                    @if ($f = $kpi($k))
                        @include('filament.pages.home.tile', ['f' => $f])
                    @endif
                @endforeach
            </div>
            @if (count($h['operations']) > 0)
                <x-pos.phone-cap :total="count($h['operations'])">
                <div class="pos-stream pos-stream-divided pos-home-flush">
                    @foreach ($h['operations'] as $op)
                        <div class="pos-stream-row pos-signal-row" data-tone="{{ $op['severity'] }}">
                            <span class="pos-figure-value pos-signal-count">{{ $op['count'] }}</span>
                            <div class="pos-stream-body"><p class="pos-stream-title"><span class="pos-signal-text">{{ $op['title'] }}</span></p><p class="pos-stream-meta">{{ $op['why'] }}</p></div>
                            @if ($op['url'])<a href="{{ $op['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm pos-signal-link"><span class="pos-signal-verb">Open<span class="sr-only">: {{ $op['title'] }}</span></span><x-filament::icon icon="heroicon-m-chevron-right" class="pos-signal-chevron size-5" /></a>@else<span></span>@endif
                        </div>
                    @endforeach
                </div>
                </x-pos.phone-cap>
            @else
                <x-pos.state variant="caught-up" size="inline" class="pos-home-flush" title="Nothing is at risk today." why="Joiners, probation, onboarding, SLAs, documents, workflows and exits are on track." />
            @endif
        </div>
    </x-pos.section>
@endif
