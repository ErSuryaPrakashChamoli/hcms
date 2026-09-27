<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section compact><div class="text-sm text-gray-500">Employees</div><div class="text-2xl font-semibold">{{ $s['headcount'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Present</div><div class="text-2xl font-semibold text-success-600">{{ $s['present'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">On leave</div><div class="text-2xl font-semibold text-info-600">{{ $s['leave'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Absent</div><div class="text-2xl font-semibold {{ $s['absent'] > 0 ? 'text-danger-600' : '' }}">{{ $s['absent'] }}</div></x-filament::section>
    </div>
    <div class="grid gap-4 md:grid-cols-5">
        <x-filament::section compact><div class="text-sm text-gray-500">Late today</div><div class="text-2xl font-semibold">{{ $s['late'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Missing punch</div><div class="text-2xl font-semibold {{ $s['missing_punch'] > 0 ? 'text-warning-600' : '' }}">{{ $s['missing_punch'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">WFH / on duty / field</div><div class="text-2xl font-semibold">{{ $s['remote'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Regularisations pending</div><div class="text-2xl font-semibold">{{ $s['regularisations_pending'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Overtime pending</div><div class="text-2xl font-semibold">{{ $s['overtime_pending'] }}</div></x-filament::section>
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
