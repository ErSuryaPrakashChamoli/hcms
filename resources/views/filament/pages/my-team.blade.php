<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['headcount'] }}</span><span class="pos-figure-label">Employees</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="good">{{ $s['present'] }}</span><span class="pos-figure-label">Present</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="info">{{ $s['leave'] }}</span><span class="pos-figure-label">On leave</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $s['absent'] > 0 ? 'bad' : '' }}">{{ $s['absent'] }}</span><span class="pos-figure-label">Absent</span></div>
    </div>
    <div class="pos-panel pos-panel-pad pos-figures">
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['late'] }}</span><span class="pos-figure-label">Late today</span></div>
        <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ $s['missing_punch'] > 0 ? 'warning' : '' }}">{{ $s['missing_punch'] }}</span><span class="pos-figure-label">Missing punch</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['remote'] }}</span><span class="pos-figure-label">WFH / on duty / field</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['regularisations_pending'] }}</span><span class="pos-figure-label">Regularisations pending</span></div>
        <div class="pos-figure"><span class="pos-figure-value">{{ $s['overtime_pending'] }}</span><span class="pos-figure-label">Overtime pending</span></div>
    </div>

    <x-filament::section heading="Needs attention">
        @forelse ($this->getNeedsAttention() as $item)
            <div class="flex items-center justify-between py-2 border-b border-gray-100 dark:border-gray-800 last:border-0">
                <div>
                    <x-filament::badge :color="$item['severity'] === 'danger' ? 'danger' : ($item['severity'] === 'warning' ? 'warning' : 'info')">{{ $item['count'] }}</x-filament::badge>
                    <span class="ml-2 font-medium">{{ $item['title'] }}</span>
                    <span class="ml-2 text-sm text-gray-500">{{ $item['detail'] }}</span>
                </div>
                @if ($item['url'])
                    <x-filament::link :href="$item['url']" size="sm">Go</x-filament::link>
                @endif
            </div>
        @empty
            <div class="text-sm text-gray-500">Nothing waiting on you.</div>
        @endforelse
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
