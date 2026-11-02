{{-- HROne parity GO-4: the check-ins of a linked goal (HROne "Task period, Status, Comment, Activity"), newest first. --}}
@php
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
@endphp
<div class="space-y-4">
    @if ($checkIns->isEmpty())
        <p class="pos-caption">No check-ins yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10" tabindex="0" role="region" aria-label="Check-ins">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <thead>
                    <tr>
                        <th class="{{ $th }}">Checked in</th><th class="{{ $th }}">Period</th><th class="{{ $th }}">Status</th>
                        <th class="{{ $th }} text-right">Progress</th><th class="{{ $th }}">Comment</th><th class="{{ $th }}">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($checkIns as $checkIn)
                        <tr>
                            <td class="{{ $td }} whitespace-nowrap">{{ $checkIn->created_at?->format('d M Y, H:i') }}</td>
                            <td class="{{ $td }} whitespace-nowrap">{{ $checkIn->periodLabel() ?? '—' }}</td>
                            <td class="{{ $td }}">{{ $checkIn->healthLabel() ?? '—' }}@if ($checkIn->keyResult) <div class="pos-caption">{{ $checkIn->keyResult->title }}</div>@endif</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $checkIn->previous_progress, 2) }} → {{ number_format((float) $checkIn->progress, 2) }} %</td>
                            <td class="{{ $td }}">{{ $checkIn->note ?? '—' }}</td>
                            <td class="{{ $td }}">{{ $checkIn->author?->name ?? 'System' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
