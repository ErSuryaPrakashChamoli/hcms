<x-filament-panels::page>
    @php($report = $this->getReport())
    @php($s = $report['summary'])
    <x-filament::section>
        <div class="space-y-1 text-sm">
            <div><strong>Configuration:</strong> {{ $s['configuration'] }} · {{ $s['warnings'] }} warning(s)</div>
            <div><strong>Statutory production gate:</strong>
                <x-filament::badge :color="$s['statutory']['blocked'] ? 'danger' : 'success'" size="sm">{{ $s['statutory']['blocked'] ? 'BLOCKED' : 'OPEN' }}</x-filament::badge>
                {{ $s['statutory']['rules'] }} rules · {{ $s['statutory']['verified'] }} verified · {{ $s['statutory']['open_notices'] }} open notices
            </div>
            <div class="text-danger-600 dark:text-danger-400"><strong>Production readiness:</strong> {{ $s['verdict'] }}</div>
        </div>
    </x-filament::section>
    <x-filament::section heading="Checks">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Area</th><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
                @foreach ($report['checks'] as $c)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1">{{ $c['area'] }}</td>
                        <td>{{ $c['check'] }}</td>
                        <td><x-filament::badge size="sm" :color="match ($c['status']) { 'PASS' => 'success', 'WARN' => 'warning', 'FAIL', 'BLOCKED' => 'danger', default => 'gray' }">{{ $c['status'] }}</x-filament::badge></td>
                        <td class="text-gray-600 dark:text-gray-300">{{ $c['detail'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
