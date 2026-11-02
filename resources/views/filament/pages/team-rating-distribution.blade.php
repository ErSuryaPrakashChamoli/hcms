<x-filament-panels::page>
    {{-- HROne parity GO-3 (Team → Analytics → Performance): the manager's own team, counts per level only — no ranking. --}}
    @php($cycle = $this->getCycle())
    @php($team = $this->getDistribution())
    @if (! $cycle || ! $team)
        <x-filament::section heading="No team distribution yet">
            <p class="text-sm text-gray-500">None of the people you manage has an appraisal in a performance cycle you can open.</p>
        </x-filament::section>
    @else
        @php($format = fn ($v) => \App\Domain\Performance\Services\PerformanceDistribution::format($v))
        <x-filament::section :heading="$cycle->name" :description="$team['members'] . ' in your team · ' . $team['counted'] . ' counted · groups under ' . $team['min_group'] . ' are not shown'">
            @if ($team['hidden'] > 0)
                <p class="text-sm text-warning-600">{{ $team['hidden'] }} team {{ $team['hidden'] === 1 ? 'member is' : 'members are' }} not counted: the performance policy keeps their ratings from you.</p>
            @endif
            <div class="grid gap-3 mt-4" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                @foreach (['system' => 'System calculated rating', 'final' => 'Normalised / final rating'] as $curveKey => $curveLabel)
                    @php($curve = $team[$curveKey])
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                        <div class="text-sm font-medium">{{ $curveLabel }}</div>
                        @if ($curve['suppressed'])
                            <p class="mt-2 text-xs text-gray-500">Fewer than {{ $team['min_group'] }} ratings ({{ $curve['rated'] }}): not shown, so no one's rating can be worked out.</p>
                        @else
                            <div class="text-xs text-gray-500">{{ $curve['rated'] }} rated</div>
                            <div class="grid gap-2 mt-2" style="grid-template-columns: repeat({{ max(1, count($curve['rows'])) }}, minmax(0, 1fr));">
                                @foreach ($curve['rows'] as $row)
                                    <div class="text-center">
                                        <div class="text-xs text-gray-500">{{ $row['label'] }}</div>
                                        <div class="text-2xl font-semibold">{{ $row['count'] }}</div>
                                        <div class="text-xs text-gray-500">{{ round($row['actual']) }}%@if ($row['target'] !== null) · target {{ $format($row['target']) }}%@endif</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            @if (! $team['system']['suppressed'] && ! $team['final']['suppressed'])
                <div class="mt-4">
                    <x-pos.columns
                        :label="'Rating distribution of your team in ' . $cycle->name . ': system calculated and normalised or final share per level'"
                        :labels="collect($team['final']['rows'])->map(fn ($r) => $format($r['value']) . ' ' . $r['label'])->all()"
                        :series="[
                            ['name' => 'System %', 'values' => collect($team['system']['rows'])->pluck('actual')->map(fn ($v) => (float) $v)->all(), 'color' => 3],
                            ['name' => 'Final %', 'values' => collect($team['final']['rows'])->pluck('actual')->map(fn ($v) => (float) $v)->all(), 'color' => 1],
                        ]" />
                </div>
            @endif
            @if ($team['names'] !== [])
                <p class="mt-4 text-xs text-gray-500">Counted (alphabetical): {{ collect($team['names'])->map(fn ($n) => trim(($n['name'] ?? '') . ' (' . ($n['code'] ?? '') . ')'))->implode(', ') }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
