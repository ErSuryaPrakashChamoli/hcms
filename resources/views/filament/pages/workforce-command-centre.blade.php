<x-filament-panels::page>
    @php
        $m = $this->metrics;
        $t = $this->trends;
        $fmt = fn ($metric) => ($metric['restricted'] ?? false) || $metric['value'] === null ? '—' : \App\Filament\Pages\DashboardViewer::formatValue($metric['value'], $metric['format']);
        $headline = [
            ['headcount', 'heroicon-o-users', 'indigo'], ['joiners_30d', 'heroicon-o-user-plus', 'emerald'], ['exits_30d', 'heroicon-o-arrow-right-start-on-rectangle', 'rose'],
            ['attrition_rate', 'heroicon-o-arrow-trending-down', 'amber'], ['absenteeism_rate', 'heroicon-o-calendar-days', 'sky'], ['people_cost', 'heroicon-o-banknotes', 'gold'],
        ];
        $series = fn (string $k) => array_values($t[$k]['series'])[0] ?? [];
    @endphp

    {{-- Headline --}}
    <section class="pos-kpis" aria-label="Headline metrics">
        @foreach ($headline as [$key, $icon, $tone])
            @if (isset($m[$key]))
                <div class="pos-kpi" title="{{ $m[$key]['hint'] }}">
                    <x-pos.tile-icon :tone="$tone" :icon="$icon" />
                    <div class="min-w-0">
                        <p class="pos-kpi-value pos-num">{{ $fmt($m[$key]) }}</p>
                        <p class="pos-kpi-label">{{ $m[$key]['label'] }}</p>
                    </div>
                </div>
            @endif
        @endforeach
    </section>

    <div class="pos-wcc">
        {{-- What changed --}}
        <section class="pos-card" aria-labelledby="wcc-changed">
            <p class="pos-label">What changed</p>
            <h2 id="wcc-changed" class="pos-h3 mt-1">This month against last month</h2>
            <div class="pos-metrics mt-4">
                @forelse ($this->getChanges() as $c)
                    @php($d = $c['now'] - $c['before'])
                    <div class="pos-metric-cell">
                        <p class="pos-caption">{{ $c['label'] }}</p>
                        <p class="pos-metric pos-num">{{ number_format($c['now']) }}</p>
                        <p class="pos-caption {{ $d === 0.0 ? '' : (($d > 0) !== $c['rising_is_bad'] ? 'text-pos-success' : 'text-pos-danger') }}">{{ $d === 0.0 ? 'No change' : ($d > 0 ? '↑ '.number_format($d) : '↓ '.number_format(abs($d))).' vs last month' }}</p>
                    </div>
                @empty
                    <p class="pos-body-sm">Not enough history yet.</p>
                @endforelse
            </div>
        </section>

        {{-- Requires a decision --}}
        <section class="pos-card" aria-labelledby="wcc-decide">
            <p class="pos-label">Requires a decision</p>
            <h2 id="wcc-decide" class="pos-h3 mt-1">Waiting on you or your approval body</h2>
            <ul class="mt-3 space-y-2">
                @forelse ($this->getDecisions() as $dec)
                    <li>
                        <a @if ($dec['url']) href="{{ $dec['url'] }}" wire:navigate @endif class="pos-company-row">
                            <x-pos.tile-icon :tone="$dec['tone']" icon="heroicon-o-check-badge" size="sm" />
                            <span class="flex-1 pos-body-sm font-medium text-pos-text">{{ $dec['title'] }}</span>
                            <span class="pos-count" data-tone="{{ $dec['count'] > 0 ? 'danger' : 'neutral' }}">{{ $dec['count'] }}</span>
                        </a>
                    </li>
                @empty
                    <li class="pos-body-sm">Nothing is waiting for a decision from you.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Needs attention --}}
    <section class="pos-card" aria-labelledby="wcc-attention">
        <p class="pos-label">Needs attention</p>
        <h2 id="wcc-attention" class="pos-h3 mt-1">{{ count($this->getRisks()) === 0 ? 'No workforce risk crosses its threshold' : 'Risks above their thresholds' }}</h2>
        @if (count($this->getRisks()) > 0)
            <ul class="pos-list mt-3">
                @foreach ($this->getRisks() as $r)
                    <li class="pos-list-row pos-attention" data-severity="{{ $r['severity'] }}">
                        <span class="pos-severity" aria-hidden="true"></span>
                        <span class="pos-metric pos-num w-20 shrink-0">{{ $r['value'] }}</span>
                        <div class="min-w-0 flex-1"><p class="pos-body font-medium">{{ $r['title'] }}</p><p class="pos-caption">{{ $r['why'] }}</p></div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Trending --}}
    <div class="pos-wcc">
        <section class="pos-card" aria-labelledby="wcc-trend-hc">
            <p class="pos-label">Trending</p>
            <h2 id="wcc-trend-hc" class="pos-h3 mt-1">Headcount · last 12 months</h2>
            @if (count($series('headcount')) > 1)
                <x-pos.sparkline :values="$series('headcount')" :labels="$t['headcount']['labels']" label="Headcount, last 12 months" class="mt-4" />
            @endif
        </section>
        <section class="pos-card" aria-labelledby="wcc-trend-flow">
            <p class="pos-label">Trending</p>
            <h2 id="wcc-trend-flow" class="pos-h3 mt-1">Joiners and leavers · last 12 months</h2>
            <x-pos.columns class="mt-4" :labels="$t['joiners']['labels']" label="Joiners and leavers by month"
                :series="[['name' => 'Joined', 'values' => $series('joiners'), 'color' => 2], ['name' => 'Left', 'values' => $series('exits'), 'color' => 4]]" />
        </section>
    </div>

    @if (! ($m['people_cost']['restricted'] ?? false) && count($series('people_cost')) > 1 && array_sum($series('people_cost')) > 0)
        <section class="pos-card" aria-labelledby="wcc-cost">
            <p class="pos-label">Trending</p>
            <h2 id="wcc-cost" class="pos-h3 mt-1">People cost · finalized payroll, last 12 months</h2>
            <x-pos.sparkline :values="$series('people_cost')" :labels="$t['people_cost']['labels']" label="People cost, last 12 months" class="mt-4" />
        </section>
    @endif

    <div class="pos-wcc">
        <x-filament::section heading="Critical skills" description="Skills with the fewest advanced or expert holders — succession and training risk.">
            <table class="pos-table">
                <thead><tr><th scope="col">Skill</th><th scope="col" class="text-end">Holders</th><th scope="col" class="text-end">Advanced / expert</th></tr></thead>
                <tbody>
                    @forelse ($this->getCriticalSkills() as $s)
                        <tr><td>{{ $s['skill'] }}</td><td class="text-end pos-num">{{ $s['holders'] }}</td><td class="text-end pos-num {{ $s['experts'] === 0 ? 'text-pos-danger font-semibold' : '' }}">{{ $s['experts'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="pos-caption">No skills recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <section class="pos-card" aria-labelledby="wcc-more">
            <p class="pos-label">All metrics</p>
            <h2 id="wcc-more" class="pos-h3 mt-1">Definitions and privacy rules apply</h2>
            <dl class="pos-facts mt-3">
                @foreach ($m as $metric)
                    <div><dt>{{ $metric['label'] }}</dt><dd class="pos-num">{{ $fmt($metric) }}@if ($metric['hint'])<span class="pos-caption block">{{ $metric['hint'] }}</span>@endif</dd></div>
                @endforeach
                <div><dt>Open positions</dt><dd>—<span class="pos-caption block">Requisitions arrive through the RMS integration</span></dd></div>
            </dl>
        </section>
    </div>
</x-filament-panels::page>
