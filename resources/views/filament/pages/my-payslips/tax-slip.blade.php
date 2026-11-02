{{-- HROne parity MS-9: the monthly income-tax slip, read from the payslip's tax snapshot. --}}
@if ($slip === null)
    <p class="text-sm text-gray-500">No tax was worked out on this payslip.</p>
@else
    <div class="space-y-4 text-sm" data-tax-slip>
        @foreach ($slip as $part)
            <div>
                <div class="font-medium">{{ $part['heading'] }}</div>
                <dl class="mt-1">
                    @foreach ($part['rows'] as $row)
                        <div class="flex justify-between border-t border-gray-200 py-1 dark:border-gray-700">
                            <dt class="text-gray-500">{{ $row['label'] }}</dt>
                            <dd class="tabular-nums">{{ $row['money'] ? number_format((float) $row['value'], 2) : $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </div>
@endif
