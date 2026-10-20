<x-filament-panels::page>
    @php($current = $this->getCurrent())
    <x-filament::section heading="In force today">
        @if ($current)
            <dl class="grid gap-4 text-sm md:grid-cols-4">
                <div><dt class="text-gray-500">Annual CTC</dt><dd class="text-lg font-semibold">{{ number_format($current->ctcAnnual, 2) }} {{ $current->currency }}</dd></div>
                <div><dt class="text-gray-500">Monthly</dt><dd>{{ number_format($current->monthlyCtc(), 2) }}</dd></div>
                <div><dt class="text-gray-500">Variable target (annual)</dt><dd>{{ $current->variableTargetAnnual === null ? '—' : number_format($current->variableTargetAnnual, 2) }}</dd></div>
                <div><dt class="text-gray-500">Since</dt><dd>{{ $current->effectiveFrom->toDateString() }}</dd></div>
            </dl>
            @if ($current->componentValues)
                <p class="mt-3 text-sm text-gray-600">Fixed monthly components: {{ collect($current->componentValues)->map(fn ($v, $k) => $k.' '.number_format($v, 2))->implode(', ') }}</p>
            @endif
        @else
            <p class="text-sm text-gray-500">No approved compensation is in force today.</p>
        @endif
    </x-filament::section>
    <x-filament::section heading="History and scheduled changes" description="Approved compensation only. Payroll calculates your pay from it; see your payslips for amounts paid.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">From</th><th>To</th><th>Annual CTC</th><th>Type</th></tr></thead>
            <tbody>
                @forelse ($this->getHistory() as $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1">{{ $row->effectiveFrom->toDateString() }}@if ($row->effectiveFrom->isFuture()) <span class="text-xs text-primary-600">(scheduled)</span>@endif</td>
                        <td>{{ $row->effectiveTo?->toDateString() ?? 'Open' }}</td>
                        <td>{{ number_format($row->ctcAnnual, 2) }} {{ $row->currency }}</td>
                        <td>{{ config('peopleos.compensation.change_types.'.$row->changeType, $row->changeType) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
