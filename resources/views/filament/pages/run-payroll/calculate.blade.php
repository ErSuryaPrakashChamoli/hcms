{{-- Step 4 · Run payroll: what still stops the run (with a jump to where it is fixed), or the calculated totals. --}}
@if (! $run)
    <x-pos.step-wait icon="heroicon-o-play-circle" :title="$this->monthLabel().' has not started'" text="Start the month to create its payroll run.">
        @if ($this->startRunAction->isVisible()) {{ $this->startRunAction }} @endif
    </x-pos.step-wait>
@else
    @php
        $blockers = $run->isEditable() ? $this->blockers() : [];
        $attendanceBlocks = collect($blockers)->contains(fn (string $r) => str_starts_with($r, 'Attendance first'));
        $checksBlock = collect($blockers)->contains(fn (string $r) => ! str_starts_with($r, 'Attendance first'));
        $totals = $run->totals ?? [];
        $money = fn ($v) => number_format((float) $v, 2);
    @endphp

    @if ($blockers !== [])
        <x-pos.step-banner tone="warning" :title="count($blockers).' '.\Illuminate\Support\Str::plural('thing', count($blockers)).' before the run can start'">
            <ul class="pos-step-reasons">
                @foreach ($blockers as $reason)
                    @php
                        [$what, $why] = str_contains($reason, ': ') ? explode(': ', $reason, 2) : [$reason, ''];
                    @endphp
                    <li><strong>{{ $what }}</strong>@if ($why) {{ $why }}@endif</li>
                @endforeach
            </ul>
            <x-slot:aside>
                @if ($attendanceBlocks)
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-left" wire:click="goTo('attendance')">Attendance</x-filament::button>
                @endif
                @if ($checksBlock)
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-left" wire:click="goTo('checks')">Pre-payroll checks</x-filament::button>
                @endif
            </x-slot:aside>
        </x-pos.step-banner>
    @elseif ($run->calculated_at)
        <x-pos.step-banner tone="clear" icon="heroicon-o-calculator" :title="'Calculated '.$run->calculated_at->format('j M Y, H:i')" sub="Look over the result, then review and approve it." />
    @else
        <x-pos.step-banner tone="idle" icon="heroicon-o-calculator" title="Ready to calculate" sub="Every employee of the month is calculated. Large months can run in the background." />
    @endif

    @if ($run->calculated_at)
        <dl class="pos-step-stats">
            <div class="pos-step-stat" data-tone="idle"><dt>Employees</dt><dd>{{ number_format((int) ($totals['employees'] ?? 0)) }}</dd></div>
            <div class="pos-step-stat" data-tone="idle"><dt>Gross</dt><dd>{{ $money($totals['gross'] ?? 0) }}</dd></div>
            <div class="pos-step-stat" data-tone="idle"><dt>Deductions</dt><dd>{{ $money($totals['deductions'] ?? 0) }}</dd></div>
            <div class="pos-step-stat is-key"><dt>Net pay</dt><dd>{{ $money($totals['net'] ?? 0) }}</dd></div>
            <div class="pos-step-stat" data-tone="idle"><dt>Employer cost</dt><dd>{{ $money($totals['employer_cost'] ?? 0) }}</dd></div>
            <div class="pos-step-stat" data-tone="{{ (int) $run->exception_count > 0 ? 'warning' : 'clear' }}"><dt>With exceptions</dt><dd>{{ number_format((int) $run->exception_count) }}</dd></div>
        </dl>
    @endif

    <div class="pos-step-actions">
        @if ($this->calculateAction->isVisible()) {{ $this->calculateAction }} @endif
        @if ($this->calculateInBackgroundAction->isVisible()) {{ $this->calculateInBackgroundAction }} @endif
        @if ($this->paymentDateAction->isVisible()) {{ $this->paymentDateAction }} @endif
        @if ($url = $this->runUrl())
            <a href="{{ $url }}" wire:navigate class="pos-step-link">Review each employee on the run<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
        @endif
    </div>
@endif
