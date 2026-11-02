{{-- HROne parity MS-12: the person's own leave year month by month — days added and subtracted per leave type. --}}
@if ($table === null || $table['types'] === [])
    <p class="text-sm text-gray-500">No leave movements in this leave year.</p>
@else
    <div class="space-y-6 text-sm" data-month-view="{{ $table['year'] }}">
        @foreach ($table['types'] as $type)
            <div data-leave-type="{{ $type['type_id'] }}">
                <div class="font-medium">{{ $type['name'] }}</div>
                <table class="mt-1 w-full">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Leave month name</th><th class="text-right">Balance added</th><th class="text-right">Balance subtracted</th></tr></thead>
                    <tbody>
                        @foreach ($table['months'] as $key => $label)
                            <tr class="border-t border-gray-200 dark:border-gray-700" data-month="{{ $key }}">
                                <td class="py-1">{{ $label }}</td>
                                <td class="text-right tabular-nums">{{ rtrim(rtrim(number_format($type['months'][$key]['added'], 2), '0'), '.') }}</td>
                                <td class="text-right tabular-nums">{{ rtrim(rtrim(number_format($type['months'][$key]['subtracted'], 2), '0'), '.') }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t border-gray-200 font-semibold dark:border-gray-700"><td class="py-1">Total</td><td class="text-right tabular-nums">{{ rtrim(rtrim(number_format($type['added'], 2), '0'), '.') }}</td><td class="text-right tabular-nums">{{ rtrim(rtrim(number_format($type['subtracted'], 2), '0'), '.') }}</td></tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
@endif
