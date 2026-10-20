<x-filament-panels::page>
    @php($s = $this->getSummary())
    <div class="grid gap-4 md:grid-cols-4">
        @foreach ([
            ['Enrolments', $s['enrolments']['total'], 'last 12 months'],
            ['Completion rate', $s['completion_rate'] === null ? '—' : $s['completion_rate'].'%', $s['completions'].' completions'],
            ['Mandatory compliance', $s['mandatory']['compliance_rate'] === null ? '—' : $s['mandatory']['compliance_rate'].'%', $s['mandatory']['overdue'].' overdue'],
            ['Learning hours', $s['learning_hours'], 'from finalized completions'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="text-3xl font-semibold">{{ $value }}</div>
                <div class="text-xs text-gray-500">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="Certificates" description="Expiring credentials are renewed through recertification, never extended.">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Valid</dt><dd>{{ $s['certificates']['valid'] }}</dd>
                <dt class="text-gray-500">Expiring in 30 days</dt><dd>{{ $s['certificates']['expiring_30'] }}</dd>
                <dt class="text-gray-500">Expiring in 90 days</dt><dd>{{ $s['certificates']['expiring_90'] }}</dd>
                <dt class="text-gray-500">Expired</dt><dd>{{ $s['certificates']['expired'] }}</dd>
                <dt class="text-gray-500">Revoked</dt><dd>{{ $s['certificates']['revoked'] }}</dd>
            </dl>
        </x-filament::section>
        <x-filament::section heading="Development plans">
            <div class="text-sm">Completion rate: {{ $s['development_plans']['completion_rate'] === null ? '—' : $s['development_plans']['completion_rate'].'%' }}</div>
            <div class="mt-2 flex flex-wrap gap-2">
                @forelse ($s['development_plans']['by_status'] as $status => $count)
                    <x-filament::badge color="gray">{{ $status }}: {{ $count }}</x-filament::badge>
                @empty
                    <span class="text-sm text-gray-500">No plans yet.</span>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Mandatory training" description="Per course: assigned, completed and overdue.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Course</th><th>Assigned</th><th>Completed</th><th>Overdue</th><th>Compliance</th></tr></thead>
            <tbody>
                @forelse ($this->getMandatory() as $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $row['course'] }}</td><td>{{ $row['assigned'] }}</td><td>{{ $row['completed'] }}</td><td>{{ $row['overdue'] }}</td><td>{{ $row['rate'] === null ? '—' : $row['rate'].'%' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">No mandatory learning assigned.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="Popular courses">
            <ul class="text-sm">
                @forelse ($s['popular_courses'] as $c)
                    <li>{{ $c['title'] }} <span class="text-gray-500">({{ $c['code'] }}) — {{ $c['enrolments'] }}</span></li>
                @empty
                    <li class="text-gray-500">No enrolments yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="Skill gaps" :description="'Groups smaller than '.$s['min_group'].' people are suppressed.'">
            <ul class="text-sm">
                @forelse ($s['skill_gaps'] as $g)
                    <li>{{ $g['skill'] }} — {{ $g['suppressed'] ? 'suppressed' : $g['employees'].' people, average gap '.$g['average_gap'] }}</li>
                @empty
                    <li class="text-gray-500">No skill targets recorded yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="By department" :description="'Departments with fewer than '.$s['min_group'].' learners are suppressed.'">
            <ul class="text-sm">
                @forelse ($s['by_department'] as $d)
                    <li>{{ $d['department'] }} — {{ $d['suppressed'] ? 'suppressed' : $d['completion_rate'].'% complete ('.$d['enrolments'].' enrolments)' }}</li>
                @empty
                    <li class="text-gray-500">No data.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="Providers and costs">
            <ul class="text-sm">
                @foreach ($s['providers'] as $p)
                    <li>{{ $p['name'] }} — {{ $p['completions'] }} completion(s)</li>
                @endforeach
                @if ($s['costs'] !== null)
                    @foreach ($s['costs'] as $cost)
                        <li>{{ $cost['type'] }}: {{ number_format($cost['total'], 2) }} {{ $cost['currency'] }}</li>
                    @endforeach
                @else
                    <li class="text-gray-500">Costs need learning.costs.</li>
                @endif
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
