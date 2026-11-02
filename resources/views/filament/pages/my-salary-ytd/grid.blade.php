{{-- HROne parity MS-10: component × month grid in the financial year's order, with totals. --}}
<div class="overflow-x-auto">
    <table class="min-w-full text-sm" data-ytd-grid>
        <thead>
            <tr class="text-left text-gray-500">
                <th class="py-1 pe-3">Component</th>
                @foreach ($months as $key => $label)
                    <th class="whitespace-nowrap px-2 text-right" title="{{ $label }}">{{ \Illuminate\Support\Carbon::parse($key.'-01')->format('M') }}</th>
                @endforeach
                <th class="px-2 text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $group => $rows)
                <tr class="border-t border-gray-200 dark:border-gray-700"><td colspan="{{ count($months) + 2 }}" class="py-1 font-medium">{{ $group }}</td></tr>
                @foreach ($rows as $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700" data-row="{{ $row['key'] }}">
                        <td class="whitespace-nowrap py-1 pe-3">
                            {{ $row['name'] }}
                            @if ($row['settings'] !== null || $row['detail'] !== [] || $row['arrears'] !== [])
                                <div>{{ ($this->howAction)(['row' => $row['key']]) }}</div>
                            @endif
                        </td>
                        @foreach ($months as $key => $label)
                            <td class="whitespace-nowrap px-2 text-right tabular-nums">{{ $cell($key, $row['months'][$key] ?? 0) }}</td>
                        @endforeach
                        <td class="whitespace-nowrap px-2 text-right font-medium tabular-nums">{{ $money($row['total']) }}</td>
                    </tr>
                @endforeach
            @endforeach
            @foreach ($totals as $label => $total)
                <tr class="border-t border-gray-200 font-semibold dark:border-gray-700" data-total="{{ $label }}">
                    <td class="py-1 pe-3">{{ $label }}</td>
                    @foreach ($months as $key => $monthLabel)
                        <td class="whitespace-nowrap px-2 text-right tabular-nums">{{ $cell($key, $total['months'][$key] ?? 0) }}</td>
                    @endforeach
                    <td class="whitespace-nowrap px-2 text-right tabular-nums">{{ $money($total['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
