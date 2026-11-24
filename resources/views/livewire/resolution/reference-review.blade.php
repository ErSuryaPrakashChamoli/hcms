<section class="pos-di-card" aria-labelledby="refs-title">
    <div class="pos-di-card-head">
        <div>
            <h3 class="pos-di-card-title" id="refs-title">Names in your file</h3>
            <p class="pos-di-card-sub">Departments, designations, companies and other records your file names, and what PeopleOS matched each one to. Nothing is guessed: a near miss is only suggested.</p>
        </div>
    </div>

    <div class="pos-di-stats">
        <div class="pos-di-stat" @if ($counts['matched']) data-tone="add" @endif><span class="pos-di-stat-value">{{ $counts['matched'] }}</span><span class="pos-di-stat-label">Matched</span></div>
        <div class="pos-di-stat" @if ($counts['attention']) data-tone="error" @endif><span class="pos-di-stat-value">{{ $counts['attention'] }}</span><span class="pos-di-stat-label">Need attention</span></div>
        <div class="pos-di-stat" @if ($counts['settled']) data-tone="update" @endif><span class="pos-di-stat-value">{{ $counts['settled'] }}</span><span class="pos-di-stat-label">Settled here</span></div>
    </div>

    <label class="pos-di-card-sub" style="display: inline-flex; gap: .5rem; align-items: center">
        <x-filament::input.checkbox wire:model.live="all" />
        <span>Show matched names too</span>
    </label>

    @if ($entries === [])
        <div class="pos-di-note" data-tone="success"><x-filament::icon icon="heroicon-m-check-circle" /><span>Every name in the file matched one record.</span></div>
    @else
        <div class="pos-fmt-scroll" tabindex="0" role="region" aria-label="Names in your file">
            <table class="pos-fmt-table">
                <caption class="sr-only">Names in your file and the records they matched</caption>
                <thead>
                    <tr>
                        <th scope="col">Supplied</th>
                        <th scope="col">Resolved record</th>
                        <th scope="col">Entity</th>
                        <th scope="col">Status</th>
                        <th scope="col">What to do</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        @php
                            $status = \App\Domain\Organisation\Resolution\ResolutionStatus::from($entry['status']);
                            $first = $entry['at'][0] ?? null;
                            $where = $first ? trim(($first['sheet'] ?? '').($first['row'] ? ' row '.$first['row'] : '').($first['column'] ? ', '.$first['column'] : '')) : null;
                            $key = $entry['key'];
                        @endphp
                        <tr wire:key="ref-{{ $key }}">
                            <td>
                                <span class="pos-fmt-name">{{ $entry['supplied'] }}</span>
                                <div class="pos-di-card-sub">{{ $entry['master_label'] }}@if ($where) · {{ $where }}@endif @if ($entry['count'] > 1) · {{ $entry['count'] }} rows @endif</div>
                            </td>
                            <td>
                                {{ $entry['record'] ?? '—' }}
                                @if (($entry['via'] ?? null) === 'mapping') <div class="pos-di-card-sub">by your choice</div>
                                @elseif (($entry['via'] ?? null) === 'created') <div class="pos-di-card-sub">created now</div>
                                @elseif (($entry['via'] ?? null) === 'code') <div class="pos-di-card-sub">by code</div>
                                @endif
                            </td>
                            <td>{{ $entry['company'] ?? 'Any' }}</td>
                            <td><x-filament::badge :color="$status->tone()">{{ $status->label() }}</x-filament::badge></td>
                            <td>
                                @if ($status->ok())
                                    @foreach ($entry['warnings'] ?? [] as $warning)<div class="pos-di-card-sub">{{ $warning }}</div>@endforeach
                                @else
                                    <div class="pos-di-card-sub">{{ $entry['message'] }}</div>
                                    @if ($status->reviewable())
                                        <div style="display: flex; flex-wrap: wrap; gap: .375rem; margin-top: .375rem">
                                            @foreach ($entry['candidates'] ?? [] as $candidate)
                                                @php $candidateLabel = $candidate['name'].($candidate['code'] && $candidate['code'] !== $candidate['name'] ? ' ('.$candidate['code'].')' : ''); @endphp
                                                <x-filament::button size="xs" color="gray" icon="heroicon-m-check" wire:click="useRecord('{{ $key }}', {{ (int) $candidate['id'] }})">Use {{ $candidateLabel }}</x-filament::button>
                                            @endforeach
                                            @php $options = $this->options($entry); @endphp
                                            @if ($options !== [])
                                                <x-filament::input.wrapper style="min-width: 14rem">
                                                    <x-filament::input.select wire:model="choice.{{ $key }}" aria-label="Choose the {{ mb_strtolower($entry['master_label']) }} '{{ $entry['supplied'] }}' means">
                                                        <option value="">Choose a {{ mb_strtolower($entry['master_label']) }}…</option>
                                                        @foreach ($options as $id => $label)
                                                            <option value="{{ $id }}">{{ $label }}</option>
                                                        @endforeach
                                                    </x-filament::input.select>
                                                </x-filament::input.wrapper>
                                                <x-filament::button size="xs" wire:click="useRecord('{{ $key }}')">Use</x-filament::button>
                                            @endif
                                            @if ($this->canCreate($entry))
                                                <x-filament::button size="xs" color="gray" icon="heroicon-m-plus" wire:click="createMissing('{{ $key }}')"
                                                    wire:confirm="Create the {{ mb_strtolower($entry['master_label']) }} '{{ $entry['supplied'] }}'{{ $entry['company'] ? ' for '.$entry['company'] : '' }}? Check the suggestions first: this adds a new record.">
                                                    Create {{ $entry['supplied'] }}
                                                </x-filament::button>
                                            @endif
                                        </div>
                                        @if ($this->canRemember($entry))
                                            <label class="pos-di-card-sub" style="display: inline-flex; gap: .375rem; align-items: center; margin-top: .375rem">
                                                <x-filament::input.checkbox wire:model="remember.{{ $key }}" />
                                                <span>Remember for later uploads</span>
                                            </label>
                                        @endif
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($more > 0)
            <div style="margin-top: .5rem">
                <x-filament::button size="xs" color="gray" wire:click="showMore">Show {{ min($more, \App\Livewire\Resolution\ReferenceReview::PAGE) }} more ({{ $more }} not shown yet)</x-filament::button>
            </div>
        @endif
    @endif

    @if ($unlisted > 0)
        <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-exclamation-triangle" /><span>{{ $unlisted }} more names need attention but are not listed: too many to settle one by one. Correct them in the file and upload it again.</span></div>
    @endif
    @if ($all && $unlistedMatched > 0)
        <div class="pos-di-card-sub">{{ $unlistedMatched }} more names matched one record each and are not listed.</div>
    @endif
</section>
