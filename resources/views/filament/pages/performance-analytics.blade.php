<x-filament-panels::page>
    @php($summary = $this->getSummary())
    @php($rows = array_merge([['group' => 'All employees'] + $summary['overall']], $summary['groups']))
    <x-filament::section :heading="$summary['cycle'] ? 'Cycle ' . $summary['cycle'] : 'All employees'" :description="'Groups smaller than ' . $summary['min_group'] . ' people are suppressed so no individual rating can be inferred. Descriptive only — no ranking or recommendations.'">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="py-2 pr-4">Group</th><th class="py-2 pr-4">Employees</th><th class="py-2 pr-4">Appraisals</th>
                        <th class="py-2 pr-4">Completion</th><th class="py-2 pr-4">Avg goal progress</th><th class="py-2 pr-4">Check-ins (90 days)</th><th class="py-2 pr-4">Rating distribution</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-4 font-medium">{{ $row['group'] }}</td>
                            @if ($row['suppressed'])
                                <td class="py-2 pr-4 text-gray-500" colspan="6">Suppressed (fewer than {{ $summary['min_group'] }} people)</td>
                            @else
                                <td class="py-2 pr-4">{{ $row['employees'] }}</td>
                                <td class="py-2 pr-4">{{ $row['appraisals'] }}</td>
                                <td class="py-2 pr-4">{{ $row['appraisal_completion'] === null ? '—' : $row['appraisal_completion'] . '%' }}</td>
                                <td class="py-2 pr-4">{{ $row['average_goal_progress'] === null ? '—' : $row['average_goal_progress'] . '%' }}</td>
                                <td class="py-2 pr-4">{{ $row['check_ins_90_days'] }}</td>
                                <td class="py-2 pr-4">
                                    @if ($row['rating_distribution'] === null)
                                        <span class="text-gray-500">—</span>
                                    @else
                                        {{ collect($row['rating_distribution'])->map(fn ($n, $label) => $label . ': ' . $n)->implode(' · ') }}
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
