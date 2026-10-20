<x-filament-panels::page>
    @php($results = $this->getResults())
    @php($participation = $this->getParticipation())
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-filament::badge :color="$results['mode'] === 'anonymous' ? 'success' : ($results['mode'] === 'confidential' ? 'warning' : 'gray')">{{ ucfirst($results['mode']) }} responses</x-filament::badge>
            <span>Privacy threshold: groups and questions need at least {{ $results['k'] }} respondents; complementary groups are suppressed too.</span>
        </div>
        @isset($results['message'])
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $results['message'] }}</p>
        @endisset
    </x-filament::section>

    @if ($participation)
        <x-filament::section heading="Participation">
            <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-5">
                <div><div class="text-gray-500">Eligible</div><div class="text-lg font-semibold">{{ $participation['eligible'] }}</div></div>
                <div><div class="text-gray-500">Opened</div><div class="text-lg font-semibold">{{ $participation['opened'] }}</div></div>
                <div><div class="text-gray-500">Submitted</div><div class="text-lg font-semibold">{{ $participation['submitted'] }}</div></div>
                <div><div class="text-gray-500">Expired</div><div class="text-lg font-semibold">{{ $participation['expired'] }}</div></div>
                <div><div class="text-gray-500">Response rate</div><div class="text-lg font-semibold">{{ $participation['response_rate'] !== null ? $participation['response_rate'].'%' : '—' }}</div></div>
            </div>
            @if ($participation['groups'] !== [])
                <table class="mt-4 w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Group</th><th>Eligible</th><th>Submitted</th></tr></thead>
                    <tbody>
                        @foreach ($participation['groups'] as $g)
                            <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $g['label'] }}</td><td>{{ $g['eligible'] }}</td><td>{{ $g['submitted'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif

    @if (! $results['suppressed'])
        <x-filament::section :heading="'Respondents: '.$results['respondents']">
            @if ($results['groups'] !== [])
                <div class="mb-3 flex flex-wrap gap-2 text-xs">
                    @foreach ($results['groups'] as $g)
                        <x-filament::badge :color="$g['suppressed'] ? 'gray' : 'primary'">{{ $g['label'] }}: {{ $g['suppressed'] ? 'suppressed' : $g['respondents'] }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
            <div class="space-y-4">
                @foreach ($results['questions'] as $q)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="font-medium">{{ $q['prompt'] }}</div>
                        @php($cells = array_filter(['Overall' => $q['overall']] + collect($q['groups'])->mapWithKeys(fn ($c, $key) => [collect($results['groups'])->firstWhere('key', $key)['label'] ?? $key => $c ?? false])->all(), fn ($c) => $c !== null))
                        @forelse ($cells as $label => $cell)
                            <div class="mt-2 text-sm">
                                <span class="font-semibold">{{ $label }}</span>
                                @if ($cell === false)
                                    <span class="text-gray-500">— suppressed (too few answers)</span>
                                @else
                                    <span class="text-gray-500">· {{ $cell['answered'] }} answered</span>
                                    @if ($cell['average'] !== null)<span> · average {{ $cell['average'] }}</span>@endif
                                    @if ($cell['distribution'])
                                        <div class="mt-1 flex flex-wrap gap-2">
                                            @foreach ($cell['distribution'] as $value => $count)
                                                <x-filament::badge color="gray">{{ $q['options'][$value] ?? $value }}: {{ $count }}</x-filament::badge>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @empty
                            <div class="mt-2 text-sm text-gray-500">Suppressed: fewer than {{ $results['k'] }} answers.</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @foreach ($this->getComments() as $prompt => $comments)
        <x-filament::section :heading="'Comments: '.$prompt" description="Overall only, shuffled, never attributed.">
            @if ($comments === null)
                <p class="text-sm text-gray-500">Suppressed: too few comments to protect respondents.</p>
            @else
                <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    @foreach ($comments as $c)
                        <li class="flex items-start justify-between gap-3 py-2"><span>{{ $c['text'] }}</span>@if ($c['handle']){{ ($this->revealAction)(['handle' => $c['handle']]) }}@endif</li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endforeach

    @php($trend = $this->getTrend())
    @if (count($trend) > 1)
        <x-filament::section heading="Trend (overall, closed versions)">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Version</th><th>Closed</th><th>Respondents</th><th>Response rate</th><th>Averages</th></tr></thead>
                <tbody>
                    @foreach ($trend as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1">v{{ $row['version'] }}</td><td>{{ $row['closed_at'] ?? '—' }}</td><td>{{ $row['respondents'] ?? 'suppressed' }}</td>
                            <td>{{ $row['response_rate'] !== null ? $row['response_rate'].'%' : '—' }}</td>
                            <td>{{ collect($row['averages'])->map(fn ($v, $k) => $k.' '.$v)->implode(' · ') ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
