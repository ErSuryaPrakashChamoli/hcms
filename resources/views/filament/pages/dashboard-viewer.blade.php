<x-filament-panels::page>
    @php($widgets = $this->getWidgets())
    @if (! $this->getDashboard())
        <x-filament::section>No dashboard is available to you yet.</x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-4">
            @foreach ($widgets as $i => $w)
                <div class="{{ $w['size'] >= 4 ? 'md:col-span-4' : ($w['size'] >= 2 ? 'md:col-span-2' : '') }}">
                    <x-filament::section :heading="$w['title']" compact>
                        @if ($w['error'])
                            <div class="text-sm text-danger-600">{{ $w['error'] }}</div>
                        @elseif ($w['type'] === 'kpi')
                            <div class="text-3xl font-semibold">{{ \App\Filament\Pages\DashboardViewer::formatValue($w['metric']['value'], $w['metric']['format']) }}</div>
                            @if ($w['metric']['hint'])<div class="text-xs text-gray-500">{{ $w['metric']['hint'] }}</div>@endif
                        @elseif ($w['type'] === 'trend')
                            @php($values = array_values($w['chart']['series'])[0] ?? [])
                            @php($max = max(1, max($values ?: [1])))
                            <div class="flex items-end gap-1 h-28">
                                @foreach ($values as $k => $v)
                                    <div class="flex-1 bg-primary-500/70 rounded-t" style="height: {{ round($v / $max * 100) }}%" title="{{ $w['chart']['labels'][$k] }}: {{ $v }}"></div>
                                @endforeach
                            </div>
                            <div class="flex justify-between text-xs text-gray-500 mt-1"><span>{{ $w['chart']['labels'][0] ?? '' }}</span><span>{{ end($w['chart']['labels']) ?: '' }}</span></div>
                            <div class="text-sm mt-1">Latest: <span class="font-semibold">{{ end($values) === false ? '—' : end($values) }}</span></div>
                        @elseif ($w['type'] === 'alerts')
                            @forelse ($w['items'] as $item)
                                <div class="flex items-center justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800 last:border-0">
                                    <span><x-filament::badge :color="$item['severity'] === 'danger' ? 'danger' : ($item['severity'] === 'warning' ? 'warning' : 'info')">{{ $item['count'] }}</x-filament::badge> <span class="ml-1">{{ $item['title'] }}</span></span>
                                    @if ($item['url'])<x-filament::link :href="$item['url']" size="sm">Go</x-filament::link>@endif
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Nothing needs attention.</div>
                            @endforelse
                        @elseif ($w['type'] === 'payroll')
                            {{-- HROne parity CA-10: the last finalized payroll month (aggregates only). --}}
                            @if ($w['payroll'] === null)
                                <div class="text-sm text-gray-500">No finalized payroll yet.</div>
                            @elseif ($w['payroll']['suppressed'])
                                <div class="text-sm text-gray-500">{{ $w['payroll']['period'] }}: suppressed (fewer than {{ $w['payroll']['min_group'] }} people paid in this view).</div>
                            @else
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                    @foreach (['net_pay' => 'Net paid', 'total_deductions' => 'Deductions', 'gross' => 'Gross', 'employer_cost' => 'Employer cost (effective)'] as $key => $label)
                                        <div><div class="text-xs text-gray-500">{{ $label }}</div><div class="text-xl font-semibold tabular-nums">{{ \App\Filament\Pages\DashboardViewer::formatValue($w['payroll'][$key], 'currency') }}</div></div>
                                    @endforeach
                                </div>
                                <div class="text-xs text-gray-500 mt-2">{{ $w['payroll']['period'] }} · {{ $w['payroll']['employees'] }} people paid{{ $w['payroll']['as_of'] ? ' · as of '.$w['payroll']['as_of'] : '' }}</div>
                            @endif
                        @elseif ($w['type'] === 'nine_box')
                            {{-- HROne parity CA-10: counts per box, never names; small boxes suppressed. --}}
                            @if ($w['nine_box'] === null)
                                <div class="text-sm text-gray-500">No performance cycle yet.</div>
                            @elseif ($w['nine_box']['suppressed'])
                                <div class="text-sm text-gray-500">{{ $w['nine_box']['cycle'] }}: suppressed (fewer than {{ $w['nine_box']['min_group'] }} placed).</div>
                            @else
                                <div class="grid grid-cols-3 gap-1">
                                    @foreach (['high', 'moderate', 'low'] as $potential)
                                        @foreach (['low', 'moderate', 'high'] as $performance)
                                            @php($box = $w['nine_box']['boxes'][$potential.'_'.$performance] ?? null)
                                            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-2 text-center">
                                                <div class="text-xs text-gray-500">{{ $box['label'] ?? '' }}</div>
                                                <div class="text-lg font-semibold tabular-nums">{{ ($box['count'] ?? null) === null ? '<'.$w['nine_box']['min_group'] : $box['count'] }}</div>
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>
                                <div class="text-xs text-gray-500 mt-2">{{ $w['nine_box']['cycle'] }} · {{ $w['nine_box']['placed'] }} placed · rows: potential high → low; columns: performance low → high</div>
                            @endif
                        @elseif ($w['type'] === 'survey')
                            {{-- HROne parity CA-10: the latest released results the viewer may read, under the survey's own suppression. --}}
                            @if ($w['survey'] === null)
                                <div class="text-sm text-gray-500">No released survey results you may see in this view.</div>
                            @else
                                <div class="text-sm font-medium">{{ $w['survey']['name'] }} (version {{ $w['survey']['version'] }}, closed {{ $w['survey']['closed_on'] }})</div>
                                @if ($w['survey']['suppressed'])
                                    <div class="text-sm text-gray-500">{{ $w['survey']['message'] ?? 'Suppressed to protect respondents.' }}</div>
                                @else
                                    <div class="text-xs text-gray-500 mb-2">{{ $w['survey']['respondents'] }} responses{{ $w['survey']['response_rate'] !== null ? ' · '.$w['survey']['response_rate'].'% response rate' : '' }}</div>
                                    <table class="w-full text-sm">
                                        <tbody>
                                            @forelse ($w['survey']['questions'] as $question)
                                                <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pr-2">{{ $question['prompt'] }}</td><td class="py-1 pr-2 text-right tabular-nums">{{ $question['average'] }}</td></tr>
                                            @empty
                                                <tr><td class="py-2 text-gray-500">No rated questions.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                @endif
                            @endif
                        @else
                            @php($r = $w['result'])
                            @if ($w['type'] === 'chart' && ! empty($r->chart['labels']))
                                @php($values = array_values($r->chart['series'])[0] ?? [])
                                @php($max = max(1, max($values ?: [1])))
                                <div class="space-y-1">
                                    @foreach ($r->chart['labels'] as $k => $label)
                                        <div class="flex items-center gap-2 text-sm"><span class="w-32 truncate text-gray-600 dark:text-gray-300" title="{{ $label }}">{{ $label }}</span><div class="flex-1 bg-gray-100 dark:bg-gray-800 rounded"><div class="bg-primary-500 rounded h-3" style="width: {{ round(($values[$k] ?? 0) / $max * 100) }}%"></div></div><span class="w-16 text-right tabular-nums">{{ $values[$k] ?? 0 }}</span></div>
                                    @endforeach
                                </div>
                            @else
                                <table class="w-full text-sm">
                                    <thead><tr class="text-left text-gray-500">@foreach ($r->columns as $label)<th class="py-1 pr-2 font-medium">{{ $label }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @forelse ($r->rows as $row)
                                            <tr class="border-t border-gray-100 dark:border-gray-800">@foreach ($r->columns as $key => $label)<td class="py-1 pr-2 {{ is_numeric($row[$key] ?? null) ? 'text-right tabular-nums' : '' }}">{{ is_bool($row[$key] ?? null) ? (($row[$key]) ? 'Yes' : 'No') : ($row[$key] ?? '—') }}</td>@endforeach</tr>
                                        @empty
                                            <tr><td colspan="{{ count($r->columns) }}" class="py-2 text-gray-500">No rows.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            @endif
                            <div class="mt-2"><x-filament::link :href="\App\Filament\Resources\Reports\ReportResource::getUrl('view', ['record' => $w['report']['id']])" size="sm">Open report</x-filament::link></div>
                        @endif
                    </x-filament::section>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
