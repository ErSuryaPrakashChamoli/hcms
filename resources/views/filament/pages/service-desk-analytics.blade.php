<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">From <input type="date" wire:model.live="from" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-gray-900"></label>
        <label class="text-sm">To <input type="date" wire:model.live="to" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-gray-900"></label>
    </div>
    @php($s = $this->getSummary())
    @if ($s['suppressed'])
        <x-filament::section><p class="text-sm text-gray-500">{{ $s['note'] }}</p></x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-4">
            @foreach (['received' => 'Received', 'open' => 'Open now', 'backlog_overdue' => 'Overdue backlog', 'resolved' => 'Resolved', 'average_first_response_hours' => 'Avg first response (h)', 'average_resolution_hours' => 'Avg resolution (h)', 'sla_compliance_percent' => 'SLA compliance (%)', 'breached' => 'Breached', 'confidential_cases' => 'Confidential cases'] as $key => $label)
                <x-filament::section compact>
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                    <div class="text-xl font-semibold">{{ $s[$key] ?? '—' }}</div>
                </x-filament::section>
            @endforeach
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['by_service' => 'By service', 'by_status' => 'By status', 'by_priority' => 'By priority', 'by_team' => 'By HR team', 'by_organisation' => 'By department'] as $key => $label)
                <x-filament::section :heading="$label" compact>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse ($s[$key] as $row)
                                <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $row['group'] }}</td><td class="text-right {{ $row['suppressed'] ? 'text-gray-400' : 'font-semibold' }}">{{ $row['requests'] }}</td></tr>
                            @empty
                                <tr><td class="py-1 text-gray-500">No requests.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-filament::section>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">Groups with fewer than {{ $s['min_group'] }} people are suppressed; when only one group would be hidden, the next smallest is hidden too.</p>
    @endif
</x-filament-panels::page>
