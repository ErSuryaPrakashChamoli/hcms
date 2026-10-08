<x-filament-panels::page>
    <div class="flex items-end gap-3">
        <label class="text-sm">As of <input type="date" wire:model.live="on" class="rounded border-gray-300 text-sm dark:bg-gray-800" /></label>
        <span class="text-xs text-gray-500">The position hierarchy describes capacity; employee reporting lines are separate and are never derived from it.</span>
    </div>
    <x-filament::section>
        <ul class="text-sm">
            @forelse ($this->rows() as $row)
                <li class="py-0.5" style="padding-left: {{ $row['depth'] * 1.5 }}rem">
                    <span class="font-medium">{{ $row['code'] }}</span> · {{ $row['title'] }}
                    <x-filament::badge size="sm" color="gray">{{ config('peopleos.workforce.position_statuses.'.$row['status'], $row['status']) }}</x-filament::badge>
                    <span class="text-gray-500">{{ $row['occupied'] }} / {{ $row['seats'] }} seat(s)</span>
                </li>
            @empty
                <li class="text-gray-500">No positions in force on this date.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
