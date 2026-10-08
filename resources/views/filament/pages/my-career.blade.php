<x-filament-panels::page>
    @php($profile = $this->profile())
    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="My career profile" description="You decide what your manager sees. HR career administrators see your profile within their scope.">
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-500">Career track</dt><dd>{{ $profile?->track?->name ?? '—' }}</dd>
                <dt class="text-gray-500">Mobility</dt><dd>{{ collect($profile?->mobility ?? [])->filter()->keys()->map(fn ($k) => config('peopleos.career.mobility_options.'.$k, $k))->implode(', ') ?: '—' }}</dd>
                <dt class="text-gray-500">Development priorities</dt><dd>{{ $profile?->development_priorities ?? '—' }}</dd>
                <dt class="text-gray-500">Shared with my manager</dt>
                <dd>{{ collect(['aspirations' => $profile?->share_aspirations_with_manager, 'goals' => $profile === null || $profile->share_goals_with_manager, 'mobility' => $profile?->share_mobility_with_manager])->filter()->keys()->implode(', ') ?: 'nothing' }}</dd>
            </dl>
        </x-filament::section>
        <x-filament::section heading="My aspirations" description="Each new aspiration is kept; earlier ones remain as history.">
            <ul class="text-sm">
                @forelse ($this->aspirations() as $a)
                    <li class="py-1 {{ $a->status === 'superseded' ? 'text-gray-500' : '' }}">{{ config('peopleos.career.aspiration_terms.'.$a->term) }} — {{ $a->targetDesignation?->name ? $a->targetDesignation->name.': ' : '' }}{{ $a->aspiration }} <span class="text-gray-500">({{ $a->effective_from?->toDateString() }}{{ $a->status === 'superseded' ? ' – '.$a->effective_to?->toDateString() : '' }})</span></li>
                @empty
                    <li class="text-gray-500">No aspirations recorded yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="My career goals" description="Separate from performance goals. Achieving a career goal never changes your job, pay or position by itself.">
            <ul class="text-sm">
                @forelse ($this->goals() as $g)
                    <li class="py-1">{{ $g->title }} <x-filament::badge size="sm" color="gray">{{ config('peopleos.career.goal_statuses.'.$g->status) }}</x-filament::badge>
                        <span class="text-gray-500">{{ $g->targetDesignation?->name }}{{ $g->target_date ? ' · by '.$g->target_date->toDateString() : '' }}</span></li>
                @empty
                    <li class="text-gray-500">No career goals yet.</li>
                @endforelse
            </ul>
        </x-filament::section>
        <x-filament::section heading="My mobility interest">
            <ul class="text-sm">
                @forelse ($this->interests() as $i)
                    <li class="py-1">{{ config('peopleos.career.mobility_interest_types.'.$i->interest_type) }}{{ $i->designation ? ': '.$i->designation->name : '' }} <x-filament::badge size="sm" color="gray">{{ $i->status }}</x-filament::badge></li>
                @empty
                    <li class="text-gray-500">None recorded.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>

    <x-filament::section heading="What the roles I target require" description="Facts from the role requirements and your verified records — not a score or a recommendation. Self-declared levels are shown separately.">
        @forelse ($this->gaps() as $gap)
            <h4 class="mt-2 font-medium">{{ $gap['designation'] }} <span class="text-xs text-gray-500">requirements v{{ $gap['requirements']['version'] }}</span></h4>
            <ul class="text-sm">
                @foreach ($gap['skills'] as $s)
                    <li>{{ $s['skill'] }}: required {{ $s['required_label'] ?? $s['required_level'] }}, current {{ $s['current_level'] ?? 'none' }}{{ $s['basis'] ? ' ('.$s['basis'].')' : '' }}{{ $s['self_declared'] !== null && ! $s['verified'] ? ', self-declared '.$s['self_declared'] : '' }}{{ $s['gap'] ? ' — gap '.$s['gap'] : '' }}</li>
                @endforeach
                @foreach ($gap['learning'] as $l)
                    <li>{{ $l['title'] }}: {{ str_replace('_', ' ', $l['status']) }}</li>
                @endforeach
                @foreach ($gap['certifications'] as $c)
                    <li>Certification {{ $c['title'] }}: {{ str_replace('_', ' ', $c['status']) }}</li>
                @endforeach
            </ul>
        @empty
            <p class="text-sm text-gray-500">Add target roles to your profile or a career goal; gaps appear when the role has published requirements.</p>
        @endforelse
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2">
        <x-filament::section heading="My movement history" description="From your employment record.">
            <ul class="text-sm">
                @forelse ($this->movements() as $m)
                    <li>{{ $m['effective_from'] }} — {{ $m['change_type'] }}: {{ $m['designation'] ?? '—' }}{{ $m['department'] ? ', '.$m['department'] : '' }}</li>
                @empty
                    <li class="text-gray-500">No history.</li>
                @endforelse
            </ul>
        </x-filament::section>
        @if ($this->candidacy()->isNotEmpty())
            <x-filament::section heading="My succession candidacy">
                <ul class="text-sm">
                    @foreach ($this->candidacy() as $c)
                        <li>{{ $c['position'] }} — {{ config('peopleos.talent.readiness_levels.'.$c['readiness'], 'not assessed') }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
