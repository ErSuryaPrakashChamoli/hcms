<x-filament-panels::page>
    @php($cal = $this->calendar())
    @php($person = $this->person())
    @php($monthLabel = $cal['start']->format('F Y'))
    {{-- Each day opens the day panel (regularise, apply leave): one's own month, and for HR anyone's (their day drawer is in the panel). --}}
    @php($own = $person !== null && $this->isOwn())
    @php($canOpen = $person !== null && $this->subject() !== null)

    <div class="pos-cal">
        {{-- Who and which month; the picker and the slip. --}}
        <div class="pos-cal-bar">
            <div class="pos-cal-who">
                @if ($person)
                    <x-pos.avatar :name="$person->display_name" size="lg" />
                    <div class="min-w-0">
                        <h2 class="pos-cal-name">{{ $person->display_name }}</h2>
                        <p class="pos-cal-sub">{{ $person->employee_code }} · Attendance for {{ $monthLabel }}</p>
                        {{-- The shifts assigned this month, and a mark when days have none. --}}
                        <p class="pos-cal-shiftline">
                            @foreach ($cal['shifts']['assigned'] as $shift)
                                <span class="pos-cal-shifttag" title="Shift {{ $shift['code'] }}">
                                    <x-filament::icon icon="heroicon-m-clock" class="pos-cal-shifttag-icon" />{{ $shift['label'] }}
                                </span>
                            @endforeach
                            @if ($cal['shifts']['unassigned'] > 0)
                                <span class="pos-cal-shifttag is-unassigned" data-shift-unassigned="{{ $cal['shifts']['unassigned'] }}">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="pos-cal-shifttag-icon" />
                                    {{ $cal['shifts']['assigned'] === [] ? 'Shift not assigned' : 'Shift not assigned on '.$cal['shifts']['unassigned'].' '.\Illuminate\Support\Str::plural('day', $cal['shifts']['unassigned']) }}
                                </span>
                            @endif
                        </p>
                    </div>
                @else
                    <h2 class="pos-cal-name">Attendance for {{ $monthLabel }}</h2>
                @endif
            </div>
            <div class="pos-cal-tools pos-cal-noprint">
                @if ($this->canPick())
                    {{-- One search box: type an employee ID or a name, the matches show right below it (searched on the server,
                         within the viewer's access scope); picking one opens their month and empties the box. --}}
                    @php($matches = $this->employeeMatches())
                    <div class="pos-cal-picker relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input type="search" wire:model.live.debounce.300ms="employeeSearch" placeholder="Search employee"
                                aria-label="Search employee by ID or name" autocomplete="off" role="combobox" aria-autocomplete="list"
                                aria-controls="pos-cal-picker-results" x-bind:aria-expanded="open ? 'true' : 'false'"
                                x-on:focus="open = true" x-on:input="open = true" wire:keydown.enter.prevent="pickFirstMatch" />
                        </x-filament::input.wrapper>
                        @if (trim($employeeSearch) !== '')
                            <div id="pos-cal-picker-results" role="listbox" x-show="open" x-cloak
                                class="absolute mt-1 w-full overflow-y-auto rounded-lg bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                                style="z-index: 30; max-height: 18rem; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);">
                                <div class="fi-dropdown-list">
                                    @forelse ($matches as $id => $label)
                                        <button type="button" role="option" class="fi-dropdown-list-item text-left"
                                            wire:key="pick-{{ $id }}" wire:click="pickEmployee({{ (int) $id }})" x-on:click="open = false">
                                            <span class="fi-dropdown-list-item-label truncate">{{ $label }}</span>
                                        </button>
                                    @empty
                                        <p class="fi-dropdown-list-item text-gray-500">No employee matches.</p>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
                <x-filament::button color="gray" icon="heroicon-m-printer" onclick="window.print()">Print slip</x-filament::button>
            </div>
        </div>

        {{-- The month (about five sixths) beside its totals (a column on the right; above the month when narrow). With no
             employee picked it still shows: plain days, the holidays everyone has and empty totals, filled in once someone is picked. --}}
        <div class="pos-cal-main">
            <section class="pos-card pos-cal-card" aria-label="Attendance for {{ $monthLabel }}">
                <header class="pos-cal-card-head">
                    {{-- The month and the year are picked separately, from the joining month to the last month of the payroll year. --}}
                    @php([$firstMonth, $lastMonth] = $this->monthBounds())
                    <div class="pos-cal-nav">
                        <x-filament::icon-button class="pos-cal-noprint" color="gray" icon="heroicon-m-chevron-left" wire:click="shiftMonth(-1)" label="Previous month"
                            :tooltip="$cal['start']->lte($firstMonth) ? 'Joining month' : 'Previous month'" :disabled="$cal['start']->lte($firstMonth)" />
                        <h3 class="pos-cal-month pos-cal-printonly">{{ $monthLabel }}</h3>
                        <div class="pos-cal-pick pos-cal-noprint">
                            <x-filament::input.wrapper class="pos-cal-pick-month">
                                <x-filament::input.select wire:model.live="calMonth" aria-label="Month">
                                    @foreach (range(1, 12) as $m)
                                        @php($option = \Illuminate\Support\Carbon::create($calYear, $m, 1))
                                        <option value="{{ $m }}" @disabled($option->lt($firstMonth) || $option->gt($lastMonth))>{{ $option->format('F') }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            <x-filament::input.wrapper class="pos-cal-pick-year">
                                <x-filament::input.select wire:model.live="calYear" aria-label="Year">
                                    @foreach (range($firstMonth->year, $lastMonth->year) as $y)
                                        <option value="{{ $y }}">{{ $y }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <x-filament::icon-button class="pos-cal-noprint" color="gray" icon="heroicon-m-chevron-right" wire:click="shiftMonth(1)" label="Next month"
                            :tooltip="$cal['start']->gte($lastMonth) ? 'Last month of the payroll year' : 'Next month'" :disabled="$cal['start']->gte($lastMonth)" />
                        @unless ($cal['start']->isSameMonth(today()))
                            <x-filament::button class="pos-cal-noprint" color="gray" size="xs" wire:click="thisMonth">This month</x-filament::button>
                        @endunless
                    </div>
                    @if ($hint = $this->dayHint())
                        <p class="pos-cal-hint pos-cal-noprint">
                            <x-filament::icon icon="heroicon-m-cursor-arrow-rays" :size="\Filament\Support\Enums\IconSize::Small" class="pos-cal-pending-icon" />
                            {{ $hint }}
                        </p>
                    @endif
                    @if ($cal['unprocessed'] > 0)
                        <p class="pos-cal-pending">
                            <x-filament::icon icon="heroicon-m-clock" :size="\Filament\Support\Enums\IconSize::Small" class="pos-cal-pending-icon" />
                            {{ $cal['unprocessed'] }} {{ \Illuminate\Support\Str::plural('day', $cal['unprocessed']) }} not processed yet
                        </p>
                    @endif
                </header>

                <div class="pos-cal-grid" role="grid" aria-label="Days of {{ $monthLabel }}">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="pos-cal-weekday" role="columnheader">{{ $weekday }}</div>
                    @endforeach
                    @for ($i = 0; $i < $cal['lead']; $i++)
                        <div class="pos-cal-blank" aria-hidden="true"></div>
                    @endfor
                    @foreach ($cal['days'] as $day)
                        <div @class(['pos-cal-day', 'is-today' => $day['date']->isToday(), 'is-planned' => $day['planned'], 'is-openable' => $canOpen])
                            data-tone="{{ $day['tone'] }}" role="gridcell"
                            title="{{ $day['date']->format('D j M') }} · {{ $day['label'] }}{{ $day['holiday'] ? ' · '.$day['holiday'] : '' }}{{ $day['trip'] ? ' · Travel '.$day['trip'] : '' }}">
                            {{-- The date, then the day's shift (or the "No shift" mark) beside its status code: the top row always shows,
                                 however short the day is. --}}
                            <div class="pos-cal-day-top">
                                <span class="pos-cal-date">{{ $day['date']->day }}</span>
                                <span class="pos-cal-day-tags">
                                    @if ($day['shift'] && ! in_array($day['tone'], ['weekly_off', 'holiday'], true))
                                        <span class="pos-cal-shift" title="Shift: {{ $day['shift_title'] }}">{{ $day['shift'] }}</span>
                                    @elseif ($day['no_shift'])
                                        <span class="pos-cal-shift is-unassigned" title="No shift assigned for this day">No shift</span>
                                    @endif
                                    @if ($day['code'] !== '')
                                        <span class="pos-cal-code">{{ $day['code'] }}</span>
                                    @endif
                                </span>
                            </div>
                            <div class="pos-cal-day-body">
                                @if ($day['in'] && $day['out'])
                                    <span class="pos-cal-times">{{ $day['in'] }} – {{ $day['out'] }}</span>
                                @elseif ($day['in'] || $day['out'])
                                    <span class="pos-cal-times">{{ $day['in'] ? 'In '.$day['in'] : 'Out '.$day['out'] }}</span>
                                @endif
                                @if ($day['late'] > 0)
                                    <span class="pos-cal-flag">Late {{ $day['late'] }}m</span>
                                @endif
                                @if ($day['holiday'])
                                    <span class="pos-cal-note">{{ \Illuminate\Support\Str::limit($day['holiday'], 22) }}</span>
                                @endif
                                @if ($day['trip'])
                                    <span class="pos-cal-trip" aria-label="Travel {{ $day['trip'] }}">✈ {{ \Illuminate\Support\Str::limit($day['trip'], 18) }}</span>
                                @endif
                                @foreach ($day['requests'] as $request)
                                    <span class="pos-cal-req" data-state="{{ $request['state'] }}" title="{{ $request['title'] }}">{{ $request['label'] }}</span>
                                @endforeach
                            </div>
                            @if ($canOpen)
                                {{-- The day panel (one's own day, or for HR the employee's); the whole day is the target. --}}
                                <button type="button" class="pos-cal-open pos-cal-noprint" @if ($own) data-own-day="{{ $day['date']->toDateString() }}" @else data-panel-day="{{ $day['date']->toDateString() }}" @endif
                                    wire:click="mountAction('day', { date: '{{ $day['date']->toDateString() }}' })"
                                    aria-label="{{ $day['date']->format('D j M Y') }} · {{ $day['label'] }}{{ $day['issue'] ? ' · needs attention' : '' }}">
                                    <x-filament::icon icon="heroicon-m-arrow-up-right" :size="\Filament\Support\Enums\IconSize::Small" class="pos-cal-open-icon" />
                                </button>
                            @endif
                        </div>
                    @endforeach
                    @for ($i = 0; $i < $cal['trail']; $i++)
                        <div class="pos-cal-blank" aria-hidden="true"></div>
                    @endfor
                </div>

                <ul class="pos-cal-legend" aria-label="Key">
                    @foreach (\App\Filament\Pages\AttendanceCalendar::CODES as $status => $code)
                        <li data-tone="{{ $status }}"><span class="pos-cal-code">{{ $code }}</span>{{ config("peopleos.attendance.statuses.{$status}", $status) }}</li>
                    @endforeach
                    <li data-tone="holiday" class="is-planned"><span class="pos-cal-code">HO</span>Outlined: planned, not processed yet</li>
                    @if ($cal['shifts']['assigned'] !== [])
                        <li><span class="pos-cal-shift">{{ $cal['shifts']['assigned'][0]['code'] }}</span>Shift of the day</li>
                    @endif
                    @if ($person !== null)
                        <li><span class="pos-cal-shift is-unassigned">No shift</span>Shift not assigned</li>
                    @endif
                </ul>
            </section>

            {{-- The month's totals, each in its status tone. --}}
            <dl class="pos-cal-stats" aria-label="Totals for {{ $monthLabel }}">
                @foreach ($cal['summary'] as $stat)
                    <div class="pos-cal-stat" data-tone="{{ $stat['tone'] }}">
                        <dt>{{ $stat['label'] }}</dt>
                        <dd>{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Leave balance, and the leave requests and regularisations of this month, its financial year and till date; a past
             month as on its last day. --}}
        @if ($person === null)
            {{-- HR without an employee record of their own: the search box above opens anyone in their scope. --}}
            <section class="pos-card pos-cal-card" aria-labelledby="pos-cal-find">
                <h3 id="pos-cal-find" class="pos-cal-card-title">Find an employee</h3>
                <p class="pos-cal-empty">Search by employee ID or name above to see their attendance, leave balance, leave requests and regularisations the way they see them, and to regularise or apply leave for them.</p>
            </section>
        @elseif ($ov = $this->overview())
            @php($available = array_sum(array_column($ov['balances'], 'available')))
            <div class="pos-cal-overview">
                <section class="pos-card pos-cal-card" aria-labelledby="pos-cal-balance">
                    <header class="pos-cal-card-head">
                        <div>
                            <h3 id="pos-cal-balance" class="pos-cal-card-title">Leave balance</h3>
                            <p class="pos-cal-asof">As on {{ $ov['balances_on'] ? $ov['balances_on']->format('j M Y') : 'today' }}</p>
                        </div>
                        @if ($ov['balances_on'] === null && $available > 0 && $this->applyLeaveAction->isVisible())
                            <x-filament::button class="pos-cal-noprint" size="sm" icon="heroicon-m-calendar-days" wire:click="mountAction('applyLeave')">Apply leave</x-filament::button>
                        @endif
                    </header>
                    @if ($ov['balances'] === [])
                        <p class="pos-cal-empty">No paid leave is set up for {{ $own ? 'you' : 'this employee' }} yet.</p>
                    @else
                        <ul class="pos-cal-balances">
                            @foreach ($ov['balances'] as $balance)
                                <li @class(['is-empty' => $balance['available'] <= 0])>
                                    <span class="pos-cal-bname"><strong>{{ $balance['name'] }}</strong><span>{{ $balance['code'] }}@if ($balance['used'] > 0) · {{ rtrim(rtrim(number_format($balance['used'], 2), '0'), '.') }} used @endif @if ($balance['pending'] > 0) · {{ rtrim(rtrim(number_format($balance['pending'], 2), '0'), '.') }} pending @endif</span></span>
                                    <span class="pos-cal-bvalue"><strong>{{ rtrim(rtrim(number_format($balance['available'], 2), '0'), '.') ?: '0' }}</strong><span>{{ $balance['available'] == 1 ? 'day' : 'days' }} left</span></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @foreach (['leave' => ['Leave requests', ['applied' => 'Applied', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected', 'withdrawn' => 'Cancelled', 'days' => 'Days on leave']],
                    'regularisation' => ['Regularisations', ['applied' => 'Asked', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn']]] as $kind => [$title, $rows])
                    <section class="pos-card pos-cal-card" aria-labelledby="pos-cal-{{ $kind }}">
                        <h3 id="pos-cal-{{ $kind }}" class="pos-cal-card-title">{{ $title }}</h3>
                        <table class="pos-cal-table">
                            <thead>
                                <tr>
                                    <th scope="col"><span class="sr-only">Count</span></th>
                                    @foreach ($ov['periods'] as $period => $label)
                                        <th scope="col" @class(['is-month' => $period === 'month'])>{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $key => $label)
                                    {{-- Cancelled / withdrawn only when there were any --}}
                                    @continue($key === 'withdrawn' && collect($ov[$kind])->sum('withdrawn') === 0)
                                    <tr data-row="{{ $key }}">
                                        <th scope="row">{{ $label }}</th>
                                        @foreach (array_keys($ov['periods']) as $period)
                                            <td @class(['is-month' => $period === 'month'])>{{ rtrim(rtrim(number_format((float) $ov[$kind][$period][$key], 2), '0'), '.') ?: '0' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endforeach
            </div>
        @endif

        <section class="pos-card pos-cal-card" aria-labelledby="pos-cal-holidays">
            <h3 id="pos-cal-holidays" class="pos-cal-card-title">Holidays this month</h3>
            @if ($cal['holidays'] === [])
                <p class="pos-cal-empty">No holidays on the calendar this month.</p>
            @else
                <ul class="pos-cal-holidays">
                    @foreach ($cal['holidays'] as $holiday)
                        <li data-tone="{{ $holiday['off'] ? 'holiday' : 'optional' }}">
                            <span class="pos-cal-hdate"><span>{{ $holiday['date']->format('M') }}</span><strong>{{ $holiday['date']->format('d') }}</strong></span>
                            <span class="pos-cal-hname"><strong>{{ $holiday['name'] }}</strong><span>{{ $holiday['date']->format('l') }}</span></span>
                            <span class="pos-cal-hkind">{{ $holiday['kind'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-panels::page>
