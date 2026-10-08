<x-filament-panels::page>
    <x-filament::section heading="Team career & development" description="Aspirations, goals and mobility appear only where the employee chose to share them with you.">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-1">Employee</th><th>Aspirations</th><th>Career goals</th><th>Mobility</th><th>Development plans (done / items)</th></tr></thead>
            <tbody>
                @foreach ($this->members() as $m)
                    <tr class="border-t border-gray-200 dark:border-gray-700 align-top">
                        <td class="py-1">{{ $m['name'] }} <span class="text-gray-500">{{ $m['code'] }}</span></td>
                        <td>{{ $m['aspirations'] === null ? 'Not shared' : (implode('; ', $m['aspirations']) ?: '—') }}</td>
                        <td>{{ $m['goals'] === null ? 'Not shared' : (implode('; ', $m['goals']) ?: '—') }}</td>
                        <td>{{ $m['mobility'] === null ? 'Not shared' : (implode(', ', $m['mobility']) ?: '—') }}</td>
                        <td>{{ implode('; ', $m['development']) ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    @if (auth()->user()->can('succession.team'))
        <x-filament::section heading="Succession" description="Confidential. Your team members are not told about their candidacy.">
            <ul class="text-sm">
                @forelse ($this->succession() as $s)
                    <li>{{ $s['name'] }} — successor for {{ $s['position'] }} ({{ config('peopleos.talent.readiness_levels.'.$s['readiness'], 'not assessed') }})</li>
                @empty
                    <li class="text-gray-500">No one in your team is on a succession plan.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif

    @if ($this->reviews()->isNotEmpty())
        <x-filament::section heading="Talent reviews I take part in">
            <ul class="text-sm">
                @foreach ($this->reviews() as $r)
                    <li><a class="text-primary-600 hover:underline" href="{{ $this->reviewsUrl() }}">{{ $r->name }}</a> — {{ str_replace('_', ' ', $r->status) }}{{ $r->scheduled_for ? ', '.$r->scheduled_for->toDateString() : '' }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
