<x-filament-panels::page>
    <x-filament::section heading="Attrition and capacity" description="Aggregate facts only. PeopleOS does not score, rank or predict whether any individual will leave, be promoted or be let go.">
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($this->getFacts() as $fact)
                <div>
                    <div class="text-sm text-gray-500">{{ $fact['label'] }}</div>
                    <div class="text-xl font-semibold">{{ $fact['value'] === null ? '—' : $fact['value'].($fact['format'] === 'percent' ? '%' : '') }}</div>
                    @if ($fact['hint'])<div class="text-xs text-gray-400">{{ $fact['hint'] }}</div>@endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
    <x-filament::section heading="Critical skills" description="Fewest advanced / expert holders first.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Skill</th><th class="text-right">Holders</th><th class="text-right">Advanced / expert</th></tr></thead>
            <tbody>
                @forelse ($this->getSkills() as $s)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1">{{ $s['skill'] }}</td><td class="text-right">{{ $s['holders'] }}</td><td class="text-right {{ $s['experts'] === 0 ? 'text-danger-600 font-semibold' : '' }}">{{ $s['experts'] }}</td></tr>
                @empty
                    <tr><td colspan="3" class="py-2 text-gray-500">No skills recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
