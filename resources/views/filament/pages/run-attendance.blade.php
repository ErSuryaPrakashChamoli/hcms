<x-filament-panels::page>
    {{-- HROne parity TX-3: Run attendance — a company's attendance month in HROne's steps. Figures load after the page. --}}
    <div class="grid gap-3 text-sm md:grid-cols-3">
        <label class="flex flex-col gap-1">Company
            <select wire:model.live="company" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                @foreach ($this->companies() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1">Month
            <select wire:model.live="month" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                @foreach ($this->monthOptions() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        </label>
    </div>

    <div wire:init="load" @if ($this->hasRunningRun()) wire:poll.5s @endif class="space-y-4">
        @php($desk = $this->overview())
        @if ($desk === null)
            <p class="text-sm text-gray-500" wire:loading.remove>{{ $this->companyId() === null ? 'Add a company first.' : 'Loading the month…' }}</p>
        @elseif (isset($desk['error']))
            <p class="text-sm text-danger-600">{{ $desk['error'] }}</p>
        @else
            {{-- The month and, with month close in use, its state. --}}
            <x-filament::section :heading="$desk['label'].' · '.$desk['start']->format('j M').' – '.$desk['end']->format('j M Y')"
                :description="$desk['close']['enabled'] ? 'Close the month when it is ready: its days then refuse requests and corrections, and payroll reads a closed month.' : 'Attendance months are not closed in this organisation: days lock when payroll is finalized.'">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <div>
                        @if ($desk['close']['enabled'])
                            @if ($desk['close']['status'] === 'closed')
                                <p class="font-semibold text-success-600" data-month-state="closed">Closed{{ $desk['close']['closed_by'] ? ' by '.$desk['close']['closed_by'] : '' }}{{ $desk['close']['closed_at'] ? ' on '.$desk['close']['closed_at']->format('j M Y, H:i') : '' }}</p>
                                @if ($desk['close']['note'])<p class="text-xs text-gray-500">{{ $desk['close']['note'] }}</p>@endif
                                @if ($desk['close']['window_changed'])<p class="text-xs text-warning-600">The pay cycle has changed since the month was closed: the closed days are the dates it had then.</p>@endif
                            @else
                                <p class="font-semibold text-warning-600" data-month-state="open">Open</p>
                                @if ($desk['close']['reopened_at'])<p class="text-xs text-gray-500">Reopened{{ $desk['close']['reopened_by'] ? ' by '.$desk['close']['reopened_by'] : '' }} on {{ $desk['close']['reopened_at']->format('j M Y') }}: {{ $desk['close']['reopen_reason'] }}</p>@endif
                                @foreach ($desk['close']['blockers'] as $blocker)<p class="text-xs text-gray-500">{{ $blocker }}</p>@endforeach
                            @endif
                            @if ($desk['close']['final'])<p class="text-xs text-gray-500">A finalized payroll covers this month: it can no longer be reopened.</p>@endif
                        @else
                            <p class="text-gray-500">{{ $desk['ended'] ? 'The month has ended.' : ($desk['started'] ? 'The month is running.' : 'The month has not started yet.') }}</p>
                        @endif
                    </div>
                    @if ($desk['close']['enabled'] && $this->canProcess())
                        <div>
                            @if ($desk['close']['status'] === 'closed')
                                @unless ($desk['close']['final']){{ $this->reopenMonthAction }}@endunless
                            @else
                                {{ $this->closeMonthAction }}
                            @endif
                        </div>
                    @endif
                </div>
            </x-filament::section>

            {{-- "Please review following errors" and "Reminders". --}}
            <x-filament::section heading="Before you close the month" description="Errors to review and requests still waiting on the month's days. Open a card for its list and CSV.">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach (\App\Domain\Attendance\Services\AttendanceMonthDesk::READINESS as $key)
                        @php($count = $desk['readiness'][$key])
                        <button type="button" wire:click="toggleList('{{ $key }}')" class="rounded-lg border border-gray-200 p-3 text-start dark:border-gray-700 {{ $openList === $key ? 'ring-1' : '' }}" aria-pressed="{{ $openList === $key ? 'true' : 'false' }}" data-list="{{ $key }}">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$key][0] }}</span>
                                <span class="tabular-nums font-semibold {{ $count > 0 ? 'text-warning-600' : 'text-gray-500' }}">{{ $count }}</span>
                            </span>
                            <span class="mt-2 block text-xs text-gray-500">{{ \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$key][1] }}</span>
                        </button>
                    @endforeach
                    @foreach (\App\Domain\Attendance\Services\AttendanceMonthPopulation::PENDING_KINDS as $kind => $label)
                        @php($key = 'pending_'.$kind)
                        @php($count = $desk['pending'][$kind] ?? 0)
                        <button type="button" wire:click="toggleList('{{ $key }}')" class="rounded-lg border border-gray-200 p-3 text-start dark:border-gray-700 {{ $openList === $key ? 'ring-1' : '' }}" aria-pressed="{{ $openList === $key ? 'true' : 'false' }}" data-list="{{ $key }}">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$key][0] }}</span>
                                <span class="tabular-nums font-semibold {{ $count > 0 ? 'text-warning-600' : 'text-gray-500' }}">{{ $count }}</span>
                            </span>
                            <span class="mt-2 block text-xs text-gray-500">Reminder · {{ \App\Domain\Attendance\Services\AttendanceMonthDesk::LISTS[$key][1] }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($openList !== null && $openList !== 'unprocessed')
                    @include('filament.pages.partials.run-attendance-list')
                @endif
            </x-filament::section>

            {{-- Step 1 --}}
            @if ($this->canProcess())
                <x-filament::section heading="1 · Attendance pre-verification (optional)" description="Absent and half days of the month: review them, mark days present or apply leave below.">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <p><span class="font-semibold tabular-nums">{{ $desk['absences']['absent_days'] }}</span> absent day(s) · <span class="font-semibold tabular-nums">{{ $desk['absences']['half_days'] }}</span> half day(s) · {{ $desk['absences']['people'] }} employee(s)</p>
                        @if ($desk['absences']['absent_days'] + $desk['absences']['half_days'] > 0)
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadList('absences')">Download</x-filament::button>
                        @endif
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>

    @if ($this->canProcess() && $this->companyId() !== null)
        {{ $this->table }}
    @endif

    @if ($desk !== null && ! isset($desk['error']))
        {{-- Step 2 --}}
        @if ($this->canWorkArrears())
            <x-filament::section heading="2 · Attendance correction arrears" description="Corrections to days of earlier months (locked or closed) that this month takes, by the month they correct. Hold a month to leave its arrears for later; release it to take them again. Arrears are recorded automatically; settled ones never change.">
                @php($pendingRows = collect($desk['arrears'])->sum('pending'))
                @php($heldRows = collect($desk['arrears'])->sum('held'))
                <p class="text-sm"><span class="font-semibold tabular-nums">{{ $pendingRows }}</span> ready to take · <span class="font-semibold tabular-nums">{{ $heldRows }}</span> held · {{ collect($desk['arrears'])->sum('people') }} employee(s)</p>
                @if ($desk['arrears'] === [])
                    <p class="mt-2 text-sm text-gray-500">No arrears waiting for this month.</p>
                @else
                    <div class="mt-2 overflow-x-auto" tabindex="0" role="region" aria-label="Arrears by month corrected">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left text-gray-500"><th class="py-1 pe-3">Arrear for month</th><th class="py-1 pe-3">Pending (days)</th><th class="py-1 pe-3">Held (days)</th><th class="py-1 pe-3">Employees</th><th class="py-1 pe-3"></th></tr></thead>
                            <tbody>
                                @foreach ($desk['arrears'] as $group)
                                    <tr class="border-t border-gray-200 dark:border-gray-700" data-arrear-month="{{ $group['key'] }}">
                                        <td class="py-1 pe-3">{{ $group['label'] }}</td>
                                        <td class="py-1 pe-3 tabular-nums">{{ $group['pending'] }} ({{ sprintf('%+.2f', $group['pending_days']) }})</td>
                                        <td class="py-1 pe-3 tabular-nums">{{ $group['held'] }} ({{ sprintf('%+.2f', $group['held_days']) }})</td>
                                        <td class="py-1 pe-3 tabular-nums">{{ $group['people'] }}</td>
                                        <td class="py-1 pe-3">
                                            @if ($group['pending'] > 0){{ ($this->holdArrearsAction)(['month' => $group['key']]) }}@endif
                                            @if ($group['held'] > 0){{ ($this->releaseArrearsAction)(['month' => $group['key']]) }}@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="mt-2 flex flex-wrap gap-4 text-sm">
                    <x-filament::link :href="$this->arrearsUrl('pending')" size="sm">Review pending arrears</x-filament::link>
                    <x-filament::link :href="$this->arrearsUrl('held')" size="sm">Review held arrears</x-filament::link>
                </div>
            </x-filament::section>
        @endif

        {{-- Step 3 --}}
        @if ($this->canProcess())
            <x-filament::section heading="3 · Process attendance" description="Calculate the month so far for everyone or the people you choose, in the background. Days locked by payroll or in a closed month keep their values.">
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <p><span class="font-semibold tabular-nums">{{ $desk['processing']['complete'] }}</span> fully processed · <button type="button" class="font-semibold tabular-nums text-warning-600" wire:click="toggleList('unprocessed')">{{ $desk['processing']['incomplete'] }}</button> not yet ({{ $desk['processing']['missing_days'] }} day(s)) · {{ $desk['processing']['total'] }} employee(s) in the month</p>
                    @if ($desk['close']['status'] !== 'closed' && $desk['started'])
                        {{ $this->processMonthAction }}
                    @endif
                </div>
                @if ($openList === 'unprocessed')
                    @include('filament.pages.partials.run-attendance-list')
                @endif
                @foreach ($desk['processing']['runs'] as $run)
                    <div class="mt-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700" data-run="{{ $run->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ $run->status === 'running' ? 'Processing…' : 'Processed' }} · {{ $run->done }} of {{ $run->total }}{{ $run->failed > 0 ? ' · '.$run->failed.' failed' : '' }}</span>
                            <span class="text-xs text-gray-500">Started {{ $run->created_at?->format('j M, H:i') }}{{ $run->starter ? ' by '.$run->starter->name : '' }}{{ $run->finished_at ? ' · finished '.$run->finished_at->format('j M, H:i') : '' }}</span>
                        </div>
                        <div class="mt-2 h-2 w-full rounded-full bg-gray-200 dark:bg-white/5" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $run->progress() }}" aria-label="Progress">
                            <div class="h-2 rounded-full bg-primary-500" style="width: {{ $run->progress() }}%"></div>
                        </div>
                        @if ($run->filters)<p class="mt-2 text-xs text-gray-500">{{ implode(' · ', $run->filters) }}</p>@endif
                    </div>
                @endforeach
            </x-filament::section>

            {{-- Review one employee --}}
            <x-filament::section heading="Review an employee's month" description="Totals, requests still waiting and arrears for one employee; process just their month.">
                <label class="flex flex-col gap-1 text-sm">Employee
                    <select wire:model.live="reviewEmployee" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                        <option value="">Choose…</option>
                        @foreach ($this->employeeOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
                @php($review = $this->review())
                @if ($review)
                    <div class="mt-3 grid gap-3 text-sm md:grid-cols-3">
                        <div>
                            <p class="font-medium">Attendance</p>
                            @if ($review['summary'])
                                <p>{{ $review['summary']['days'] }} day(s) processed · {{ $review['summary']['unprocessed_days'] }} not yet</p>
                                <p>Paid days {{ $review['summary']['paid_days'] }} · absent {{ $review['summary']['absent_days'] }} · half days {{ $review['summary']['half_days'] }}</p>
                                <p>Penalty days {{ $review['summary']['penalty_days'] }}</p>
                            @else
                                <p class="text-gray-500">The month has not started.</p>
                            @endif
                            @if ($review['closed_days'] > 0)<p class="text-xs text-gray-500">{{ $review['closed_days'] }} day(s) in a closed month.</p>@endif
                        </div>
                        <div>
                            <p class="font-medium">Waiting for a decision</p>
                            @forelse ($review['pending'] as $item)
                                <p>{{ $item['what'] }} · {{ $item['dates'] }} · {{ $item['status'] }}</p>
                            @empty
                                <p class="text-gray-500">Nothing waiting.</p>
                            @endforelse
                        </div>
                        <div>
                            <p class="font-medium">Arrears</p>
                            @forelse ($review['arrears'] as $arrear)
                                <p>{{ $arrear->date->format('j M Y') }} · {{ sprintf('%+.2f', (float) $arrear->delta_days) }} · {{ \App\Domain\Attendance\Models\AttendanceArrear::STATUSES[$arrear->status] ?? $arrear->status }}</p>
                            @empty
                                <p class="text-gray-500">None pending or held.</p>
                            @endforelse
                        </div>
                    </div>
                    @if ($review['month'])
                        {{-- HROne parity TX-4: the review's "Summary" — hours by kind of day, arrear days and the paid days. --}}
                        @php($m = $review['month'])
                        <div class="mt-3 grid gap-3 text-sm md:grid-cols-3" data-review-summary>
                            <div>
                                <p class="font-medium">Summary</p>
                                <p>Present {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['present']) }} · weekly offs {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['weekly_off']) }} · holidays {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['holiday']) }} · paid leave {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['paid_leave']) }}</p>
                                <p>Paid days as processed {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['paid_days_processed']) }}@if ($m['adjusted']) · adjusted {{ ($m['adjustment'] > 0 ? '+' : '').\App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['adjustment']) }}@endif · effective paid days {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['paid_days']) }}</p>
                            </div>
                            <div>
                                <p class="font-medium">Hours</p>
                                <p>Working days {{ $m['working_hours'] }} h · weekly offs {{ $m['weekly_off_hours'] }} h · holidays {{ $m['holiday_hours'] }} h</p>
                                <p>Overtime (approved) {{ $m['overtime_hours'] }} h</p>
                            </div>
                            <div>
                                <p class="font-medium">Arrear days</p>
                                <p>{{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['arrear_days']) }} (corrections to this month's locked days) · penalty days {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $m['penalty_days']) }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="mt-3 flex flex-wrap items-center gap-4">
                        @if ($desk['close']['status'] !== 'closed' && $desk['started'])
                            <x-filament::button size="sm" icon="heroicon-o-arrow-path" wire:click="processEmployee">Process this employee's month</x-filament::button>
                        @endif
                        <x-filament::link :href="$review['calendar']" size="sm">Open the calendar</x-filament::link>
                        @if (\App\Filament\Pages\PaidDays::canAccess())<x-filament::link :href="$review['paid_days']" size="sm">Paid days breakdown</x-filament::link>@endif
                    </div>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
