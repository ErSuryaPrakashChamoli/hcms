<x-filament-panels::page>
    @php($risk = $this->getRisk())
    @php($bands = $risk->countBy('band'))
    <x-filament::section heading="Attrition-risk signals" description="System-generated inference from tenure, pay revisions, ratings, learning, one-on-ones, absences, feedback and grievances. Use it to start conversations, never as grounds for an employment decision (§95).">
        <div class="flex gap-3 text-sm mb-3">
            <x-filament::badge color="danger">{{ $bands['high'] ?? 0 }} high</x-filament::badge>
            <x-filament::badge color="warning">{{ $bands['medium'] ?? 0 }} medium</x-filament::badge>
            <x-filament::badge color="success">{{ $bands['low'] ?? 0 }} low</x-filament::badge>
        </div>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Employee</th><th>Department</th><th class="text-right">Score</th><th>Band</th><th>Signals</th></tr></thead>
            <tbody>
                @foreach ($risk->filter(fn ($r) => $r['score'] > 0) as $r)
                    <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1">{{ $r['name'] }}</td><td>{{ $r['department'] ?? '—' }}</td><td class="text-right">{{ $r['score'] }}</td><td><x-filament::badge :color="$r['band'] === 'high' ? 'danger' : ($r['band'] === 'medium' ? 'warning' : 'success')">{{ $r['band'] }}</x-filament::badge></td><td class="text-gray-600 dark:text-gray-300">{{ implode('; ', $r['signals']) }}</td></tr>
                @endforeach
                @if ($risk->filter(fn ($r) => $r['score'] > 0)->isEmpty())
                    <tr><td colspan="5" class="py-2 text-gray-500">No employee shows any risk signal.</td></tr>
                @endif
            </tbody>
        </table>
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
