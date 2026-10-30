{{-- HROne setup parity HP-9a (EN-10): the article's audience, with when each person read and acknowledged the current version. --}}
@php
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
    $done = $rows->whereNotNull('acknowledged_at')->count();
@endphp
<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <p>{{ $done }} of {{ $rows->count() }} acknowledged.@if ($due) Due by {{ $due->format('j M Y') }}.@endif</p>
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10" tabindex="0" role="region" aria-label="Acknowledgements">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
            <caption class="sr-only">Acknowledgements</caption>
            <thead><tr><th scope="col" class="{{ $th }}">Employee</th><th scope="col" class="{{ $th }}">Department</th><th scope="col" class="{{ $th }}">Read</th><th scope="col" class="{{ $th }}">Acknowledged</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr>
                        <td class="{{ $td }}">{{ $row['employee']->person?->display_name }} <span class="text-gray-500 dark:text-gray-400">{{ $row['employee']->employee_code }}</span></td>
                        <td class="{{ $td }}">{{ $row['employee']->currentPosition?->department?->name ?? '—' }}</td>
                        <td class="{{ $td }} whitespace-nowrap">{{ $row['read_at']?->format('j M Y, H:i') ?? '—' }}</td>
                        <td class="{{ $td }} whitespace-nowrap">{{ $row['acknowledged_at']?->format('j M Y, H:i') ?? 'Not yet' }}</td>
                    </tr>
                @empty
                    <tr><td class="{{ $td }}" colspan="4">Nobody is in this article's audience.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
