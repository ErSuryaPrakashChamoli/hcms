<x-filament-panels::page>
    @php($p = $this->getPassport())
    @if (! $p)
        <x-filament::section>No employee selected.</x-filament::section>
    @else
        <x-filament::section :heading="$p['employee']['name']" :description="($p['employee']['designation'] ?? 'No designation') . ' · ' . $p['employee']['code'] . ($p['employee']['joined'] ? ' · joined ' . $p['employee']['joined'] : '')">
            <div class="grid gap-4 md:grid-cols-4 text-sm">
                <div><div class="text-gray-500">Active goals</div><div class="text-2xl font-semibold">{{ $p['goals']['active'] }}</div></div>
                <div><div class="text-gray-500">Goals completed</div><div class="text-2xl font-semibold">{{ $p['goals']['completed'] }}</div></div>
                <div><div class="text-gray-500">Praise received</div><div class="text-2xl font-semibold">{{ $p['feedback']['praise'] }}</div></div>
                <div><div class="text-gray-500">Latest rating</div><div class="text-2xl font-semibold">{{ $p['performance'][0]['label'] ?? '—' }}</div></div>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2">
            <x-filament::section heading="Skills" compact>
                @forelse ($p['skills'] as $s)
                    <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800"><span>{{ $s['name'] }}</span><span class="text-gray-500">{{ ucfirst($s['proficiency']) }}{{ $s['years'] ? ' · ' . $s['years'] . ' yrs' : '' }}</span></div>
                @empty
                    <div class="text-sm text-gray-500">No skills recorded.</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Certifications & qualifications" compact>
                @foreach ($p['certifications'] as $c)
                    <div class="py-1 text-sm">{{ $c['name'] }} <span class="text-gray-500">{{ $c['issuer'] ? '· ' . $c['issuer'] : '' }}{{ $c['expires_on'] ? ' · expires ' . $c['expires_on'] : '' }}</span></div>
                @endforeach
                @foreach ($p['qualifications'] as $q)
                    <div class="py-1 text-sm">{{ $q['degree'] }} <span class="text-gray-500">{{ $q['institution'] ? '· ' . $q['institution'] : '' }}{{ $q['year'] ? ' · ' . $q['year'] : '' }}</span></div>
                @endforeach
                @if (empty($p['certifications']) && empty($p['qualifications']))
                    <div class="text-sm text-gray-500">Nothing recorded.</div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Performance history" compact>
                @forelse ($p['performance'] as $r)
                    <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800"><span>{{ $r['cycle'] }}</span><span>{{ $r['rating'] }} · {{ $r['label'] }}{{ $r['promotion_recommended'] ? ' · promotion recommended' : '' }}</span></div>
                @empty
                    <div class="text-sm text-gray-500">No finalized appraisals yet.</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Experience" compact>
                @forelse ($p['experience'] as $e)
                    <div class="py-1 text-sm">{{ $e['title'] }} <span class="text-gray-500">at {{ $e['employer'] }} · {{ $e['from'] }} – {{ $e['to'] ?? 'present' }}</span></div>
                @empty
                    <div class="text-sm text-gray-500">No previous experience recorded.</div>
                @endforelse
            </x-filament::section>

            <x-filament::section heading="Aspirations" compact>
                @if ($p['aspiration'])
                    <div class="text-sm space-y-1">
                        <div><span class="text-gray-500">Target role:</span> {{ $p['aspiration']['target'] ?? '—' }}</div>
                        <div><span class="text-gray-500">Path:</span> {{ $p['aspiration']['path'] ?? '—' }}</div>
                        <div>{{ $p['aspiration']['notes'] }}</div>
                        @if ($p['aspiration']['interests'])<div><span class="text-gray-500">Interests:</span> {{ implode(', ', $p['aspiration']['interests']) }}</div>@endif
                    </div>
                @else
                    <div class="text-sm text-gray-500">No aspirations recorded yet.</div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Career path & next step" compact>
                @if ($p['career_path'])
                    <div class="text-sm mb-2">{{ $p['career_path']['name'] }}: {{ collect($p['career_path']['steps'])->map(fn ($s) => $s['current'] ? '[' . $s['designation'] . ']' : $s['designation'])->implode(' → ') }}</div>
                @endif
                @if ($p['next_step'])
                    <div class="text-sm font-medium">Next: {{ $p['next_step']['designation'] }}</div>
                    @forelse ($p['next_step']['gaps'] as $g)
                        <div class="flex justify-between py-1 text-sm"><span>{{ $g['skill'] }}</span><span class="{{ $g['met'] ? 'text-success-600' : 'text-warning-600' }}">{{ $g['met'] ? 'Ready' : 'Needs ' . $g['required'] . ($g['current'] ? ' (now ' . $g['current'] . ')' : ' (not recorded)') }}</span></div>
                    @empty
                        <div class="text-sm text-gray-500">No skill requirements defined for the next step.</div>
                    @endforelse
                @else
                    <div class="text-sm text-gray-500">No career path covers this role yet.</div>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
