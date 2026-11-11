{{-- Step 2 · Attendance: where the month's attendance stands (one banner), four tiles, then what can be done about it. --}}
@php
    $a = $this->attendance();
@endphp
@if ($a === null)
    <div class="pos-step-loading" role="status"><x-filament::loading-indicator class="pos-step-loading-icon" /> Checking attendance…</div>
@elseif (isset($a['error']))
    <x-pos.step-banner tone="blocked" title="Attendance could not be read" :sub="$a['error']" />
@else
    @php
        $required = $this->attendanceRequired();
        $processed = $a['employees'] - $a['incomplete'];
        $pct = $a['employees'] > 0 ? (int) round($processed * 100 / $a['employees']) : 100;
        $closeLine = 'Attendance month close: '.($a['close_enabled'] ? ($a['closed'] ? 'closed' : 'open') : 'not in use').'.';
        [$tone, $title] = match (true) {
            $a['done'] && $a['employees'] === 0 => ['idle', 'No one to process in '.$this->monthLabel()],
            $a['done'] => ['clear', 'Attendance is ready for payroll'],
            $a['running'] => ['pending', 'Attendance is being processed'],
            default => [$required ? 'warning' : 'pending', 'Attendance needs a look'],
        };
    @endphp

    <x-pos.step-banner :tone="$tone" :title="$title" :sub="$closeLine" :icon="$a['employees'] === 0 ? 'heroicon-o-user-group' : null">
        @unless ($a['done'])
            <ul class="pos-step-reasons">
                @foreach ($a['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
            </ul>
            @unless ($required)
                <p class="pos-step-note">This organisation runs payroll without waiting for attendance (Setup → Payroll); the run reads what is processed.</p>
            @endunless
        @endunless
    </x-pos.step-banner>

    <dl class="pos-step-stats">
        <div class="pos-step-stat" data-tone="idle">
            <dt>People in the month</dt><dd>{{ number_format($a['employees']) }}</dd>
        </div>
        <div class="pos-step-stat" data-tone="{{ $a['incomplete'] > 0 ? 'warning' : 'clear' }}">
            <dt>Fully processed</dt><dd>{{ number_format($processed) }}</dd>
            <span class="pos-step-meter" style="--pct: {{ $pct }}" aria-hidden="true"><span></span></span>
        </div>
        <div class="pos-step-stat" data-tone="{{ $a['missing_days'] > 0 ? 'warning' : 'clear' }}">
            <dt>Days without attendance</dt><dd>{{ number_format($a['missing_days']) }}</dd>
        </div>
        <div class="pos-step-stat" data-tone="{{ $a['pending'] > 0 ? 'warning' : 'clear' }}">
            <dt>Requests still pending</dt><dd>{{ number_format($a['pending']) }}</dd>
        </div>
    </dl>

    <div class="pos-step-actions">
        @if ($this->processAttendanceAction->isVisible()) {{ $this->processAttendanceAction }} @endif
        @if ($a['close_enabled'] && ! $a['closed'])
            @if ($this->closeAttendanceAction->isVisible()) {{ $this->closeAttendanceAction }} @endif
        @endif
        @if ($url = $this->attendanceUrl())
            <a href="{{ $url }}" wire:navigate class="pos-step-link">Open Run attendance<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
        @endif
    </div>
@endif
