{{--
    UX.16 executive: the workforce as a story (the Workforce pulse headline from real movement this month against last),
    then what moved, then the figures, then Explore. Aggregates and permissions are the Workforce pulse page's own.
--}}
@php($w = $h['workforce'] ?? null)
<x-pos.section title="Workforce pulse" :link="\App\Filament\Pages\WorkforceCommandCentre::canAccess() ? \App\Filament\Pages\WorkforceCommandCentre::getUrl() : null" link-label="Explore">
    <div class="pos-panel pos-panel-pad grid gap-4">
        @if ($w)
            <p class="pos-body pos-workforce-headline">{{ $w['headline'] }}</p>
            @php($mv = $w['movement'])
            <dl class="pos-figure-list pos-movement" aria-label="Movement this month against last month">
                @foreach (['joiners' => 'Joiners', 'exits' => 'Exits', 'promotions' => 'Promotions', 'moves' => 'Internal moves'] as $k => $label)
                    <div><dt>{{ $label }}</dt><dd class="pos-num">{{ $mv['now'][$k] }} <span class="pos-muted font-normal">· {{ $mv['last'][$k] }} last month</span></dd></div>
                @endforeach
                @if ($mv['critical'] !== null)
                    <div><dt>Critical positions at risk</dt><dd class="pos-num">{{ $mv['critical'] }}</dd></div>
                @endif
            </dl>
        @endif
        <div class="pos-figures">
            @foreach (['headcount', 'joiners', 'exits', 'attrition', 'on_leave'] as $k)
                {{-- UX.17: on phones joiners and exits are already in the movement above, so the strip keeps headcount, attrition and leave --}}
                @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" @class(['pos-wide-only' => in_array($k, ['joiners', 'exits'], true)]) />@endif
            @endforeach
        </div>
        @php($series = array_values($w['size']['series']['series'] ?? [])[0] ?? [])
        @if (count($series) > 1)
            {{-- UX.17: no tiny trend chart on phones; the trend lives on Workforce pulse --}}
            <div class="pos-wide-only"><x-pos.sparkline zero :values="$series" :labels="$w['size']['series']['labels'] ?? []" label="Headcount, last twelve months" /></div>
        @endif
        @if (($w['decisions'] ?? []) !== [])
            <div class="pos-stream pos-stream-divided">
                @foreach ($w['decisions'] as $d)
                    <div class="pos-stream-row" data-tone="{{ $d['severity'] }}">
                        <span class="pos-figure-value pos-signal-count">{{ $d['count'] }}</span>
                        <div class="pos-stream-body"><p class="pos-stream-title">{{ $d['title'] }}</p><p class="pos-stream-meta">{{ $d['why'] }}</p></div>
                        <span></span>
                    </div>
                @endforeach
            </div>
        @endif
        <p class="pos-meta">Counts from PeopleOS records you are allowed to see. Nothing here is estimated or predicted.</p>
    </div>
</x-pos.section>
