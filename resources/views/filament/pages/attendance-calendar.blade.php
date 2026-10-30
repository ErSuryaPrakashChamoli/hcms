<x-filament-panels::page>
    @php($cal = $this->calendar())
    @php($person = $this->person())
    <style>
        .pos-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .375rem; }
        .pos-cal-day { min-height: 4.5rem; border-radius: .5rem; padding: .375rem .5rem; box-shadow: inset 0 0 0 1px rgb(15 23 42 / .08); color: #0f172a; }
        .pos-cal-head { font-size: .75rem; font-weight: 600; color: rgb(100 116 139); text-align: center; }
        .pos-cal-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr)); gap: .5rem; }
        @media print {
            .pos-cal-noprint, .fi-sidebar, .fi-topbar, .fi-header-actions { display: none !important; }
            .pos-cal-day { min-height: 3.5rem; }
        }
    </style>

    <div class="pos-cal-noprint flex flex-wrap items-end gap-2">
        <x-filament::button color="gray" icon="heroicon-m-chevron-left" wire:click="shiftMonth(-1)">Previous</x-filament::button>
        <span class="text-sm font-semibold tabular-nums px-2">{{ $cal['start']->format('F Y') }}</span>
        <x-filament::button color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="shiftMonth(1)">Next</x-filament::button>
        @if ($this->canPick())
            <label class="text-sm">
                <span class="sr-only">Employee</span>
                <select wire:model.live="employee" class="rounded-lg text-sm ring-1 ring-gray-950/10 dark:bg-white/5" aria-label="Employee">
                    @foreach ($this->employeeOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <x-filament::button color="gray" icon="heroicon-m-printer" onclick="window.print()">Print slip</x-filament::button>
    </div>

    @if ($person === null)
        <x-filament::section>
            <p class="text-sm text-gray-500">Choose an employee to see their attendance.</p>
        </x-filament::section>
    @else
        <x-filament::section :heading="$person->display_name.' · '.$person->employee_code" :description="'Attendance for '.$cal['start']->format('F Y')">
            <div class="pos-cal-summary mb-4">
                @foreach ($cal['summary'] as $label => $value)
                    <div class="rounded-lg p-2 ring-1 ring-gray-950/10">
                        <div class="text-xs text-gray-500">{{ $label }}</div>
                        <div class="font-semibold tabular-nums">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div class="pos-cal-grid" role="grid" aria-label="Days of {{ $cal['start']->format('F Y') }}">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                    <div class="pos-cal-head" role="columnheader">{{ $weekday }}</div>
                @endforeach
                @for ($i = 0; $i < $cal['lead']; $i++)
                    <div aria-hidden="true"></div>
                @endfor
                @foreach ($cal['days'] as $day)
                    <div class="pos-cal-day" role="gridcell" style="background: {{ $day['colour'] }}" title="{{ $day['label'] }}{{ $day['holiday'] ? ' · '.$day['holiday'] : '' }}{{ $day['trip'] ? ' · Travel '.$day['trip'] : '' }}">
                        <div class="flex items-baseline justify-between gap-1">
                            <span class="text-xs font-semibold tabular-nums">{{ $day['date']->day }}</span>
                            <span class="text-xs font-semibold">{{ $day['code'] }}</span>
                        </div>
                        @if ($day['in'] || $day['out'])
                            <div class="text-xs tabular-nums">{{ $day['in'] ?? '–' }} – {{ $day['out'] ?? '–' }}</div>
                        @endif
                        @if ($day['late'] > 0)
                            <div class="text-xs text-danger-600">Late {{ $day['late'] }}m</div>
                        @endif
                        @if ($day['holiday'])
                            <div class="text-xs">{{ \Illuminate\Support\Str::limit($day['holiday'], 18) }}</div>
                        @endif
                        @if ($day['trip'])
                            <div class="text-xs font-semibold" aria-label="Travel {{ $day['trip'] }}">✈ {{ \Illuminate\Support\Str::limit($day['trip'], 18) }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500">
                @foreach (\App\Filament\Pages\AttendanceCalendar::CODES as $status => $code)
                    <span class="mr-3">{{ $code }} {{ config("peopleos.attendance.statuses.{$status}", $status) }}</span>
                @endforeach
            </p>
        </x-filament::section>

        <x-filament::section heading="Holidays this month">
            @if ($cal['holidays'] === [])
                <p class="text-sm text-gray-500">No holidays on the calendar this month.</p>
            @else
                <ul class="grid gap-1">
                    @foreach ($cal['holidays'] as $holiday)
                        <li class="flex justify-between text-sm"><span>{{ $holiday['date']->format('D, d M') }} · {{ $holiday['name'] }}</span><span class="text-gray-500">{{ $holiday['kind'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
