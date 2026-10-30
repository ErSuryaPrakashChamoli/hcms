{{-- HROne setup parity (Code series): every code type, whether it is set up, and the numbers it issues now. --}}
@php
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
@endphp
<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <p>A code type without a series of its own keeps its built-in format. A company-level code type is set up when there is an organisation-wide series or one for every company.</p>
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10" tabindex="0" role="region" aria-label="Code types">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
            <caption class="sr-only">Code types</caption>
            <thead><tr><th scope="col" class="{{ $th }}">Module</th><th scope="col" class="{{ $th }}">Code type</th><th scope="col" class="{{ $th }}">Applicable on</th><th scope="col" class="{{ $th }}">Configured?</th><th scope="col" class="{{ $th }}">Next number</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr>
                        <td class="{{ $td }} whitespace-nowrap">{{ $row['module'] }}</td>
                        <td class="{{ $td }}">{{ $row['label'] }}</td>
                        <td class="{{ $td }} whitespace-nowrap">{{ $row['applicable_label'] }}</td>
                        <td class="{{ $td }} whitespace-nowrap">{{ $row['configured_label'] }}</td>
                        <td class="{{ $td }} whitespace-nowrap font-mono text-[0.8rem]">{{ $row['next'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
