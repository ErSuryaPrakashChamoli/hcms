<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ([
            ['Critical positions', $s['critical_positions']['active'], $s['critical_positions']['review_overdue'].' review(s) overdue'],
            ['With successors', $s['succession']['with_successors'], $s['succession']['coverage_rate'] === null ? '—' : $s['succession']['coverage_rate'].'% coverage'],
            ['With a ready-now successor', $s['succession']['with_ready_now'], $s['succession']['ready_now_rate'] === null ? '—' : $s['succession']['ready_now_rate'].'% of positions'],
            ['With an open plan', $s['succession']['with_open_plan'], 'draft, active or under review'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="text-3xl font-semibold">{{ $value }}</div>
                <div class="text-xs text-gray-500">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="Positions without a ready-now successor" description="A recorded readiness label, current and not expired, is required.">
            <ul class="text-sm">
                @forelse ($s['succession']['without_ready_now'] as $title)
                    <li>{{ $title }}</li>
                @empty
                    <li class="text-gray-500">None.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="Talent pools" :description="'Pools with fewer than '.$s['min_group'].' members show as suppressed.'">
            <ul class="text-sm">
                @forelse ($s['pools'] as $p)
                    <li>{{ $p['pool'] }} — {{ $p['suppressed'] ? 'suppressed' : $p['count'].' members' }}</li>
                @empty
                    <li class="text-gray-500">No pools.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
    <p class="text-xs text-gray-500">These figures are facts recorded by people (designations, plans, readiness labels, recorded exits). PeopleOS does not predict who will leave or who should be promoted.</p>
</x-filament-panels::page>
