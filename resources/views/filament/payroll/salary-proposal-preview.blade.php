{{-- HROne parity PO-2 (matrix 25 §25.2.2 #12, #22, #23): the proposal's breakup for this employee (StructureFormulas::preview),
     the read-only statutory estimate (verified rules only), the salary checks and the payment mode. Nothing here is saved. --}}
@php($money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2))
<div class="space-y-4" data-proposal-preview>
    @if ($failure)
        <p class="text-sm text-danger-600 dark:text-danger-400" data-preview-failure>{{ $failure }}</p>
    @else
        @php($p = $proposal)
        <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Annual CTC proposed</dt><dd class="tabular-nums">{{ $p['currency'] }} {{ $money($p['ctc_annual']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Annual CTC in force</dt><dd class="tabular-nums">{{ $current ? $current->currency.' '.$money($current->ctcAnnual) : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Effective from</dt><dd>{{ \Illuminate\Support\Carbon::parse($p['effective_from'])->format('j M Y') }}</dd></div>
            @if ($p['increase_percent'] !== null)
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Revision</dt><dd class="tabular-nums">{{ $p['increase_percent'] >= 0 ? '+' : '' }}{{ $p['increase_percent'] }}%</dd></div>
            @endif
        </dl>

        <x-filament::section heading="Salary breakup" description="A full month of the effective date, from the structure's own formulas with this employee's values and PF options.">
            @include('filament.payroll.structure-preview', ['result' => $result['preview'], 'failure' => null])
        </x-filament::section>

        @php($est = $result['estimate'])
        <x-filament::section heading="Statutory deductions (estimate)" :description="'For '.$est['month'].'. Read-only: worked out by the statutory engine from verified rules only, nothing stored. Income tax (TDS) is not estimated.'">
            <div class="space-y-3 text-sm" data-estimate>
                @if ($est['note'])
                    <p class="text-gray-500">{{ $est['note'] }}</p>
                @endif
                <ul class="space-y-1">
                    @foreach ($est['schemes'] as $code => $scheme)
                        <li data-scheme="{{ $code }}" data-scheme-state="{{ $scheme['state'] }}"><span class="font-medium">{{ $scheme['label'] }}:</span>
                            <span class="{{ $scheme['state'] === 'no_rule' ? 'text-warning-600' : 'text-gray-500' }}">{{ $scheme['text'] }}</span></li>
                    @endforeach
                </ul>
                @if ($est['employee'] !== [] || $est['employer'] !== [])
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <p class="font-medium">Recurring deductions (employee)</p>
                            <dl class="mt-1 space-y-1">
                                @foreach ($est['employee'] as $line)
                                    <div class="flex justify-between" data-line="{{ $line['code'] }}"><dt class="text-gray-500 dark:text-gray-400">{{ $line['name'] }}</dt><dd class="tabular-nums">{{ $money($line['amount']) }}</dd></div>
                                @endforeach
                                <div class="flex justify-between font-medium"><dt>Total</dt><dd class="tabular-nums">{{ $money($est['employee_total']) }}</dd></div>
                            </dl>
                        </div>
                        <div>
                            <p class="font-medium">Contributions (employer)</p>
                            <dl class="mt-1 space-y-1">
                                @foreach ($est['employer'] as $line)
                                    <div class="flex justify-between" data-line="{{ $line['code'] }}"><dt class="text-gray-500 dark:text-gray-400">{{ $line['name'] }}</dt><dd class="tabular-nums">{{ $money($line['amount']) }}</dd></div>
                                @endforeach
                                <div class="flex justify-between font-medium"><dt>Total</dt><dd class="tabular-nums">{{ $money($est['employer_total']) }}</dd></div>
                            </dl>
                        </div>
                    </div>
                @endif
                @if ($est['take_home'] !== null)
                    <p class="font-medium" data-take-home>Net take home, excluding TDS: <span class="tabular-nums">{{ $money($est['take_home']) }}</span>
                        @unless ($est['complete'])<span class="text-xs font-normal text-warning-600">(before the deductions above that have no verified rule)</span>@endunless</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Salary insights" description="Advisory checks; they never stop a proposal. Statutory figures come only from verified rules.">
            <ul class="space-y-1 text-sm" data-checks>
                @foreach ($result['checks'] as $check)
                    <li data-check="{{ $check['check'] }}" data-check-state="{{ $check['state'] }}" class="{{ $check['state'] === 'attention' ? 'text-warning-600' : 'text-gray-600 dark:text-gray-300' }}">{{ $check['text'] }}</li>
                @endforeach
            </ul>
        </x-filament::section>

        <p class="text-sm" data-pay-mode>Salary payment mode: {{ $result['pay_mode']['label'] }}
            @if ($payModesUrl)· <a href="{{ $payModesUrl }}" class="text-primary-600 underline" target="_blank">Payment modes</a>@endif
        </p>
    @endif
</div>
