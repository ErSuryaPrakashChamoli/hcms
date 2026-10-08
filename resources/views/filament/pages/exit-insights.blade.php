<x-filament-panels::page>
    @php($a = $this->getAnalytics())
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value">{{ $a['count'] }}</span><span class="pos-figure-label">Exit interviews</span><span class="pos-figure-delta">{{ $a['employee_count'] }} answered by employees</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $a['would_recommend'] === null ? '—' : $a['would_recommend'] . '%' }}</span><span class="pos-figure-label">Would recommend</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $a['would_rejoin'] === null ? '—' : $a['would_rejoin'] . '%' }}</span><span class="pos-figure-label">Would rejoin</span></div>
        <div class="pos-figure"><span class="pos-figure-label">Exits by type</span><dl class="pos-figure-list">@foreach ($this->getExitsByType() as $type => $n)<div><dt>{{ config('peopleos.exit.types.' . $type, $type) }}</dt><dd class="pos-num">{{ $n }}</dd></div>@endforeach</dl></div>
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
