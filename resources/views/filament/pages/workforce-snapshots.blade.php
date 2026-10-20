<x-filament-panels::page>
    @php($h = $this->headcount())
    <label class="text-sm">As of <input type="date" wire:model.live="on" class="rounded border-gray-300 text-sm dark:bg-gray-800" /></label>
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ([['Positions in force', $h['positions']], ['Approved seats', $h['approved_seats'].' ('.$h['approved_fte'].' FTE)'], ['Occupied seats', $h['occupied_seats'].' ('.$h['occupied_fte'].' FTE)'], ['Vacant seats', $h['vacant_seats']], ['Frozen seats', $h['frozen_seats']], ['On hold seats', $h['on_hold_seats']], ['Planned (approved, not open)', $h['planned_seats']], ['Employees', $h['employees']]] as [$label, $value])
            <x-filament::section><div class="text-sm text-gray-500">{{ $label }}</div><div class="text-2xl font-semibold">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach (['By department' => $this->byDepartment(), 'By location' => $this->byLocation()] as $heading => $rows)
            <x-filament::section :heading="$heading">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Unit</th><th>Positions</th><th>Seats</th><th>FTE</th><th>Occupied</th><th>Vacant</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $r)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $r['name'] }}</td><td>{{ $r['positions'] }}</td><td>{{ $r['seats'] }}</td><td>{{ $r['fte'] }}</td><td>{{ $r['occupied_seats'] }}</td><td>{{ $r['vacant_seats'] }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="py-2 text-gray-500">No positions in force on this date.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
