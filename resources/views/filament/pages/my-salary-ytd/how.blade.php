{{-- HROne parity MS-10: "How it is calculated" — the component's set-up and what each month recorded. --}}
@if ($row === null)
    <p class="text-sm text-gray-500">Not available.</p>
@else
    <div class="space-y-4 text-sm" data-how="{{ $row['key'] }}">
        <dl class="grid gap-4 md:grid-cols-3">
            <div><dt class="text-gray-500">Total paid this year</dt><dd class="font-semibold tabular-nums">{{ number_format($row['total'], 2) }}</dd></div>
            @if ($s = $row['settings'])
                <div><dt class="text-gray-500">Component type</dt><dd>{{ ['formula' => 'Formula', 'fixed' => 'Fixed amount', 'input' => 'Monthly input', 'statutory' => 'Statutory'][$s['method']] ?? ucfirst($s['method']) }}</dd></div>
                @if ($s['formula'])
                    <div><dt class="text-gray-500">Formula</dt><dd class="font-mono">{{ $s['formula'] }}</dd></div>
                @endif
                <div><dt class="text-gray-500">Taxable</dt><dd>{{ $s['taxable'] ? 'Yes' : 'No' }}@if ($s['exempt_in_regimes'] !== []) (exempt in the {{ implode(', ', $s['exempt_in_regimes']) }} regime)@endif</dd></div>
                <div><dt class="text-gray-500">Prorated on paid days</dt><dd>{{ $s['proratable'] ? 'Yes' : 'No' }}</dd></div>
                <div><dt class="text-gray-500">Part of arrears</dt><dd>{{ $s['arrear_eligible'] ? 'Yes' : 'No' }}</dd></div>
                <div><dt class="text-gray-500">Counts for PF / ESI</dt><dd>{{ $s['pf_applicable'] ? 'PF' : 'No PF' }} · {{ $s['esi_applicable'] ? 'ESI' : 'No ESI' }}</dd></div>
                @if ($s['pay_in_joining_month'] !== null || $s['pay_in_exit_month'] !== null)
                    <div><dt class="text-gray-500">Joining / leaving month</dt><dd>{{ $s['pay_in_joining_month'] === false ? 'Not paid in the joining month' : 'Paid in the joining month' }} · {{ $s['pay_in_exit_month'] === false ? 'not paid in the leaving month' : 'paid in the leaving month' }}</dd></div>
                @endif
            @endif
        </dl>
        @if ($row['detail'] !== [])
            <table class="w-full" data-how-months>
                <thead><tr class="text-left text-gray-500"><th class="py-1">Month</th><th class="text-right">Full month</th><th class="text-right">Paid days</th><th class="text-right">Dividing factor</th><th class="text-right">Paid</th></tr></thead>
                <tbody>
                    @foreach ($row['detail'] as $key => $d)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1">{{ $months[$key] ?? $key }}</td>
                            <td class="text-right tabular-nums">{{ $d['full_month'] === null ? '—' : number_format($d['full_month'], 2) }}</td>
                            <td class="text-right tabular-nums">{{ rtrim(rtrim(number_format($d['paid_days'], 2), '0'), '.') }} / {{ $d['days_in_period'] }}</td>
                            <td class="text-right tabular-nums">{{ $d['prorated'] && $d['divisor'] ? $d['divisor'] : '—' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['months'][$key] ?? 0, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if ($row['arrears'] !== [])
            <div>
                <div class="font-medium">Arrears</div>
                <table class="mt-1 w-full">
                    <tbody>
                        @foreach ($row['arrears'] as $a)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $months[$a['month']] ?? $a['month'] }}</td><td>{{ $a['name'] }}</td><td class="text-right tabular-nums">{{ number_format($a['amount'], 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
