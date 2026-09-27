<x-filament-panels::page>
    @php($m = $this->getMetrics())
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ($m as $metric)
            <x-filament::section compact>
                <div class="text-sm text-gray-500">{{ $metric['label'] }}</div>
                <div class="text-3xl font-semibold">{{ \App\Filament\Pages\DashboardViewer::formatValue($metric['value'], $metric['format']) }}</div>
                @if ($metric['hint'])<div class="text-xs text-gray-500">{{ $metric['hint'] }}</div>@endif
            </x-filament::section>
        @endforeach
        <x-filament::section compact>
            <div class="text-sm text-gray-500">Open positions</div>
            <div class="text-3xl font-semibold">—</div>
            <div class="text-xs text-gray-500">Requisitions arrive through the RMS integration</div>
        </x-filament::section>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->getTrends() as $key => $trend)
            @php($values = array_values($trend['series'])[0] ?? [])
            @php($max = max(1, max($values ?: [1])))
            <x-filament::section :heading="array_key_first($trend['series']) . ' · last 12 months'" compact>
                <div class="flex items-end gap-1 h-32">
                    @foreach ($values as $k => $v)
                        <div class="flex-1 bg-primary-500/70 rounded-t" style="height: {{ round($v / $max * 100) }}%" title="{{ $trend['labels'][$k] }}: {{ $v }}"></div>
                    @endforeach
                </div>
                <div class="flex justify-between text-xs text-gray-500 mt-1"><span>{{ $trend['labels'][0] ?? '' }}</span><span>{{ end($trend['labels']) ?: '' }}</span></div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Critical skills" description="Skills with the fewest advanced or expert holders — succession and training risk.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Skill</th><th class="text-right">Holders</th><th class="text-right">Advanced / expert</th></tr></thead>
            <tbody>
                @forelse ($this->getCriticalSkills() as $s)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1">{{ $s['skill'] }}</td><td class="text-right">{{ $s['holders'] }}</td><td class="text-right {{ $s['experts'] === 0 ? 'text-danger-600 font-semibold' : '' }}">{{ $s['experts'] }}</td></tr>
                @empty
                    <tr><td colspan="3" class="py-2 text-gray-500">No skills recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
