<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="grid gap-4 md:grid-cols-4 lg:grid-cols-7">
        <x-filament::section compact><div class="text-sm text-gray-500">Exceptions (30d)</div><div class="text-2xl font-semibold">{{ $s['exceptions'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Missing punch</div><div class="text-2xl font-semibold {{ $s['missing_punch'] > 0 ? 'text-warning-600' : '' }}">{{ $s['missing_punch'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Late</div><div class="text-2xl font-semibold">{{ $s['late'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Absent</div><div class="text-2xl font-semibold {{ $s['absent'] > 0 ? 'text-danger-600' : '' }}">{{ $s['absent'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">OT pending</div><div class="text-2xl font-semibold">{{ $s['overtime_pending'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Regularisations</div><div class="text-2xl font-semibold">{{ $s['regularisations_pending'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-sm text-gray-500">Failed punches</div><div class="text-2xl font-semibold {{ $s['failed_punches'] > 0 ? 'text-danger-600' : '' }}">{{ $s['failed_punches'] }}</div></x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
