<x-filament-panels::page>
    @php
        $pulse = $this->pulse;
        $m = $pulse['metrics'];
        $mv = $pulse['movement'];
        $fmt = fn ($metric) => ($metric['restricted'] ?? false) || $metric['value'] === null ? '—' : \App\Filament\Pages\DashboardViewer::formatValue($metric['value'], $metric['format']);
        // Change is coloured by meaning only where it has one (an internal move is neither good nor bad).
        $delta = function (int|float $now, int|float $before, ?bool $risingIsBad) {
            $d = $now - $before;
            if ($d == 0) {
                return ['same as last month', null];
            }

            return [($d > 0 ? '+' : '−').rtrim(rtrim(number_format(abs($d), 1), '0'), '.').' vs last month', $risingIsBad === null ? null : (($d > 0) === $risingIsBad ? 'bad' : 'good')];
        };
        $drill = fn (string $key) => "\$dispatch('pos-drawer-open', { type: 'pulse', id: '{$key}' })";
        $headcountSeries = array_values($pulse['size']['series']['series'] ?? [])[0] ?? [];
    @endphp

    <div class="pos-ws">
        <div class="pos-ws-cols">
            <div class="pos-ws-main">
                {{-- Movement: the story of the month --}}
                <x-pos.section title="Movement" sub="This month to date against last month. Select a figure to see the people behind it.">
                    <div class="pos-panel pos-panel-pad grid gap-4">
                        @if ($mv['rate'] !== null)
                            <p class="pos-section-title"><span class="pos-num">{{ $mv['rate'] }}%</span> of headcount moved this month
                                @if ($mv['rate_last'] !== null)<span class="pos-meta">· {{ $mv['rate_last'] }}% last month</span>@endif</p>
                        @endif
                        <div class="pos-figures">
                            @foreach (['moves' => ['Internal moves', null], 'promotions' => ['Promotions', false], 'joiners' => ['Joiners', false], 'exits' => ['Exits', true]] as $key => [$label, $bad])
                                @php([$text, $meaning] = $delta($mv['now'][$key], $mv['last'][$key], $bad))
                                <x-pos.figure :value="$mv['now'][$key]" :label="$label" :delta="$text" :meaning="$meaning" :drill="$drill($key)" />
                            @endforeach
                            @if ($mv['critical'] !== null)
                                <x-pos.figure :value="$mv['critical']" label="Critical positions affected" :delta="$mv['critical'] > 0 ? 'incumbent leaving or seat empty' : 'none at risk'" :meaning="$mv['critical'] > 0 ? 'bad' : 'good'" />
                            @endif
                        </div>
                        <p class="pos-meta">Movement rate = joiners, exits, promotions and internal moves ÷ headcount. Moves and promotions are position changes effective in the month.</p>
                    </div>
                </x-pos.section>

                {{-- Size --}}
                <x-pos.section title="Size">
                    <div class="pos-panel pos-panel-pad grid gap-4">
                        @php([$hcText, $hcMeaning] = $delta($pulse['size']['headcount'], $pulse['size']['last'], null))
                        <div class="pos-figures">
                            <x-pos.figure :value="number_format($pulse['size']['headcount'])" label="Headcount today" :delta="$hcText" />
                            @if (isset($m['avg_tenure_months']))<x-pos.figure :value="$fmt($m['avg_tenure_months'])" label="Average tenure, months" />@endif
                            @if (isset($m['attrition_rate']))<x-pos.figure :value="$fmt($m['attrition_rate'])" label="Attrition, 12 months" />@endif
                        </div>
                        @if (count($headcountSeries) > 1)
                            <x-pos.sparkline zero :values="$headcountSeries" :labels="$pulse['size']['series']['labels']" label="Headcount, last 12 months" />
                        @endif
                    </div>
                </x-pos.section>

                {{-- Attendance, performance and capability --}}
                <div class="pos-ws-cols pos-pulse-pair">
                    <x-pos.section title="Attendance">
                        <div class="pos-panel pos-panel-pad pos-figures">
                            <x-pos.figure :value="$fmt($m['absenteeism_rate'])" label="Absenteeism, last 30 days" :meaning="(($m['absenteeism_rate']['value'] ?? 0) >= 5) ? 'bad' : null" :delta="(($m['absenteeism_rate']['value'] ?? 0) >= 5) ? 'above 5%' : null" />
                            <x-pos.figure :value="$fmt($m['on_leave_today'])" label="On leave today" :drill="$drill('on_leave')" />
                        </div>
                    </x-pos.section>
                    <x-pos.section title="Performance and capability">
                        <div class="pos-panel pos-panel-pad grid gap-4">
                            <div class="pos-figures">
                                <x-pos.figure :value="$fmt($m['high_performers'])" label="High performers" />
                                <x-pos.figure :value="$fmt($m['learning_completion'])" label="Mandatory learning done" :meaning="(($m['learning_completion']['value'] ?? 100) < 80) ? 'bad' : null" />
                            </div>
                            @if (($m['high_performers']['restricted'] ?? false) || ($m['learning_completion']['restricted'] ?? false))
                                <p class="pos-meta">Some figures need the matching analytics permission and show “—”.</p>
                            @endif
                        </div>
                    </x-pos.section>
                </div>

                <x-pos.section title="Critical skills" sub="Skills with the fewest advanced or expert holders.">
                    @if ($pulse['critical_skills'] === [])
                        <x-pos.state variant="empty" size="inline" title="No skills recorded yet." />
                    @else
                        <div class="pos-panel pos-stream">
                            @foreach (array_slice($pulse['critical_skills'], 0, 6) as $skill)
                                <div class="pos-stream-row" data-tone="{{ ($skill['experts'] ?? 0) === 0 ? 'danger' : 'info' }}">
                                    <span class="pos-stream-mark" aria-hidden="true"></span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $skill['name'] ?? $skill['skill'] ?? 'Skill' }}</span><span class="pos-stream-meta">{{ $skill['holders'] ?? 0 }} holders · {{ $skill['experts'] ?? 0 }} expert</span></span>
                                    @if (($skill['experts'] ?? 0) === 0)<x-pos.status tone="danger" label="No expert" />@else<span></span>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-pos.section>

                {{-- Exceptions --}}
                <x-pos.section title="Exceptions" :count="count($this->getRisks()) ?: null">
                    @if ($this->getRisks() === [])
                        <x-pos.state variant="caught-up" size="inline" title="No workforce risk crosses its threshold." why="Attrition, absenteeism, mandatory learning, skills without an expert and open grievances are watched here." />
                    @else
                        <div class="pos-panel pos-stream">
                            @foreach ($this->getRisks() as $r)
                                <div class="pos-stream-row" data-tone="{{ $r['severity'] }}">
                                    <span class="pos-figure-value w-16 text-center">{{ $r['value'] }}</span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $r['title'] }}</span><span class="pos-stream-meta">{{ $r['why'] }}</span></span>
                                    @if ($r['url'])<a href="{{ $r['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open</a>@else<span></span>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-pos.section>
            </div>

            <aside class="pos-ws-side" aria-label="Decisions and planning">
                <x-pos.section title="Requires a decision">
                    <div class="pos-panel pos-stream">
                        @forelse ($this->getDecisions() as $d)
                            <a @if ($d['url']) href="{{ $d['url'] }}" wire:navigate @endif class="pos-stream-row" data-tone="{{ $d['count'] > 0 ? 'warning' : 'neutral' }}">
                                <span class="pos-figure-value w-10 text-center">{{ $d['count'] }}</span>
                                <span class="pos-stream-body"><span class="pos-stream-title">{{ $d['title'] }}</span></span>
                                <span aria-hidden="true" class="pos-muted">→</span>
                            </a>
                        @empty
                            <x-pos.state variant="caught-up" size="inline" title="Nothing needs your decision." />
                        @endforelse
                    </div>
                </x-pos.section>
                @if ($pulse['planning']['open_positions'] !== null || $pulse['planning']['plans_in_review'] !== null)
                    <x-pos.section title="Planning">
                        <div class="pos-panel pos-panel-pad pos-figures">
                            @if ($pulse['planning']['open_positions'] !== null)<x-pos.figure :value="$pulse['planning']['open_positions']" label="Open positions" />@endif
                            @if ($pulse['planning']['plans_in_review'] !== null)<x-pos.figure :value="$pulse['planning']['plans_in_review']" label="Plans awaiting review" />@endif
                        </div>
                    </x-pos.section>
                @endif
                @if (isset($m['people_cost']) && ! ($m['people_cost']['restricted'] ?? false) && $m['people_cost']['value'] !== null)
                    <x-pos.section title="People cost">
                        <div class="pos-panel pos-panel-pad"><x-pos.figure :value="$fmt($m['people_cost'])" label="{{ $m['people_cost']['hint'] ?? 'Last finalised payroll' }}" /></div>
                    </x-pos.section>
                @endif
                <x-pos.section title="Go deeper">
                    <div class="pos-panel pos-stream">
                        @foreach (array_filter([
                            \App\Filament\Pages\ChangeIntelligencePage::canAccess() ? ['Change intelligence', 'Every recorded change, by person and date', \App\Filament\Pages\ChangeIntelligencePage::getUrl()] : null,
                            \App\Filament\Pages\WorkforceIntelligence::canAccess() ? ['Workforce intelligence', 'Signals and their reasons', \App\Filament\Pages\WorkforceIntelligence::getUrl()] : null,
                            \App\Filament\Pages\PeopleAnalyticsPage::canAccess() ? ['People analytics', 'Breakdowns with privacy thresholds', \App\Filament\Pages\PeopleAnalyticsPage::getUrl()] : null,
                        ]) as [$label, $hint, $url])
                            <a href="{{ $url }}" wire:navigate class="pos-stream-row"><span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-presentation-chart-line" class="size-4" /></span><span class="pos-stream-body"><span class="pos-stream-title">{{ $label }}</span><span class="pos-stream-meta">{{ $hint }}</span></span><span aria-hidden="true" class="pos-muted">→</span></a>
                        @endforeach
                    </div>
                </x-pos.section>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
