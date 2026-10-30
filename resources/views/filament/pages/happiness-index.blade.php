{{-- HROne setup parity HP-9f: the happiness index (MoodAnalytics suppresses every number below the privacy threshold). --}}
<x-filament-panels::page>
    @php($results = $this->results())
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-filament::badge :color="$results['privacy'] === 'anonymous' ? 'success' : ($results['privacy'] === 'confidential' ? 'warning' : 'gray')">{{ ucfirst($results['privacy']) }} check-ins</x-filament::badge>
            <x-filament::badge color="gray">{{ ucfirst($results['frequency']) }}</x-filament::badge>
            <span>Privacy threshold: periods and groups need at least {{ $results['k'] }} check-ins; complementary groups are suppressed too. {{ $results['current']['label'] }} is still open: its results appear once it ends.</span>
        </div>
    </x-filament::section>

    @forelse ($results['periods'] as $period)
        <x-filament::section wire:key="mood-{{ $period['key'] }}" :heading="$period['label']" :description="$period['respondents'] === null ? 'Suppressed: fewer than '.$results['k'].' check-ins' : $period['respondents'].' check-ins'">
            @if ($period['respondents'] !== null)
                <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-5">
                    <div><div class="text-gray-500">Average mood (1–5)</div><div class="text-lg font-semibold">{{ $period['average'] }}</div></div>
                    <div><div class="text-gray-500">Happy or very happy</div><div class="text-lg font-semibold">{{ $period['happy_share'] }}%</div></div>
                    <div><div class="text-gray-500">Check-ins</div><div class="text-lg font-semibold">{{ $period['respondents'] }}</div></div>
                </div>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @foreach ($period['distribution'] as $mood => $count)
                        <x-filament::badge color="gray">{{ $this->moodLabel($mood) }}: {{ $count }}</x-filament::badge>
                    @endforeach
                </div>
                @if ($period['reasons'])
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @foreach ($period['reasons'] as $reason => $count)
                            <x-filament::badge color="primary">{{ $reason }}: {{ $count }}</x-filament::badge>
                        @endforeach
                    </div>
                @endif
                @if ($period['groups'] !== [])
                    <table class="mt-4 w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1">Group</th><th>Check-ins</th><th>Average mood</th></tr></thead>
                        <tbody>
                            @foreach ($period['groups'] as $g)
                                <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $g['label'] }}</td><td>{{ $g['suppressed'] ? 'suppressed' : $g['respondents'] }}</td><td>{{ $g['suppressed'] ? '—' : $g['average'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if ($this->canReadComments())
                    <div class="mt-3"><x-filament::button size="sm" color="gray" wire:click="showComments('{{ $period['key'] }}')">Comments</x-filament::button></div>
                    @if ($this->commentsFor === $period['key'])
                        @php($comments = $this->comments())
                        @if ($comments === null)
                            <p class="mt-2 text-sm text-gray-500">Comments are shown only when at least {{ $results['text_k'] }} people commented in the period.</p>
                        @else
                            @foreach ($comments as $comment)
                                <div class="mt-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                    <p class="whitespace-pre-line">{{ $comment['text'] }}</p>
                                    @if ($comment['handle'])
                                        <x-filament::link size="sm" color="danger" tag="button" wire:click="mountAction('identify', { handle: '{{ $comment['handle'] }}' })">Identify the author</x-filament::link>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    @endif
                @endif
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>No period has ended with check-ins yet.</x-filament::section>
    @endforelse

    @if (($identified = $this->identified()) !== [])
        <x-filament::section heading="Identified check-ins" description="Only check-ins the organisation chose to identify, for people in your scope.">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Employee</th><th>Period</th><th>Mood</th><th>Reasons</th><th>Comment</th></tr></thead>
                <tbody>
                    @foreach ($identified as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700"><td class="py-1">{{ $row['employee']->person?->display_name }}</td><td>{{ $row['period'] }}</td><td>{{ $this->moodLabel($row['mood']) }}</td><td>{{ implode(', ', $row['reasons']) }}</td><td>{{ $row['comment'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
