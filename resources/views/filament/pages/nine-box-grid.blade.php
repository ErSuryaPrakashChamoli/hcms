{{-- HROne setup parity HP-8g: the 9-box (potential rows high → low, performance columns low → high). --}}
<x-filament-panels::page>
    @php($cycle = $this->getCycle())
    @php($grid = $this->getGrid())
    @if (! $cycle || ! $grid)
        <x-filament::section>No performance cycle yet. Launch one, then come back to place its employees.</x-filament::section>
    @else
        @php($axes = $grid['axes'])
        @php($boxes = config('peopleos.performance.nine_box.boxes'))
        @php($bandNames = config('peopleos.performance.nine_box.bands'))
        @php($filters = $this->getFilterLabels())
        @php($tint = ['high_high' => 'success', 'high_moderate' => 'success', 'moderate_high' => 'success', 'high_low' => 'warning', 'moderate_moderate' => 'info', 'low_high' => 'info', 'moderate_low' => 'warning', 'low_moderate' => 'warning', 'low_low' => 'danger'])
        @php($range = fn (array $axis, string $band) => \App\Domain\Performance\Services\NineBox::format($axis['bands'][$band]['from']) . ($band === 'high' ? '+' : '–' . \App\Domain\Performance\Services\NineBox::format($axis['bands'][$band]['to'])))
        <x-filament::section :heading="$cycle->name" :description="$grid['placed'] . ' placed' . ($grid['mode'] === 'counts' ? ' (counts only)' : '') . ' · ' . $axes['performance']['label'] . ' × ' . $axes['potential']['label'] . ($filters ? ' · ' . implode(' · ', $filters) : '')">
            @if ($grid['suppressed'])
                <p class="text-sm text-gray-500">Fewer than {{ $grid['min_group'] }} people are placed: the grid is suppressed so no individual's rating can be inferred.</p>
            @else
                <div class="overflow-x-auto">
                    <div class="grid gap-2" style="grid-template-columns: 7rem repeat(3, minmax(12rem, 1fr));" role="table" aria-label="9-box: potential against performance">
                        @foreach (['high', 'moderate', 'low'] as $potential)
                            <div class="flex items-center text-xs text-gray-500" role="rowheader">
                                <div><div class="font-semibold">Potential: {{ $bandNames[$potential] }}</div><div>{{ $range($axes['potential'], $potential) }}</div></div>
                            </div>
                            @foreach (['low', 'moderate', 'high'] as $performance)
                                @php($box = $potential . '_' . $performance)
                                @php($people = $grid['cells'][$box] ?? [])
                                @php($count = $grid['counts'][$box] ?? 0)
                                <div wire:key="box-{{ $box }}" class="rounded-lg border border-gray-200 dark:border-gray-700 p-3" role="cell"
                                     style="background: color-mix(in srgb, var(--{{ $tint[$box] }}-500) 10%, transparent); min-height: 7rem;">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold">{{ $boxes[$box] }}</span>
                                        <span class="text-lg font-semibold tabular-nums">{{ $count === null ? '<' . $grid['min_group'] : $count }}</span>
                                    </div>
                                    @if ($people)
                                        <ul class="mt-2 space-y-1 text-sm" style="max-height: 12rem; overflow-y: auto;">
                                            @foreach ($people as $person)
                                                <li wire:key="p-{{ $person['appraisal_id'] }}" class="flex items-center justify-between gap-2">
                                                    <span class="min-w-0 truncate" title="{{ $person['code'] }} · performance {{ \App\Domain\Performance\Services\NineBox::format($person['performance']) }}{{ $person['provisional'] ? ' (provisional)' : '' }} · potential {{ \App\Domain\Performance\Services\NineBox::format($person['potential']) }}">
                                                        @if ($url = $this->appraisalUrl($person['appraisal_id']))
                                                            <a href="{{ $url }}" class="hover:underline">{{ $person['name'] ?? $person['code'] }}</a>
                                                        @else
                                                            {{ $person['name'] ?? $person['code'] }}
                                                        @endif
                                                        @if ($person['provisional'])<span class="text-xs text-gray-500">*</span>@endif
                                                    </span>
                                                    @if ($this->canAssignInitiative($person['employee_id']))
                                                        <x-filament::link size="xs" color="gray" tag="button" wire:click="mountAction('assignInitiative', { employee: {{ (int) $person['employee_id'] }} })" aria-label="Assign an initiative to {{ $person['name'] }}">Initiative</x-filament::link>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        @endforeach
                        <div></div>
                        @foreach (['low', 'moderate', 'high'] as $performance)
                            <div class="text-center text-xs text-gray-500" role="columnheader"><span class="font-semibold">Performance: {{ $bandNames[$performance] }}</span> · {{ $range($axes['performance'], $performance) }}</div>
                        @endforeach
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    * provisional: the rating is not final yet (calibrated, else computed). Bands: performance {{ $axes['performance']['source'] === 'thirds' ? 'thirds of the scale' : ($axes['performance']['source'] === 'cycle' ? 'this cycle' : 'the default') }},
                    potential {{ $axes['potential']['source'] === 'thirds' ? 'thirds of the scale' : ($axes['potential']['source'] === 'cycle' ? 'this cycle' : 'the default') }}.
                </p>
            @endif
        </x-filament::section>

        @if ($grid['unplaced'] !== [])
            <x-filament::section heading="Not placed" :description="count($grid['unplaced']) . ' without a rating or a potential score'" collapsible collapsed>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Employee</th><th>Code</th><th>Why</th></tr></thead>
                    <tbody>
                        @foreach ($grid['unplaced'] as $row)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $row['name'] }}</td><td>{{ $row['code'] }}</td><td>{{ $row['reason'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
