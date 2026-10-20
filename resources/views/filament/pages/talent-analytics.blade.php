<x-filament-panels::page>
    @php($s = $this->getSummary())
    <x-filament::section heading="Bench strength by critical position" description="Active successors on open plans and current, unexpired ready-now labels. Exit dates are those already recorded in Exit.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Position</th><th>Criticality</th><th>Open plan</th><th>Successors</th><th>Ready now</th><th>Next review</th><th>Recorded incumbent exit</th></tr></thead>
            <tbody>
                @forelse ($this->getCoverage() as $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1">{{ $row['title'] }}</td><td>{{ $row['criticality'] ?? '—' }}</td><td>{{ $row['open_plan'] ? 'Yes' : 'No' }}</td>
                        <td>{{ $row['successors'] }}</td><td>{{ $row['ready_now'] }}</td>
                        <td @class(['text-danger-600' => $row['review_overdue']])>{{ $row['next_review_on'] ?? '—' }}</td><td>{{ $row['incumbent_exit_on'] ? substr((string) $row['incumbent_exit_on'], 0, 10) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-2 text-gray-500">No critical positions.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ([
            ['Readiness labels (current)', $s['readiness'], 'peopleos.talent.readiness_levels'],
            ['Development actions (plan item status)', $s['development_actions'], 'x'],
            ['Talent review decisions', $s['review_decisions'], 'peopleos.talent.review_decisions'],
            ['Current aspirations by term', $s['aspirations'], 'peopleos.career.aspiration_terms'],
            ['Active mobility interest', $s['mobility_interest'], 'peopleos.career.mobility_interest_types'],
            ['Successor skill gaps (required skills)', $this->getGaps(), 'x'],
            ['Successor certification gaps (missing or expired)', $this->getCertificationGaps(), 'x'],
        ] as [$heading, $rows, $labels])
            <x-filament::section :heading="$heading" :description="'Groups below '.$s['min_group'].' are suppressed.'">
                <ul class="text-sm">
                    @forelse ($rows as $r)
                        <li>{{ $this->label($labels, $r['key']) }} — {{ $r['suppressed'] ? 'suppressed' : $r['count'] }}</li>
                    @empty
                        <li class="text-gray-500">No data.</li>
                    @endforelse
                </ul>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
