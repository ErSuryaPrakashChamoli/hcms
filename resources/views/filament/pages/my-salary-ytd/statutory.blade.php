{{-- HROne parity MS-10: statutory year to date — what payroll recorded on the lines; ceilings only from verified rules. --}}
@php
    $st = $ytd['statutory'];
    $pf = $this->pfOption();
    $ceiling = fn ($c) => $c === null ? 'No verified rule' : number_format($c['value'], 2).' ('.$c['rule'].')';
    $table = function (array $scheme, array $columns) use ($months) {
        return ['rows' => collect($scheme['months'])->map(fn ($m, $key) => ['month' => $months[$key] ?? $key] + collect($columns)->mapWithKeys(fn ($label, $field) => [$field => $m[$field] ?? null])->all())->values()->all(), 'columns' => $columns];
    };
@endphp

@foreach ([
    'pf' => ['Provident fund', ['wages' => 'PF wages', 'base' => 'PF calculated on', 'employee' => 'Employee PF', 'epf' => 'Employer EPF', 'eps' => 'Employer EPS'], true],
    'vpf' => ['Voluntary provident fund', ['wages' => 'PF wages', 'amount' => 'VPF deducted'], false],
    'esi' => ['ESI', ['eligibility_wages' => 'Wages for the ceiling', 'wages' => 'ESI wages', 'employee' => 'Employee ESI', 'employer' => 'Employer ESI'], true],
    'pt' => ['Professional tax', ['wages' => 'PT calculated on', 'state' => 'State', 'amount' => 'Professional tax'], false],
    'lwf' => ['Labour welfare fund', ['state' => 'State', 'employee' => 'Employee LWF', 'employer' => 'Employer LWF'], false],
] as $scheme => [$heading, $columns, $hasCeiling])
    <x-filament::section :heading="$heading" collapsible data-scheme="{{ $scheme }}">
        @if ($scheme === 'pf' || $scheme === 'vpf')
            <dl class="mb-3 grid gap-4 text-sm md:grid-cols-3">
                @if ($scheme === 'pf')
                    <div><dt class="text-gray-500">PF wage ceiling</dt><dd data-ceiling>{{ $ceiling($st['pf']['ceiling']) }}</dd></div>
                    <div><dt class="text-gray-500">Your PF basis</dt><dd>{{ $pf && $pf['wage_basis'] ? (\App\Domain\Compliance\Models\EmployeePfOption::WAGE_BASES[$pf['wage_basis']] ?? $pf['wage_basis']) : 'As your establishment applies it' }}</dd></div>
                @else
                    <div><dt class="text-gray-500">Voluntary PF chosen</dt><dd>{{ $pf && $pf['vpf_mode'] ? ($pf['vpf_mode'] === 'percent' ? $pf['vpf_value'].'% of PF wages' : $money($pf['vpf_value']).' a month') : 'None' }}</dd></div>
                @endif
            </dl>
        @elseif ($hasCeiling)
            <dl class="mb-3 grid gap-4 text-sm md:grid-cols-3">
                <div><dt class="text-gray-500">ESI wage ceiling</dt><dd data-ceiling>{{ $ceiling($st['esi']['ceiling']) }}</dd></div>
            </dl>
        @endif
        @if ($st[$scheme]['months'] === [])
            <p class="text-sm text-gray-500">Not deducted this year.</p>
        @else
            @php($t = $table($st[$scheme], $columns))
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1 pe-3">Month</th>@foreach ($columns as $label)<th class="px-2 text-right">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($t['rows'] as $r)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                <td class="py-1 pe-3">{{ $r['month'] }}</td>
                                @foreach (array_keys($columns) as $field)
                                    <td class="whitespace-nowrap px-2 text-right tabular-nums">{{ $r[$field] === null ? '—' : (is_numeric($r[$field]) ? $money($r[$field]) : $r[$field]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="border-t border-gray-200 font-semibold dark:border-gray-700">
                            <td class="py-1 pe-3">Total</td>
                            @foreach (array_keys($columns) as $field)
                                <td class="whitespace-nowrap px-2 text-right tabular-nums">{{ isset($st[$scheme]['totals'][$field]) ? $money($st[$scheme]['totals'][$field]) : '' }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
@endforeach

<x-filament::section heading="Overtime" collapsible data-scheme="overtime">
    @if ($ytd['overtime']['months'] === [])
        <p class="text-sm text-gray-500">No overtime paid this year.</p>
    @else
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Month</th><th class="text-right">Approved overtime (hours)</th><th class="text-right">Overtime paid</th></tr></thead>
            <tbody>
                @foreach ($ytd['overtime']['months'] as $key => $m)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1">{{ $months[$key] ?? $key }}</td>
                        <td class="text-right tabular-nums">{{ number_format($m['minutes'] / 60, 2) }}</td>
                        <td class="text-right tabular-nums">{{ $money($m['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="border-t border-gray-200 font-semibold dark:border-gray-700"><td class="py-1">Total</td><td class="text-right tabular-nums">{{ number_format($ytd['overtime']['minutes'] / 60, 2) }}</td><td class="text-right tabular-nums">{{ $money($ytd['overtime']['amount']) }}</td></tr>
            </tbody>
        </table>
    @endif
</x-filament::section>

<x-filament::section heading="Bonus" collapsible data-scheme="bonus">
    <dl class="mb-3 grid gap-4 text-sm md:grid-cols-3">
        <div><dt class="text-gray-500">Statutory bonus rates</dt><dd data-bonus-rule>{{ $ytd['bonus']['rule'] === null ? 'No verified rule' : round($ytd['bonus']['rule']['minimum_rate'] * 100, 2).'% to '.($ytd['bonus']['rule']['maximum_rate'] === null ? '—' : round($ytd['bonus']['rule']['maximum_rate'] * 100, 2).'%').' ('.$ytd['bonus']['rule']['rule'].')' }}</dd></div>
        <div><dt class="text-gray-500">Bonus paid this year</dt><dd>{{ $money($ytd['bonus']['total']) }}</dd></div>
    </dl>
</x-filament::section>
