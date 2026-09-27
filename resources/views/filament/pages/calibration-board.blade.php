<x-filament-panels::page>
    @php($cycle = $this->getCycle())
    @if ($cycle)
        @php($dist = $this->getDistribution())
        @php($total = max(1, array_sum($dist)))
        <x-filament::section :heading="$cycle->name" :description="'Stage: ' . (config('peopleos.performance.stages.' . $cycle->current_stage) ?? '—') . ' · ' . array_sum($dist) . ' rated of ' . $cycle->appraisals()->count()">
            <div class="grid gap-3" style="grid-template-columns: repeat({{ max(1, count($dist)) }}, minmax(0, 1fr));">
                @foreach ($dist as $label => $count)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="text-2xl font-semibold">{{ $count }}</div>
                        <div class="text-xs text-gray-500">{{ round($count / $total * 100) }}%</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
