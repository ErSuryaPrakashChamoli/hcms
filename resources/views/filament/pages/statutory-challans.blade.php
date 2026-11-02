<x-filament-panels::page>
    {{-- HROne parity PO-13: the challan grid (HROne "Generate challan"): one financial year × month grid per statute. --}}
    @php($statute = $this->statute)
    @php($entityLevel = $statute === 'tds')
    @php($coverage = $this->coverage())
    @php($stateGap = in_array($statute, ['pt', 'lwf'], true) && $coverage['state_missing'] > 0)

    @if ($coverage['locations'] !== [] || $stateGap)
        <x-filament::section heading="Statutory coverage" description="Display only. People at a location with no establishment are reported under the company's principal establishment, or not at all. Map each location to its establishment under Organisation → Locations." data-coverage>
            @if ($coverage['locations'] !== [])
                <p class="text-sm font-medium text-warning-600" data-coverage-locations="{{ count($coverage['locations']) }}">{{ count($coverage['locations']) }} location(s) not mapped to an establishment (statutory unit)</p>
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Locations not mapped to an establishment">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1 pe-3">Location</th>
                                <th class="py-1 pe-3">State</th>
                                <th class="py-1 pe-3">Company</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($coverage['locations'] as $location)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="py-1 pe-3">{{ $location['name'] }}</td>
                                    <td class="py-1 pe-3">{{ config('peopleos.compliance.states.'.$location['state'], $location['state'] ?? '—') }}</td>
                                    <td class="py-1 pe-3">{{ $location['company'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($stateGap)
                <p class="mt-2 text-sm font-medium text-warning-600" data-coverage-state="{{ $coverage['state_missing'] }}">{{ $coverage['state_missing'] }} finalized salary(ies) of {{ $this->year }} had no state for professional tax (their establishment has no state).</p>
            @endif
        </x-filament::section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-filament::tabs>
            @foreach ($this::TABS as $key => $label)
                <x-filament::tabs.item :active="$statute === $key" wire:click="setStatute('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <label class="flex items-center gap-2 text-sm">
            <span class="text-gray-500">Financial year</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="year" aria-label="Financial year">
                    @foreach ($this->yearOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    @php($grid = $this->grid())
    @php($months = $this->months())
    @if ($grid === [])
        <x-filament::section>
            <p class="text-sm font-medium text-gray-500" data-empty-grid>No {{ strtolower($this::TABS[$statute]) }} challan: there is no {{ $entityLevel ? 'legal entity' : 'active establishment' }} in your access.</p>
        </x-filament::section>
    @else
        @php($gridHeading = $this::TABS[$statute].' · '.$this->year)
        @php($gridDescription = $entityLevel ? 'Per legal entity (TAN). The month is the month salaries were paid; the return is the quarter’s Form No. 138 statement. Below each cell: TDS deducted by finalized payroll / deposited through challans.' : 'Per establishment and wage month. Choose a month to see its return and challans and to record the payment.')
        <x-filament::section :heading="$gridHeading" :description="$gridDescription">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Challans by month">
                <table class="w-full text-sm" data-challan-grid="{{ $statute }}">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-1 pe-3">{{ $entityLevel ? 'Legal entity' : 'Establishment' }}</th>
                            @foreach ($months as $month)
                                <th class="px-1 py-1 text-center whitespace-nowrap">{{ $month->format('M') }}<br><span class="text-xs">{{ $month->format('Y') }}</span></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($grid as $row)
                            @php($unitId = $row['unit']->getKey())
                            <tr class="border-t border-gray-200 dark:border-gray-700" data-unit="{{ $unitId }}">
                                <td class="py-1 pe-3 align-top">
                                    <span class="font-medium">{{ $row['label'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $row['registration'] ?? 'No registration in force' }}</span>
                                </td>
                                @foreach ($row['cells'] as $key => $cell)
                                    <td class="px-1 py-1 text-center align-top">
                                        <button type="button" class="inline-flex flex-col items-center gap-1 cursor-pointer" wire:click="selectCell({{ $unitId }}, '{{ $key }}')"
                                            data-cell="{{ $unitId }}|{{ $key }}" data-state="{{ $cell['state'] }}" aria-label="{{ $row['label'] }} {{ $cell['month']->format('F Y') }}: {{ $this->stateLabel($cell['state']) }}">
                                            <x-filament::badge :color="$this->stateColor($cell['state'])" size="sm">{{ $this->stateLabel($cell['state']) }}</x-filament::badge>
                                            @if ($cell['payment_missing'])
                                                <span class="text-xs text-danger-600">payment not recorded</span>
                                            @endif
                                            @if ($entityLevel && $cell['tds'] !== null && $cell['tds']['deducted'] > 0)
                                                <span class="text-xs text-gray-500 tabular-nums whitespace-nowrap" data-tds="{{ $key }}">{{ number_format($cell['tds']['deducted'], 0) }} / {{ number_format($cell['tds']['deposited'], 0) }}</span>
                                            @endif
                                        </button>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Not generated → generated (from finalized payroll) → approved (by someone other than the generator) → paid (a challan recorded here) → filed (the portal reference recorded on the return).</p>
        </x-filament::section>
    @endif

    @php($selected = $this->selected())
    @if ($selected !== null)
        @php($cell = $selected['cell'])
        @php($unit = $selected['unit'])
        @php($args = ['statute' => $statute, 'unit' => $unit->getKey(), 'month' => $selected['month']->format('Y-m')])
        @php($return = $cell['return'])
        @php($panelHeading = $this->service()->unitLabel($unit).' · '.$selected['month']->format('F Y'))
        <x-filament::section :heading="$panelHeading" data-cell-panel="{{ $unit->getKey() }}|{{ $selected['month']->format('Y-m') }}">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge :color="$this->stateColor($cell['state'])">{{ $this->stateLabel($cell['state']) }}</x-filament::badge>
                <span class="text-sm" data-return-line>
                    {{ $return !== null ? $return->label().' — '.config('peopleos.compliance.return_statuses.'.$return->status, $return->status) : 'No return generated for this '.($entityLevel ? 'quarter' : 'month') }}
                </span>
                @php($returnUrl = $this->returnUrl($return))
                @if ($returnUrl)
                    <a class="text-sm text-primary-600 underline" href="{{ $returnUrl }}">Open return</a>
                @endif
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                {{ ($this->recordPaymentAction)($args) }}
                {{ ($this->generateReturnAction)($args) }}
                @if ($return !== null)
                    {{ ($this->downloadReturnFileAction)(['return' => $return->getKey()]) }}
                @endif
                {{ ($this->downloadWorkingSheetAction)($args) }}
            </div>

            @if ($entityLevel && $cell['tds'] !== null)
                <div class="mt-2 grid gap-3 text-sm md:grid-cols-4" data-tds-summary>
                    <div><p class="text-gray-500">Employees with TDS</p><p class="font-semibold tabular-nums">{{ $cell['tds']['employees'] }}</p></div>
                    <div><p class="text-gray-500">TDS deducted (finalized payroll)</p><p class="font-semibold tabular-nums">{{ number_format($cell['tds']['deducted'], 2) }}</p></div>
                    <div><p class="text-gray-500">TDS deposited (challans)</p><p class="font-semibold tabular-nums">{{ number_format($cell['tds']['deposited'], 2) }} <span class="text-xs text-gray-500">({{ $cell['tds']['employees_deposited'] }} employee(s) with challans)</span></p></div>
                    <div><p class="text-gray-500">TDS to be deposited</p><p class="font-semibold tabular-nums">{{ number_format($cell['tds']['to_deposit'], 2) }}</p></div>
                </div>
            @endif

            @php($challans = $cell['all_challans'])
            <div class="mt-2 overflow-x-auto" tabindex="0" role="region" aria-label="Challans recorded">
                @if ($challans->isEmpty())
                    <p class="text-sm text-gray-500" data-no-challans>No challan recorded for this month.</p>
                @else
                    <table class="w-full text-sm" data-challans>
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1 pe-3">Challan</th>
                                <th class="py-1 pe-3">Paid on</th>
                                <th class="py-1 pe-3">Mode</th>
                                <th class="py-1 pe-3 text-right">Amount</th>
                                <th class="py-1 pe-3 text-right">Interest</th>
                                <th class="py-1 pe-3 text-right">Penalty</th>
                                <th class="py-1 pe-3 text-right">Total</th>
                                <th class="py-1 pe-3">Status</th>
                                <th class="py-1 pe-3">Recorded by</th>
                                <th class="py-1 pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($challans as $challan)
                                @php($receipt = $this->receiptUrl($challan))
                                <tr class="border-t border-gray-200 dark:border-gray-700" data-challan="{{ $challan->id }}">
                                    <td class="py-1 pe-3">{{ $challan->challan_number }}@if (filled($challan->alias)) <span class="text-xs text-gray-500">· {{ $challan->alias }}</span>@endif @if ($challan->bsr_code) <span class="text-xs text-gray-500">· BSR {{ $challan->bsr_code }}</span>@endif</td>
                                    <td class="py-1 pe-3 whitespace-nowrap">{{ $challan->paid_on->format('d M Y') }}</td>
                                    <td class="py-1 pe-3">{{ config('peopleos.compliance.challan_payment_modes.'.$challan->payment_mode, $challan->payment_mode) }}</td>
                                    <td class="py-1 pe-3 text-right tabular-nums">{{ number_format((float) $challan->amount, 2) }}</td>
                                    <td class="py-1 pe-3 text-right tabular-nums">{{ number_format((float) $challan->interest, 2) }}</td>
                                    <td class="py-1 pe-3 text-right tabular-nums">{{ number_format((float) $challan->penalty, 2) }}</td>
                                    <td class="py-1 pe-3 text-right tabular-nums font-medium">{{ number_format($challan->total(), 2) }}</td>
                                    <td class="py-1 pe-3">{{ \App\Domain\Compliance\Models\StatutoryChallan::STATUSES[$challan->status] ?? $challan->status }}@if ($challan->verifier) <span class="text-xs text-gray-500">· {{ $challan->verifier->name }}</span>@endif</td>
                                    <td class="py-1 pe-3">{{ $challan->recorder?->name ?? '—' }} <span class="text-xs text-gray-500">· {{ $challan->recorded_at?->format('d M Y') }}</span></td>
                                    <td class="py-1 pe-3">
                                        <div class="flex flex-wrap gap-2">
                                            {{ ($this->viewChallanAction)(['challan' => $challan->id]) }}
                                            @if ($receipt)
                                                <a class="text-sm text-primary-600 underline" href="{{ $receipt }}" target="_blank" rel="noopener">Receipt</a>
                                            @endif
                                            {{ ($this->attachReceiptAction)(['challan' => $challan->id]) }}
                                            {{ ($this->verifyChallanAction)(['challan' => $challan->id]) }}
                                            {{ ($this->cancelChallanAction)(['challan' => $challan->id]) }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="mt-2">
                <x-filament::link tag="button" wire:click="closeCell" size="sm" color="gray">Close</x-filament::link>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
