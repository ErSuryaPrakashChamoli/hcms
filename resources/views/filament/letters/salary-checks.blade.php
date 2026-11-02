{{-- HROne parity WO-13: compliance warnings of a salary letter (LetterSalaryChecks; thresholds only from verified rules). --}}
@php($attention = collect($checks)->where('state', 'attention'))
@php($notes = collect($checks)->whereIn('state', ['no_rule', 'not_run']))
<div class="space-y-2">
    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Compliance warnings</p>
    @if ($attention->isNotEmpty())
        <div class="rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:text-warning-400" role="alert">
            <ul class="list-disc space-y-1 ps-5">
                @foreach ($attention as $check)
                    <li>{{ $check['text'] }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400">No compliance warning for this salary.</p>
    @endif
    @foreach ($notes as $check)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $check['text'] }}</p>
    @endforeach
</div>
