{{-- HROne parity PO-13 (§25.6.12 #16): a challan as recorded — every field, who recorded and verified it, the receipt, and for income tax the employees whose TDS it deposits (PAN masked). --}}
<div class="space-y-3 text-sm" data-challan-view>
    <dl class="grid gap-2 md:grid-cols-2">
        <div><dt class="text-gray-500">Statute</dt><dd class="font-medium">{{ $challan['statute'] }}</dd></div>
        <div><dt class="text-gray-500">Paid for</dt><dd class="font-medium">{{ $challan['unit'] }}@if ($challan['registration']) <span class="text-xs text-gray-500">· {{ $challan['registration'] }}</span>@endif</dd></div>
        <div><dt class="text-gray-500">Month</dt><dd class="font-medium">{{ $challan['month'] }}</dd></div>
        <div><dt class="text-gray-500">Return</dt><dd class="font-medium">{{ $challan['return'] ?? 'Not generated when recorded' }}</dd></div>
        @foreach ($challan['fields'] as $label => $value)
            <div><dt class="text-gray-500">{{ $label }}</dt><dd class="font-medium">{{ $value }}</dd></div>
        @endforeach
        <div><dt class="text-gray-500">Amount</dt><dd class="font-medium tabular-nums">{{ number_format($challan['amount'], 2) }}</dd></div>
        <div><dt class="text-gray-500">Interest paid</dt><dd class="font-medium tabular-nums">{{ number_format($challan['interest'], 2) }}</dd></div>
        <div><dt class="text-gray-500">Penalty paid</dt><dd class="font-medium tabular-nums">{{ number_format($challan['penalty'], 2) }}</dd></div>
        <div><dt class="text-gray-500">Total paid</dt><dd class="font-semibold tabular-nums">{{ number_format($challan['total'], 2) }}</dd></div>
        <div><dt class="text-gray-500">Status</dt><dd class="font-medium">{{ $challan['status'] }}</dd></div>
        <div><dt class="text-gray-500">Submitted by / on</dt><dd class="font-medium">{{ $challan['recorded'] }}</dd></div>
        @if ($challan['verified'])
            <div><dt class="text-gray-500">Verified by / on</dt><dd class="font-medium">{{ $challan['verified'] }}</dd></div>
        @endif
        @if ($challan['cancelled'])
            <div><dt class="text-gray-500">Cancelled</dt><dd class="font-medium">{{ $challan['cancelled'] }}</dd></div>
        @endif
        <div><dt class="text-gray-500">Receipt</dt><dd class="font-medium">@if ($receipt)<a class="text-primary-600 underline" href="{{ $receipt }}" target="_blank" rel="noopener">{{ $challan['receipt'] ?? 'Download' }}</a>@else — @endif</dd></div>
    </dl>
    @if (filled($challan['notes']))
        <p><span class="text-gray-500">Notes:</span> {{ $challan['notes'] }}</p>
    @endif

    @if ($challan['allocations'] !== [])
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Employees in this challan">
            <table class="w-full text-sm" data-allocations>
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-1 pe-3">Employee code</th>
                        <th class="py-1 pe-3">Employee name</th>
                        <th class="py-1 pe-3">PAN</th>
                        <th class="py-1 pe-3 text-right">TDS deducted</th>
                        <th class="py-1 pe-3 text-right">Deposited here</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($challan['allocations'] as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1 pe-3">{{ $row['code'] }}</td>
                            <td class="py-1 pe-3">{{ $row['name'] }}</td>
                            <td class="py-1 pe-3">{{ $row['pan'] }}</td>
                            <td class="py-1 pe-3 text-right tabular-nums">{{ number_format($row['deducted'], 2) }}</td>
                            <td class="py-1 pe-3 text-right tabular-nums">{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
