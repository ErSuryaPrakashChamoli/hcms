<x-filament-panels::page>
    @php($services = $this->getServices())
    @if ($services->isNotEmpty())
        <x-filament::section heading="Raise for a team member" compact>
            <div class="flex flex-wrap gap-2">
                @foreach ($services as $version)
                    {{ ($this->requestServiceAction)(['service' => $version->service_definition_id, 'for' => 'report'])->label($version->service->name) }}
                @endforeach
            </div>
        </x-filament::section>
    @endif
    <x-filament::section description="Status only. Sensitive and confidential requests, request details and HR conversations are never shown here.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Number</th><th>Team member</th><th>Service</th><th>Status</th><th>Raised</th><th>Resolved</th></tr></thead>
                <tbody>
                    @forelse ($this->getRequests() as $ticket)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1">{{ $ticket->number }}</td>
                            <td>{{ $ticket->employee?->person?->full_name }}</td>
                            <td>{{ $ticket->serviceName() }}</td>
                            <td><x-filament::badge :color="\App\Filament\Resources\Tickets\TicketResource::statusColor($ticket->status)">{{ $this->statusLabel($ticket->status) }}</x-filament::badge></td>
                            <td>{{ $ticket->created_at->toDateString() }}</td>
                            <td>{{ $ticket->resolved_at?->toDateString() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-500">No HR requests from your team that you may see.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
