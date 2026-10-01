<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="grid gap-4 md:grid-cols-2">
        @foreach (['By department' => $s['by_department'], 'By location' => $s['by_location'], 'By job family' => $s['by_job_family'], 'By employment type' => $s['by_employment_type']] as $heading => $rows)
            <x-filament::section :heading="$heading">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Group</th><th>Seats</th><th>FTE</th><th>Occupied</th><th>Vacant</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $r['name'] }}</td><td>{{ $r['seats'] }}</td><td>{{ $r['fte'] }}</td><td>{{ $r['occupied_seats'] }}</td><td>{{ $r['vacant_seats'] }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="py-2 text-gray-500">No positions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        @endforeach
    </div>
    <x-filament::section heading="Planned vs actual (active plans)" description="Net planned change from plan lines against the seats in each plan's scope today.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Plan</th><th>Period</th><th>Planned net change</th><th>Approved seats</th><th>Occupied</th><th>Vacant</th></tr></thead>
            <tbody>
                @forelse ($s['plans'] as $p)
                    <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $p['plan'] }} v{{ $p['version'] }}</td><td>{{ $p['period'] }}</td><td>{{ $p['planned_net_change'] }}</td><td>{{ $p['approved_seats'] }}</td><td>{{ $p['occupied_seats'] }}</td><td>{{ $p['vacant_seats'] }}</td></tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-gray-500">No active plans.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
    @if ($s['budgets'] !== null)
        <x-filament::section heading="Budget vs actual" description="Same basis and currency only; actual is finalized payroll employer cost; small populations are suppressed.">
            <ul class="text-sm">
                @forelse ($s['budgets'] as $b)
                    <li>{{ $b['name'] }} — budget {{ number_format($b['budget'], 2) }} {{ $b['currency'] }}, planned {{ $b['planned'] === null ? '—' : number_format($b['planned'], 2) }}, actual {{ $b['actual'] === null ? '—' : number_format($b['actual'], 2) }}{{ $b['note'] ? ' · '.$b['note'] : '' }}</li>
                @empty
                    <li class="text-gray-500">No approved budget covers today.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
    <p class="text-xs text-gray-500">{{ $s['critical_positions'] }} critical role(s) in Succession. Facts only — nobody is ranked, scored or predicted.</p>
</x-filament-panels::page>
