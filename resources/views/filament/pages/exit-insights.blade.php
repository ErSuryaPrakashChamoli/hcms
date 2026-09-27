<x-filament-panels::page>
    @php($a = $this->getAnalytics())
    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section compact><div class="text-sm text-gray-500">Exit interviews</div><div class="text-2xl font-semibold">{{ $a['count'] }}</div><div class="text-xs text-gray-500">{{ $a['employee_count'] }} answered by employees</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Would recommend</div><div class="text-2xl font-semibold">{{ $a['would_recommend'] === null ? '—' : $a['would_recommend'] . '%' }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Would rejoin</div><div class="text-2xl font-semibold">{{ $a['would_rejoin'] === null ? '—' : $a['would_rejoin'] . '%' }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Exits by type</div>@foreach ($this->getExitsByType() as $type => $n)<div class="text-sm flex justify-between"><span>{{ config('peopleos.exit.types.' . $type, $type) }}</span><span class="font-semibold">{{ $n }}</span></div>@endforeach</x-filament::section>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="Reasons for leaving" description="Employee answers are shown separately from HR-inferred reasons.">
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-left py-1">Reason</th><th class="text-right">Employee</th><th class="text-right">HR inferred</th></tr></thead>
                <tbody>
                @foreach ($a['by_reason'] as $key => $counts)
                    @if ($counts['employee'] + $counts['hr_inferred'] > 0)
                        <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1">{{ config('peopleos.exit.interview_reasons.' . $key) }}</td><td class="text-right font-semibold">{{ $counts['employee'] }}</td><td class="text-right text-gray-500">{{ $counts['hr_inferred'] }}</td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </x-filament::section>
        <x-filament::section heading="Average ratings (employee answers, 1–5)">
            @foreach ($a['ratings'] as $dimension => $avg)
                <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800 last:border-0"><span>{{ config('peopleos.exit.interview_dimensions.' . $dimension) }}</span><span class="font-semibold">{{ $avg === null ? '—' : number_format($avg, 2) }}</span></div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>
