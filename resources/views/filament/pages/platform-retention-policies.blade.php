<x-filament-panels::page>
    <x-filament::section heading="Policy in force today" description="Three inputs stay separate: A. a verified legal minimum (or LEGAL_MINIMUM_NOT_CONFIGURED; an unverified one keeps the category from deletion), B. Markedge's company policy, counted from its start event, C. a customer's contractual requirement (per tenant, on the Offboarding page). A category without an approved policy is never deleted.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Retention policy in force per category">
            <table class="w-full text-sm">
                <caption class="sr-only">Retention policy in force per category</caption>
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Category</th><th scope="col" class="pe-3">Owner</th><th scope="col">Policy in force (jurisdiction: company policy · legal minimum)</th></tr></thead>
                <tbody>
                    @foreach ($this->matrix() as $key => $row)
                        <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                            <td class="py-1 pe-3"><span class="font-medium">{{ $key }}</span><br><span class="text-xs text-gray-500">{{ $row['label'] }}</span></td>
                            <td class="pe-3 text-xs">{{ $row['owner'] === 'markedge' ? 'Markedge (never deleted with the tenant)' : ($row['action'] === 'retain' ? 'kept' : $row['owner']) }}</td>
                            <td>
                                @forelse ($row['current'] as $p)
                                    <div>{{ $p->jurisdiction }}: <strong>{{ $p->policy_months }} months</strong> {{ $this->startLabel($p) }} · <span class="[overflow-wrap:anywhere]">{{ $this->legalLabel($p) }}</span> <span class="text-xs text-gray-500">v{{ $p->version }} from {{ $p->effective_from->toDateString() }}</span></div>
                                @empty
                                    <span class="text-warning-700 dark:text-warning-400">Not configured</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Versions">
        @php($versions = $this->versions())
        @if ($versions->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No policy has been proposed. Use Propose policy version.</p>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Retention policy versions">
                <table class="w-full text-sm">
                    <caption class="sr-only">Retention policy versions</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Category / jurisdiction</th><th scope="col" class="pe-3">Version</th><th scope="col" class="pe-3">Policy</th><th scope="col" class="pe-3">Legal minimum</th><th scope="col" class="pe-3">From</th><th scope="col" class="pe-3">Status</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($versions as $p)
                            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                <td class="py-1 pe-3">{{ $p->category }} / {{ $p->jurisdiction }}<br><span class="text-xs text-gray-500">{{ $p->reason }}</span></td>
                                <td class="pe-3">v{{ $p->version }}</td>
                                <td class="pe-3">{{ $p->policy_months }} months {{ $this->startLabel($p) }}<br><span class="text-xs text-gray-500">{{ $p->policy_basis }}</span></td>
                                <td class="pe-3 text-xs [overflow-wrap:anywhere]">{{ $this->legalLabel($p) }}@if ($p->legal_source)<br><span class="text-gray-500">Source: {{ $p->legal_source }}</span>@endif</td>
                                <td class="pe-3">{{ $p->effective_from->toDateString() }}</td>
                                <td class="pe-3">{{ ucfirst($p->status->value) }}</td>
                                <td class="space-x-1 whitespace-nowrap">
                                    @if ($this->pending($p) && ! $this->isMine($p)){{ ($this->approveAction)(['policy' => $p->id]) }} {{ ($this->rejectAction)(['policy' => $p->id]) }}@endif
                                    @if ($this->pending($p) && $this->isMine($p)){{ ($this->withdrawAction)(['policy' => $p->id]) }}<span class="text-xs text-gray-500">another operator approves</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
