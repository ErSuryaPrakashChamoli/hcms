<x-filament-panels::page>
    @php($h = $this->getHeadcount())
    @php($q = $this->getQueues())
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ([
            ['Approved seats', $h['approved_seats'], $h['approved_fte'].' FTE · '.$h['positions'].' positions'],
            ['Occupied seats', $h['occupied_seats'], $h['occupied_fte'].' FTE'],
            ['Vacant seats', $h['vacant_seats'], $h['vacant_fte'].' FTE in open positions'],
            ['Employees', $h['employees'], 'people — not seats'],
            ['Planned / approved, not open', $h['planned_seats'], $h['future_positions'].' position(s) start later'],
            ['Frozen seats', $h['frozen_seats'], $h['on_hold_seats'].' on hold'],
            ['Waiting for a decision', $q['proposed_positions'] + $q['change_requests'] + $q['plans_waiting'], $q['proposed_positions'].' positions · '.$q['change_requests'].' changes · '.$q['plans_waiting'].' plans'],
            ['Active plans', $q['active_plans'], $q['critical_positions'].' critical role(s) in Succession'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="text-3xl font-semibold">{{ $value }}</div>
                <div class="text-xs text-gray-500">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>
    <p class="text-xs text-gray-500">Positions, seats / FTE and employees are different measures and are never interchanged. Vacancies are unfilled capacity, not requisitions.</p>
</x-filament-panels::page>
