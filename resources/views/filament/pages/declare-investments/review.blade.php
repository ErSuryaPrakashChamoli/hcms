{{-- Review step of the investment declaration: totals per section against what payroll counts, and the proofs to keep. --}}
<div class="space-y-6 text-sm">
    <p>Tax regime: <span class="font-semibold">{{ $regime }}</span></p>

    @if ($isNew && $rows !== [])
        <p class="rounded-lg bg-warning-50 px-4 py-3 text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">
            Under the new regime these amounts are recorded but do not reduce your tax. Go back to Start if you want the old regime.
        </p>
    @endif

    @if ($rows === [])
        <p class="text-gray-600 dark:text-gray-400">You have not declared anything. That is fine if nothing applies to you: payroll then deducts tax on your full salary.</p>
    @else
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-start">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">Section</th>
                        <th class="px-4 py-2 text-end font-medium">You declared</th>
                        <th class="px-4 py-2 text-start font-medium">What payroll counts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-2">{{ $row['title'] }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ $row['declared'] }}</td>
                            <td @class(['px-4 py-2', 'text-warning-700 dark:text-warning-400' => $row['over']])>{{ $row['counts'] }}@if ($row['over']) · above the limit @endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($estimate))
        <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/5">
            <p class="font-medium">Estimated tax for the year</p>
            <ul class="mt-1 space-y-0.5">
                @foreach ($estimate as $e)
                    <li>{{ $e['regime'] }}: <span class="font-semibold tabular-nums">{{ $e['tax'] }}</span></li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs text-gray-500">A rough guide from your recent salary and these amounts; payroll works out the tax deducted each month.</p>
        </div>
    @endif

    @if ($previous)
        <p>Previous employer this year: salary {{ $previous['income'] }}, tax deducted {{ $previous['tds'] }}.</p>
    @endif

    @foreach ($warnings as $warning)
        <p class="rounded-lg bg-warning-50 px-4 py-3 text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">{{ $warning }}</p>
    @endforeach

    @if ($proofs !== [])
        <div>
            <p class="font-semibold">Proofs to keep ready</p>
            <p class="text-gray-600 dark:text-gray-400">Payroll will ask for these later in the year. An item without a proof may stop counting, which means more tax from your salary.</p>
            <ul class="mt-2 list-disc space-y-1 ps-5">
                @foreach ($proofs as $proof)
                    <li>{{ $proof }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
