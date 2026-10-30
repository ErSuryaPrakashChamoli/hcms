<x-filament-panels::page>
    @php($cycle = $this->getCycle())
    @if ($cycle)
        {{-- HP-8g: actual against target per level (bell curve), for the appraisals on the board (filters applied). --}}
        @php($comparison = $this->getComparison())
        @php($filters = $this->getFilterLabels())
        <x-filament::section :heading="$cycle->name" :description="'Stage: ' . (config('peopleos.performance.stages.' . $cycle->current_stage) ?? '—') . ' · ' . $comparison['rated'] . ' rated of ' . $comparison['total'] . ($filters ? ' · ' . implode(' · ', $filters) : '')">
            <div class="grid gap-3" style="grid-template-columns: repeat({{ max(1, count($comparison['rows'])) }}, minmax(0, 1fr));">
                @foreach ($comparison['rows'] as $row)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ $row['label'] }}</div>
                        <div class="text-2xl font-semibold">{{ $row['count'] }}</div>
                        <div class="text-xs text-gray-500">{{ round($row['actual']) }}%</div>
                        @if ($row['target'] !== null)
                            <div class="text-xs {{ $row['outside'] ? 'text-danger-600' : 'text-gray-500' }}">Target {{ \App\Domain\Performance\Services\PerformanceDistribution::format($row['target']) }}%{{ $row['gap'] !== null && $row['gap'] != 0 ? ' (' . ($row['gap'] > 0 ? '+' : '') . \App\Domain\Performance\Services\PerformanceDistribution::format($row['gap']) . ')' : '' }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($comparison['has_targets'])
                <div class="mt-4">
                    <x-pos.columns
                        :label="'Rating distribution of ' . $cycle->name . ': target and actual share of employees per level'"
                        :labels="collect($comparison['rows'])->map(fn ($r) => \App\Domain\Performance\Services\PerformanceDistribution::format($r['value']) . ' ' . $r['label'])->all()"
                        :series="[
                            ['name' => 'Target %', 'values' => collect($comparison['rows'])->pluck('target')->map(fn ($v) => (float) $v)->all(), 'color' => 3],
                            ['name' => 'Actual %', 'values' => collect($comparison['rows'])->pluck('actual')->all(), 'color' => 1],
                        ]" />
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    Targets: {{ $comparison['source'] === 'cycle' ? 'this cycle' : 'the default' }} · tolerance {{ \App\Domain\Performance\Services\PerformanceDistribution::format($comparison['tolerance']) }} points ·
                    check on closing: {{ config('peopleos.performance.distribution_checks.' . $comparison['mode']) }}
                    @if ($comparison['outside'])
                        · <span class="text-danger-600">outside the tolerance at {{ collect($comparison['rows'])->where('outside', true)->pluck('label')->implode(', ') }}</span>
                    @endif
                </p>
            @endif
        </x-filament::section>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
