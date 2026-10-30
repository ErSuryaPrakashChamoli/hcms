@php
    $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 2);
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
    $sources = ['fixed' => 'Fixed', 'formula' => 'Formula', 'override' => 'Override', 'statutory' => 'Statutory', 'one_off' => 'One-off', 'input' => 'Input'];
@endphp
<div class="space-y-4" aria-live="polite">
    @if ($failure)
        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $failure }}</p>
    @elseif ($result === null || $result['rows'] === [])
        <p class="pos-caption">Add components to see the breakup.</p>
    @else
        @if ($result['errors'] !== [] || $result['warnings'] !== [])
            <ul class="space-y-1.5 text-sm">
                @foreach ($result['errors'] as $message)
                    <li class="rounded-lg bg-danger-50 px-3 py-2 text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300"><strong>Fix:</strong> {{ $message }}</li>
                @endforeach
                @foreach ($result['warnings'] as $message)
                    <li class="rounded-lg bg-warning-50 px-3 py-2 text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300"><strong>Check:</strong> {{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead>
                    <tr>
                        <th class="{{ $th }}">#</th><th class="{{ $th }}">Component</th><th class="{{ $th }}">How</th><th class="{{ $th }}">Formula</th>
                        <th class="{{ $th }} text-right">Monthly</th><th class="{{ $th }} text-right">Annual</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($result['rows'] as $row)
                        <tr>
                            <td class="{{ $td }} tabular-nums">{{ $row['sort_order'] }}</td>
                            <td class="{{ $td }}">
                                <div class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }} <span class="text-gray-500">({{ $row['code'] }})</span></div>
                                <div class="pos-caption">{{ config('peopleos.payroll.component_types.'.$row['type'], $row['type']) }}</div>
                            </td>
                            <td class="{{ $td }} whitespace-nowrap"><span class="rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ match ($row['source']) { 'override' => 'text-info-700 ring-info-600/30 dark:text-info-300', 'formula' => 'text-primary-700 ring-primary-600/30 dark:text-primary-300', 'statutory' => 'text-warning-700 ring-warning-600/30 dark:text-warning-300', default => 'text-gray-600 ring-gray-500/30 dark:text-gray-300' } }}">{{ $sources[$row['source']] ?? $row['source'] }}</span></td>
                            <td class="{{ $td }}">
                                @if ($row['formula'])<code class="break-words font-mono text-[0.8rem] [font-variant-ligatures:none]">{{ $row['formula'] }}</code>@endif
                                @if ($row['error'])<div class="text-danger-600 dark:text-danger-400">{{ $row['error'] }}</div>@endif
                                @if ($row['note'])<div class="pos-caption">{{ $row['note'] }}</div>@endif
                            </td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $money($row['monthly']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $money($row['annual']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @php($t = $result['totals'])
        <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Gross earnings</dt><dd class="tabular-nums">{{ $money($t['gross']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Monthly CTC{{ ($t['ctc_mode'] ?? 'ctc') === 'components' ? ' (worked out from the components)' : '' }}</dt><dd class="tabular-nums">{{ $money($t['ctc_monthly']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Other deductions (non-statutory)</dt><dd class="tabular-nums">{{ $money($t['deductions']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Employer PF (estimate{{ $t['pf_in_ctc'] ? ', inside CTC' : '' }})</dt><dd class="tabular-nums">{{ $money($t['pf_employer']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Employer contributions in CTC</dt><dd class="tabular-nums">{{ $money($t['employer']) }}</dd></div>
            <div class="flex justify-between font-medium"><dt>Breakup total vs CTC</dt><dd class="tabular-nums {{ abs($t['difference']) >= 1 ? 'text-warning-600 dark:text-warning-400' : '' }}">{{ $money($t['breakup']) }}{{ abs($t['difference']) >= 1 ? ' ('.($t['difference'] > 0 ? '−' : '+').$money(abs($t['difference'])).')' : '' }}</dd></div>
            @if ($t['wage_share'] !== null)
                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Basic + DA share of gross</dt><dd class="tabular-nums">{{ $t['wage_share'] }}%</dd></div>
            @endif
        </dl>
        <p class="pos-caption">Payroll works out PF, ESI, PT, LWF and TDS from the compliance rules, and prorates for days paid. This preview shows full-month amounts before those, with an employer-PF estimate for balancing to CTC.</p>
    @endif
</div>
