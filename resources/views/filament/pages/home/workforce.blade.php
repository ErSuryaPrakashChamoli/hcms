{{--
    UX.16 executive: the workforce as a story. The hero already leads with the Workforce pulse headline, so this card
    carries the evidence: the headline figures, this month's movement against last month, the headcount trend, then
    what needs a decision. Aggregates and permissions are the Workforce pulse page's own.
--}}
@php($w = $h['workforce'] ?? null)
<x-pos.section title="Workforce pulse" :link="\App\Filament\Pages\WorkforceCommandCentre::canAccess() ? \App\Filament\Pages\WorkforceCommandCentre::getUrl() : null" link-label="Explore">
    <div class="pos-panel pos-home-card">
        <div class="pos-home-tiles">
            @foreach (['headcount', 'joiners', 'exits', 'attrition', 'on_leave'] as $k)
                {{-- UX.17: on phones joiners and exits are already in the movement below, so the strip keeps headcount, attrition and leave --}}
                @if ($f = $kpi($k))
                    @include('filament.pages.home.tile', ['f' => $f, 'class' => in_array($k, ['joiners', 'exits'], true) ? 'pos-wide-only' : null])
                @endif
            @endforeach
        </div>

        @if ($w)
            @php($mv = $w['movement'])
            @php($series = array_values($w['size']['series']['series'] ?? [])[0] ?? [])
            <div @class(['pos-home-pulse', 'is-solo' => count($series) < 2])>
                <div class="pos-home-block">
                    <p class="pos-home-kicker">This month</p>
                    <dl class="pos-home-move" aria-label="Movement this month against last month">
                        @foreach (['joiners' => 'Joiners', 'exits' => 'Exits', 'promotions' => 'Promotions', 'moves' => 'Internal moves'] as $k => $label)
                            <div class="pos-home-move-item">
                                <dt>{{ $label }}</dt>
                                <dd><span class="pos-home-move-now pos-num">{{ $mv['now'][$k] }}</span> <span class="pos-home-move-last">{{ $mv['last'][$k] }} last month</span></dd>
                            </div>
                        @endforeach
                        @if ($mv['critical'] !== null)
                            <div class="pos-home-move-item" @if ($mv['critical'] > 0) data-meaning="warning" @endif>
                                <dt>Critical positions at risk</dt>
                                <dd><span class="pos-home-move-now pos-num">{{ $mv['critical'] }}</span> <span class="pos-home-move-last">{{ $mv['critical'] > 0 ? 'need a successor' : 'all covered' }}</span></dd>
                            </div>
                        @endif
                    </dl>
                </div>
                @if (count($series) > 1)
                    {{-- UX.17: no trend chart on phones; the trend lives on Workforce pulse --}}
                    <div class="pos-home-block pos-home-trend pos-wide-only">
                        <p class="pos-home-kicker">Headcount, last twelve months</p>
                        <x-pos.sparkline zero :height="104" :values="$series" :labels="$w['size']['series']['labels'] ?? []" label="Headcount, last twelve months" />
                    </div>
                @endif
            </div>

            @if (($w['decisions'] ?? []) !== [])
                <div class="pos-stream pos-stream-divided pos-home-flush">
                    @foreach ($w['decisions'] as $d)
                        <div class="pos-stream-row pos-signal-row" data-tone="{{ $d['severity'] }}">
                            <span class="pos-figure-value pos-signal-count">{{ $d['count'] }}</span>
                            <div class="pos-stream-body"><p class="pos-stream-title"><span class="pos-signal-text">{{ $d['title'] }}</span></p><p class="pos-stream-meta">{{ $d['why'] }}</p></div>
                            <span></span>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
        <p class="pos-home-foot"><x-filament::icon icon="heroicon-m-shield-check" class="size-4" aria-hidden="true" /> Counts from PeopleOS records you are allowed to see. Nothing here is estimated or predicted.</p>
    </div>
</x-pos.section>
