<x-filament-panels::page>
    <x-filament::section heading="Filters" description="Every group below the minimum group size shows no amounts; a filter that leaves fewer people shows nothing.">
        <div class="grid gap-3 text-sm md:grid-cols-6">
            <label class="flex flex-col gap-1">As of<input type="date" wire:model.live="asOf" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800"></label>
            @foreach ($this->getFilterOptions() as $property => $options)
                <label class="flex flex-col gap-1">{{ ['companyId' => 'Company', 'departmentId' => 'Department', 'locationId' => 'Location', 'gradeId' => 'Grade', 'employmentTypeId' => 'Employment type'][$property] }}
                    <select wire:model.live="{{ $property }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800">
                        <option value="">All</option>
                        @foreach ($options as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
            @endforeach
        </div>
    </x-filament::section>

    @php($s = $this->getSummary())
    @if ($s['suppressed'])
        <x-filament::section><p class="text-sm text-gray-600">{{ $s['note'] }}</p></x-filament::section>
    @else
        <x-filament::section heading="Totals ({{ $s['population'] }} people, {{ $s['as_of'] }})" :description="$s['basis']">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Currency</th><th>People</th><th>Total fixed (annual)</th><th>Average fixed</th><th>Total variable target</th></tr></thead>
                <tbody>
                    @foreach ($s['totals'] as $currency => $t)
                        <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $currency }}</td><td>{{ $t['people'] }}</td>
                            @if ($t['suppressed'])<td colspan="3" class="text-gray-500">Suppressed (small group)</td>@else<td>{{ number_format($t['total_fixed'], 2) }}</td><td>{{ number_format($t['average_fixed'], 2) }}</td><td>{{ number_format($t['total_variable_target'], 2) }}</td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['By grade' => $s['by_grade'], 'By department' => $s['by_department'], 'By location' => $s['by_location'], 'By employment type' => $s['by_employment_type']] as $heading => $rows)
                <x-filament::section :heading="$heading">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1">Group</th><th>People</th><th>Average fixed</th><th>Total fixed</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $r['name'] }} <span class="text-xs text-gray-500">{{ $r['currency'] }}</span></td><td>{{ $r['people'] }}</td>
                                    @if ($r['suppressed'])<td colspan="2" class="text-gray-500">Suppressed</td>@else<td>{{ number_format($r['average_fixed'], 2) }}</td><td>{{ number_format($r['total_fixed'], 2) }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endforeach
        </div>
        <x-filament::section heading="Range penetration" :description="$s['range_penetration']['definition']">
            <p class="text-sm">Below range: {{ $s['range_penetration']['below'] }} · Within: {{ $s['range_penetration']['within'] }} · Above: {{ $s['range_penetration']['above'] }} · No applicable range: {{ $s['range_penetration']['no_range'] }}
                · Average compa-ratio: {{ $s['range_penetration']['average_compa_ratio'] ?? '—' }}</p>
        </x-filament::section>
        <x-filament::section heading="Compensation changes (last 12 months)" description="Counts only; small counts are suppressed.">
            <ul class="text-sm">
                @forelse ($s['changes']['rows'] as $c)
                    <li>{{ config('peopleos.compensation.change_types.'.$c['change_type'], $c['change_type']) }} · {{ str_replace('_', ' ', $c['status']) }}: {{ $c['count'] }}</li>
                @empty
                    <li class="text-gray-500">None.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
    <p class="text-xs text-gray-500">Facts only: no recommendation, pay-fairness verdict, performance inference or attrition prediction is made.</p>
</x-filament-panels::page>
