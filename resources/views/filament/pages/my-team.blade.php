<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="grid gap-4 md:grid-cols-4">
        <x-filament::section compact><div class="text-sm text-gray-500">Employees</div><div class="text-2xl font-semibold">{{ $s['headcount'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Present</div><div class="text-2xl font-semibold text-success-600">{{ $s['present'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">On leave</div><div class="text-2xl font-semibold text-info-600">{{ $s['leave'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Absent</div><div class="text-2xl font-semibold {{ $s['absent'] > 0 ? 'text-danger-600' : '' }}">{{ $s['absent'] }}</div></x-filament::section>
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
