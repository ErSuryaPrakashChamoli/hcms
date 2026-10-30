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
            @if ($paid = $this->paidValues($current))
                <p class="mt-3 text-sm text-gray-600">Fixed monthly components: {{ collect($paid)->map(fn ($v, $k) => $k.' '.number_format($v, 2))->implode(', ') }}</p>
            @endif
        @else
            <p class="text-sm text-gray-500">No approved compensation is in force today.</p>
        @endif
    </x-filament::section>
    {{-- HROne setup parity (PS-3): take-home, wage split, perquisites and insight, as the organisation allows. --}}
    @php($pay = ($this->shows('net_take_home') || $this->shows('wage_split')) ? $this->getLatestPay() : null)
    @if ($pay)
        <x-filament::section heading="Latest pay ({{ $pay['period'] }})">
            <dl class="grid gap-4 text-sm md:grid-cols-4">
                @if ($this->shows('net_take_home'))
                    <div><dt class="text-gray-500">Net take-home</dt><dd class="text-lg font-semibold">{{ number_format($pay['net'], 2) }}</dd></div>
                    <div><dt class="text-gray-500">Gross</dt><dd>{{ number_format($pay['gross'], 2) }}</dd></div>
                @endif
                @if ($this->shows('wage_split'))
                    <div><dt class="text-gray-500">Wages (basic + DA)</dt><dd>{{ number_format($pay['wages'], 2) }} ({{ $pay['wage_share'] }}%)</dd></div>
                    <div><dt class="text-gray-500">Exclusions (allowances)</dt><dd>{{ number_format($pay['exclusions'], 2) }}</dd></div>
                @endif
            </dl>
        </x-filament::section>
    @endif
    @if ($this->shows('perquisites') && ($perqs = $this->getPerquisites())->isNotEmpty())
        <x-filament::section heading="Perquisites this year">
            <ul class="text-sm">
                @foreach ($perqs as $perq)
                    <li>{{ $perq->typeLabel() }} · {{ $perq->description }}: taxable value {{ number_format((float) $perq->taxable_amount, 2) }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
    {{-- HROne setup parity (BP-9): the variable pay schedule, as the organisation allows. --}}
    @if ($this->shows('variable_plan') && ($plans = $this->getVariablePlans()) !== [])
        <x-filament::section heading="Variable pay schedule">
            @foreach ($plans as $plan)
                <p class="text-sm font-medium">{{ $plan['name'] }}</p>
                <table class="mb-3 w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Month</th><th>Target</th><th>Payout</th></tr></thead>
                    <tbody>
                        @foreach ($plan['months'] as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-1">{{ $row['month'] }}</td>
                                <td>{{ number_format($row['target'], 2) }}</td>
                                <td>{{ $row['amount'] === null ? '—' : number_format($row['amount'], 2).' ('.(\App\Domain\Payroll\Models\VariablePayRequest::STATUSES[$row['status']] ?? $row['status']).')' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </x-filament::section>
    @endif
    @php($insight = $this->shows('insight') ? $this->getInsight() : [])
    @if ($this->shows('history'))
    <x-filament::section heading="History and scheduled changes" description="Approved compensation only. Payroll calculates your pay from it; see your payslips for amounts paid.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">From</th><th>To</th><th>Annual CTC</th><th>Type</th></tr></thead>
            <tbody>
                @forelse ($this->getHistory() as $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1">{{ $row->effectiveFrom->toDateString() }}@if ($row->effectiveFrom->isFuture()) <span class="text-xs text-primary-600">(scheduled)</span>@endif</td>
                        <td>{{ $row->effectiveTo?->toDateString() ?? 'Open' }}</td>
                        <td>{{ number_format($row->ctcAnnual, 2) }} {{ $row->currency }}</td>
                        <td>{{ config('peopleos.compensation.change_types.'.$row->changeType, $row->changeType) }}@if (isset($insight[$row->effectiveFrom->toDateString()])) <span class="text-xs text-gray-500">({{ $insight[$row->effectiveFrom->toDateString()] > 0 ? '+' : '' }}{{ $insight[$row->effectiveFrom->toDateString()] }}%)</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-gray-500">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
    @endif
</x-filament-panels::page>
