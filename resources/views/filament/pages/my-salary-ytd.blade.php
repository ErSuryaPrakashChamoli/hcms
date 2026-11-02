<x-filament-panels::page>
    {{-- HROne parity MS-10: the year's pay from finalized or paid payroll (PaidPayReader::yearToDate). --}}
    @php($head = $this->header())
    @php($ytd = $this->ytd())
    @php($months = $ytd['months'])
    @php($processed = array_flip($ytd['processed']))
    @php($money = fn ($v) => number_format((float) $v, 2))
    @php($cell = fn (string $month, $v) => isset($processed[$month]) ? number_format((float) $v, 2) : '—')

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="text-sm text-gray-600" data-ytd-header>
            <span class="font-semibold text-gray-950 dark:text-white">{{ $head['name'] }} (#{{ $head['code'] }})</span>
            @if ($head['company']) · {{ $head['company'] }}@endif
            @if ($head['business_unit']) · {{ $head['business_unit'] }}@endif
        </div>
        <div class="flex flex-wrap items-end gap-4">
            <label class="text-sm">
                <span class="text-gray-500">Financial year</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="year">
                        @foreach ($this->years() as $fy)
                            <option value="{{ $fy }}">{{ $fy }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @if ($url = $this->certificatesUrl())
                <x-filament::link :href="$url" size="sm">Tax certificates (Form 16)</x-filament::link>
            @endif
        </div>
    </div>

    <x-filament::tabs>
        @foreach ($this::TABS as $key => $label)
            <x-filament::tabs.item :active="$tab === $key" wire:click="setTab('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @switch($tab)
        @case('reimbursement')
            <x-filament::section heading="Reimbursements paid through payroll" description="Amounts paid in each month's salary. Claimed amounts and bills stay on your reimbursement claims.">
                @if ($ytd['reimbursement'] === [])
                    <p class="text-sm font-medium text-gray-500" data-empty="reimbursement">REIMBURSEMENT NOT PROCESSED</p>
                @else
                    @include('filament.pages.my-salary-ytd.grid', ['groups' => ['Reimbursements' => $ytd['reimbursement']], 'totals' => ['Total paid' => $ytd['totals']['reimbursements']]])
                @endif
            </x-filament::section>
            @break

        @case('statutory')
            @include('filament.pages.my-salary-ytd.statutory')
            @break

        @default
            <x-filament::section heading="Salary">
                @if ($ytd['processed'] === [])
                    <p class="text-sm font-medium text-gray-500" data-empty="salary">SALARY NOT PROCESSED</p>
                @else
                    @include('filament.pages.my-salary-ytd.grid', [
                        'groups' => array_filter(['Earnings' => $ytd['salary']['earning'], 'Deductions' => $ytd['salary']['deduction'], 'Employer contributions' => $ytd['salary']['employer_contribution']]),
                        'totals' => ['Gross' => $ytd['totals']['gross'], 'Total deductions' => $ytd['totals']['deductions'], 'Net pay' => $ytd['totals']['net']],
                    ])
                @endif
            </x-filament::section>
            @if ($ytd['arrears'] !== [])
                <x-filament::section heading="Arrears paid this year" collapsible>
                    <table class="w-full text-sm" data-arrears>
                        <thead><tr class="text-left text-gray-500"><th class="py-1">Paid in</th><th>Arrear</th><th>Note</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                            @foreach ($ytd['arrears'] as $arrear)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="py-1">{{ $months[$arrear['month']] ?? $arrear['month'] }}</td>
                                    <td>{{ $arrear['name'] }}</td>
                                    <td>{{ $arrear['note'] ?? '—' }}</td>
                                    <td class="text-right tabular-nums">{{ $arrear['type'] === 'deduction' ? '−' : '' }}{{ $money($arrear['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif
    @endswitch
</x-filament-panels::page>
