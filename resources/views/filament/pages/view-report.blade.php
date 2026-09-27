<x-filament-panels::page>
    @php($result = $this->getResult())
    @if ($this->error)
        <x-filament::section><div class="text-danger-600">{{ $this->error }}</div></x-filament::section>
    @elseif ($result)
        @if ($result->kpi !== null)
            <x-filament::section compact>
                <div class="text-sm text-gray-500">{{ $this->record->name }}</div>
                <div class="text-4xl font-semibold">{{ number_format($result->kpi, fmod($result->kpi, 1) == 0 ? 0 : 2) }}</div>
            </x-filament::section>
        @endif
        <x-filament::section :heading="($result->grouped ? 'Grouped results' : 'Rows') . ' · ' . $result->total . ' total' . (count($result->rows) < $result->total ? ' (showing ' . count($result->rows) . ')' : '')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            @foreach ($result->columns as $key => $label)
                                <th class="py-2 pr-4 font-medium">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result->rows as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach ($result->columns as $key => $label)
                                    @php($v = $row[$key] ?? null)
                                    <td class="py-1.5 pr-4 {{ is_numeric($v) ? 'text-right tabular-nums' : '' }}">{{ is_bool($v) ? ($v ? 'Yes' : 'No') : (is_numeric($v) && ! is_int($v) ? number_format((float) $v, 2) : ($v ?? '—')) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($result->columns) }}" class="py-4 text-gray-500">No rows match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
