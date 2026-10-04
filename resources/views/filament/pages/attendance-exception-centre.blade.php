<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['exceptions'] }}</span><span class="pos-figure-label">Exceptions (30d)</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $s['missing_punch'] > 0 ? 'warning' : '' }}">{{ $s['missing_punch'] }}</span><span class="pos-figure-label">Missing punch</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['late'] }}</span><span class="pos-figure-label">Late</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $s['absent'] > 0 ? 'bad' : '' }}">{{ $s['absent'] }}</span><span class="pos-figure-label">Absent</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['overtime_pending'] }}</span><span class="pos-figure-label">OT pending</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['regularisations_pending'] }}</span><span class="pos-figure-label">Regularisations</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $s['failed_punches'] > 0 ? 'bad' : '' }}">{{ $s['failed_punches'] }}</span><span class="pos-figure-label">Failed punches</span></div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
