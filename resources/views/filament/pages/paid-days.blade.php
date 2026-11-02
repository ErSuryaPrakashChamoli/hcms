<x-filament-panels::page>
    {{-- HROne parity TX-4: Paid days — a company's people for an attendance month or the financial year up to it. --}}
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
        <label class="flex flex-col gap-1">Show
            <select wire:model.live="period" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                <option value="month">The month</option>
                <option value="year">The financial year up to the month</option>
            </select>
        </label>
    </div>

    @if ($this->companyId() === null)
        <p class="text-sm text-gray-500">Add a company first.</p>
    @else
        @unless ($this->adjustmentsOn())
            <p class="text-xs text-gray-500">Paid days are what attendance processing gives; loss of pay is adjusted in payroll. HR adjusts paid days here once "HR may adjust paid days" is switched on (Setup → Time office).</p>
        @endunless

        {{ $this->table }}

        @php($breakdown = $this->breakdown())
        @if ($breakdown)
            @php($employee = $breakdown['employee'])
            <x-filament::section :heading="'Breakdown · '.$employee->employee_code.' · '.($employee->person?->display_name ?? '')"
                :description="$breakdown['year_to_date'] ? $breakdown['year_label'].': each month up to '.$breakdown['label'].', then '.$breakdown['label'].' in detail.' : $breakdown['label'].' in detail: what makes the paid days, and why they are lower.'">
                <div class="space-y-4 text-sm" data-breakdown="{{ $employee->id }}">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-x-mark" wire:click="closeDetail">Close</x-filament::button>
                    </div>

                    {{-- Month by month (HROne's details flyer). --}}
                    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Paid days by month">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500">
                                    <th class="py-1 pe-3">Month</th>
                                    @foreach (\App\Domain\Attendance\Services\PaidDaysRegister::COLUMNS as $key => $label)
                                        @if ($this->adjustmentsOn() || ! in_array($key, ['adjustment', 'paid_days_processed'], true))<th class="py-1 pe-3">{{ $label }}</th>@endif
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($breakdown['months'] as $row)
                                    <tr class="border-t border-gray-200 dark:border-gray-700" data-month="{{ $row['key'] }}">
                                        <td class="py-1 pe-3">{{ $row['label'] }}</td>
                                        @foreach (\App\Domain\Attendance\Services\PaidDaysRegister::COLUMNS as $key => $label)
                                            @if ($this->adjustmentsOn() || ! in_array($key, ['adjustment', 'paid_days_processed'], true))<td class="py-1 pe-3">{{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $row[$key]) }}</td>@endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($breakdown['month'])
                        @php($m = $breakdown['month'])
                        <div class="grid gap-3 md:grid-cols-3">
                            <div>
                                <p class="font-medium">Paid days · {{ $breakdown['label'] }}</p>
                                <p>As processed {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number($m['paid_days_processed']) }}@if ($m['adjusted']) · adjusted {{ ($m['adjustment'] > 0 ? '+' : '').\App\Domain\Attendance\Models\PaidDayAdjustment::number($m['adjustment']) }}@endif</p>
                                <p>Penalty days {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number($m['penalty_days']) }} · paid days <span class="font-semibold">{{ \App\Domain\Attendance\Models\PaidDayAdjustment::number($m['paid_days']) }}</span></p>
                                <p class="text-xs text-gray-500">{{ $m['days'] }} day(s) processed · arrear days {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number($m['arrear_days']) }}@if ($m['unpaid_weekly_off'] > 0) · unpaid weekly offs {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number($m['unpaid_weekly_off']) }}@endif</p>
                            </div>
                            <div>
                                <p class="font-medium">Hours by kind of day</p>
                                <p>Working days {{ $m['working_hours'] }} h · weekly offs {{ $m['weekly_off_hours'] }} h</p>
                                <p>Holidays {{ $m['holiday_hours'] }} h · overtime (approved) {{ $m['overtime_hours'] }} h</p>
                            </div>
                            <div>
                                <p class="font-medium">Late-mark penalties</p>
                                @forelse ($breakdown['penalties'] as $penalty)
                                    <p>{{ \Illuminate\Support\Carbon::parse($penalty['date'])->format('j M') }} · {{ $penalty['late_marks'] }} late marks · {{ $penalty['action'] }} · {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number($penalty['days']) }} day(s) · {{ $penalty['status'] }}@if ($this->canOpenDays()) · <button type="button" class="text-xs text-primary-600 hover:underline" data-open-day="{{ $penalty['date'] }}" wire:click="$dispatch('pos-day-drawer-open', { employee: {{ (int) $employee->id }}, date: '{{ \Illuminate\Support\Carbon::parse($penalty['date'])->toDateString() }}' })">Change</button>@endif</p>
                                @empty
                                    <p class="text-gray-500">None.</p>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    <div>
                        <p class="font-medium">Arrear days · corrections to days of {{ $breakdown['label'] }} after they were locked</p>
                        @forelse ($breakdown['arrears'] as $arrear)
                            <p>{{ \Illuminate\Support\Carbon::parse($arrear['date'])->format('j M Y') }} · {{ $arrear['before'] }} → {{ $arrear['after'] }} · {{ ($arrear['days'] > 0 ? '+' : '').\App\Domain\Attendance\Models\PaidDayAdjustment::number($arrear['days']) }} · {{ $arrear['status'] }}@if ($this->canOpenDays()) · <button type="button" class="text-xs text-primary-600 hover:underline" data-open-day="{{ $arrear['date'] }}" wire:click="$dispatch('pos-day-drawer-open', { employee: {{ (int) $employee->id }}, date: '{{ \Illuminate\Support\Carbon::parse($arrear['date'])->toDateString() }}' })">Change</button>@endif</p>
                        @empty
                            <p class="text-gray-500">None.</p>
                        @endforelse
                    </div>

                    <div>
                        <p class="font-medium">Paid-day adjustments · {{ $breakdown['label'] }}</p>
                        @forelse ($breakdown['adjustments'] as $adjustment)
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-2" data-adjustment="{{ $adjustment->id }}" data-adjustment-status="{{ $adjustment->status }}">
                                <div>
                                    <p>{{ $adjustment->describe() }} · {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $adjustment->paid_days_before) }} → {{ \App\Domain\Attendance\Models\PaidDayAdjustment::number((float) $adjustment->paid_days_after) }} when saved · {{ \App\Domain\Attendance\Models\PaidDayAdjustment::STATUSES[$adjustment->status] ?? $adjustment->status }}</p>
                                    <p class="text-xs text-gray-500">{{ $adjustment->reason }} · {{ $adjustment->creator?->name }} · {{ $adjustment->created_at?->format('j M Y, H:i') }}</p>
                                    @if (! $adjustment->isActive())<p class="text-xs text-gray-500">Reversed{{ $adjustment->reverser ? ' by '.$adjustment->reverser->name : '' }}{{ $adjustment->reversed_at ? ' on '.$adjustment->reversed_at->format('j M Y') : '' }}: {{ $adjustment->reversal_reason }}</p>@endif
                                </div>
                                @if ($adjustment->isActive() && $this->canAdjust() && $breakdown['refusal'] === null)
                                    {{ ($this->reverseAdjustmentAction)(['adjustment' => $adjustment->id]) }}
                                @endif
                            </div>
                        @empty
                            <p class="text-gray-500">None.{{ $this->adjustmentsOn() ? '' : ' (Paid-day adjustments are switched off.)' }}</p>
                        @endforelse
                        @if ($this->canAdjust() && $breakdown['refusal'] !== null)<p class="mt-1 text-xs text-warning-600">{{ $breakdown['refusal'] }}</p>@endif
                    </div>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
