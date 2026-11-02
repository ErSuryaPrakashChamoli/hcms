{{-- HROne parity TX-3: the list a Run attendance card opened (first 200 rows; the CSV has every row). --}}
@php($opened = $this->openedList())
@if ($opened !== null)
    <div class="mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700" data-open-list="{{ $openList }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold">{{ \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$openList][0] }} · {{ count($opened['rows']) }}</p>
            @if (count($opened['rows']) > 0)
                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadList('{{ $openList }}')">Download CSV</x-filament::button>
            @endif
        </div>
        @if (count($opened['rows']) === 0)
            <p class="mt-2 text-sm text-gray-500">Nothing to review.</p>
        @else
            <div class="mt-2 overflow-x-auto" tabindex="0" role="region" aria-label="{{ \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$openList][0] }}">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500">@foreach ($opened['columns'] as $heading)<th class="py-1 pe-3">{{ $heading }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach (array_slice($opened['rows'], 0, 200) as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700">
                                @foreach (array_keys($opened['columns']) as $column)<td class="py-1 pe-3">{{ $row[$column] ?? '' }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (count($opened['rows']) > 200)<p class="mt-2 text-xs text-gray-500">Showing 200 of {{ count($opened['rows']) }}; the CSV has every row.</p>@endif
        @endif
    </div>
@endif
