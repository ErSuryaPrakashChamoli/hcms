<x-filament-panels::page>
    {{-- HROne parity PO-22: the bonus dashboard of a company and accounting year (BonusProcessing). --}}
    @php($summary = $this->summary())
    @php($money = fn ($v) => number_format((float) $v, 2))

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-wrap items-end gap-4">
            <label class="text-sm">
                <span class="text-gray-500">Company</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="company" data-bonus-company>
                        @foreach ($this->companyOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label class="text-sm">
                <span class="text-gray-500">Accounting year</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="year" data-bonus-year>
                        @foreach ($this->yearOptions() as $fy => $label)
                            <option value="{{ $fy }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>
    </div>

    @if (! $summary['ready'])
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" heading="Awaiting verification of the BONUS rule" data-bonus-awaiting>
            <p class="text-sm text-gray-600 dark:text-gray-400">Statutory bonus for FY {{ $this->year }} is not calculated, processed or approved until a verified version of the BONUS statutory rule applies to every month of the year. The eligibility and calculation ceilings, the minimum and maximum rate, the minimum amount, the minimum days and the payment deadline come only from that rule — never typed here.</p>
            <ul class="mt-1 list-disc ps-5 text-sm text-warning-600">
                @foreach ($summary['problems'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @else
        <x-filament::section heading="FY {{ $this->year }}" :description="'Calculated with '.implode(', ', $summary['rules']).' (verified).'" data-bonus-rule>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-gray-500">Your bonus percentage</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $summary['rate'] }}</dd></div>
                <div><dt class="text-gray-500">Allowed by the rule</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $summary['range'] }}, at least ₹{{ $money($summary['minimum_amount']) }}</dd></div>
                <div><dt class="text-gray-500">Eligible up to (basic + DA a month)</dt><dd class="font-medium text-gray-950 dark:text-white">₹{{ $money($summary['eligibility_ceiling']) }}</dd></div>
                <div><dt class="text-gray-500">Calculated on at most (a month)</dt><dd class="font-medium text-gray-950 dark:text-white">₹{{ $money($summary['calculation_ceiling']) }}</dd></div>
                <div><dt class="text-gray-500">Minimum days worked</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $summary['minimum_days'] }}</dd></div>
                <div><dt class="text-gray-500">Pay by</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $summary['deadline'] ?? '—' }}</dd></div>
                @if ($summary['run'])
                    <div><dt class="text-gray-500">Prepared</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $summary['run']['prepared'] }}{{ $summary['run']['by'] ? ' by '.$summary['run']['by'] : '' }}{{ $summary['run']['recalculated'] ? ' · recalculated '.$summary['run']['recalculated'] : '' }} (at {{ $summary['run']['rate'] }})</dd></div>
                    <div><dt class="text-gray-500">Employees</dt><dd class="font-medium text-gray-950 dark:text-white" data-bonus-counts>{{ $summary['counts']['employees'] }} · {{ $summary['counts']['included'] }} included · {{ $summary['counts']['waiting'] }} waiting for approval · {{ $summary['counts']['processed'] }} processed · {{ $summary['counts']['paid'] }} paid</dd></div>
                    <div><dt class="text-gray-500">Bonus · ex gratia</dt><dd class="font-medium text-gray-950 dark:text-white tabular-nums">{{ $money($summary['totals']['bonus']) }} · {{ $money($summary['totals']['ex_gratia']) }}</dd></div>
                @endif
            </dl>
            @if ($summary['open_year'])
                <p class="mt-1 text-xs text-gray-500">The year has not ended: only the months whose payroll is finalized count so far.</p>
            @endif
        </x-filament::section>
    @endif

    {{-- HROne's two tabs. --}}
    <x-filament::tabs>
        @foreach ($this::TABS as $key => $label)
            <x-filament::tabs.item tag="a" :href="$this->tabUrl($key)" :active="$this->tab() === $key" data-bonus-tab="{{ $key }}">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
