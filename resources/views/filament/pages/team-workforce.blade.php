<x-filament-panels::page>
    @php($rows = $this->positions())
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ([['Positions', count($rows)], ['Seats', collect($rows)->sum('seats')], ['Occupied', collect($rows)->sum('occupied')], ['Open or planned', collect($rows)->whereIn('status', ['open', 'planned', 'approved'])->count()]] as [$label, $value])
            <x-filament::section><div class="text-sm text-gray-500">{{ $label }}</div><div class="text-2xl font-semibold">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>
    <x-filament::section heading="Positions in my scope">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Position</th><th>Status</th><th>Seats</th><th>Occupied</th><th>Vacant</th></tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $r['code'] }} · {{ $r['title'] }}</td><td>{{ config('peopleos.workforce.position_statuses.'.$r['status'], $r['status']) }}</td><td>{{ $r['seats'] }}</td><td>{{ $r['occupied'] }}</td><td>{{ $r['status'] === 'open' ? max(0, $r['seats'] - $r['occupied']) : '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
