<x-filament-panels::page>
    @php
        $r = $this->requests;
        $tabs = \App\Domain\Experience\Services\MyRequests::TABS;
        $kinds = $this->dateKinds();
        $team = $this->team;
        $filtered = filled($this->type) || filled($this->from) || filled($this->until) || trim($this->search) !== '' || $this->hideClosed;
    @endphp
    <div class="pos-ws pos-my-requests">
        <nav class="pos-lens pos-lens-scroll" aria-label="Show" x-data x-init="posRevealChip($el, $el.querySelector('[aria-pressed=true]'))">
            @foreach ($tabs as $key => $label)
                <button type="button" class="pos-chip" aria-pressed="{{ $this->tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" data-tab="{{ $key }}">
                    {{ $label }}@if (($r['counts'][$key] ?? 0) > 0)<span class="pos-count">{{ $r['counts'][$key] }}</span>@endif
                </button>
            @endforeach
        </nav>

        <div class="pos-toolbar" x-data="{ filters: false }">
            <label class="pos-search">
                <span class="sr-only">Search requests</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference, reason or ID" autocomplete="off" />
            </label>
            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm pos-phone-only" x-on:click="filters = ! filters" :aria-expanded="filters.toString()" aria-expanded="false" aria-controls="pos-request-filters">
                <x-filament::icon icon="heroicon-m-funnel" class="size-4" /> Filters
            </button>
            <div id="pos-request-filters" class="pos-toolbar-filters" :data-open="filters.toString()">
                @if ($team->isNotEmpty())
                    <select wire:model.live="employee" class="pos-select" aria-label="Whose requests">
                        <option value="">My requests</option>
                        @foreach ($team as $member)<option value="{{ $member->id }}">{{ $member->person?->display_name }} ({{ $member->employee_code }})</option>@endforeach
                    </select>
                @endif
                <select wire:model.live="type" class="pos-select" aria-label="Request type">
                    <option value="">All request types</option>
                    @foreach ($r['types'] as $key => $t)
                        @if ($t['count'] > 0 || $this->type === $key)<option value="{{ $key }}">{{ $t['label'] }} ({{ $t['count'] }})</option>@endif
                    @endforeach
                </select>
                <select wire:model.live="date" class="pos-select" aria-label="Which date">
                    @foreach ($kinds as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <input type="date" wire:model.live="from" class="pos-select" aria-label="From (the date chosen)" title="From">
                <input type="date" wire:model.live="until" class="pos-select" aria-label="To (the date chosen)" title="To">
                <label class="flex items-center gap-2 pos-body-sm"><input type="checkbox" wire:model.live="hideClosed"> Hide closed</label>
                @if (! $r['team'] && $r['hidden'] > 0)
                    <label class="flex items-center gap-2 pos-body-sm"><input type="checkbox" wire:model.live="showHidden"> Show hidden ({{ $r['hidden'] }})</label>
                @endif
            </div>
            @if ($filtered)
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="clearFilters">Clear filters</button>
            @endif
        </div>

        @if ($r['rows']->isEmpty())
            @if ($filtered || $this->tab !== 'all')
                <x-pos.state variant="filtered" title="No request matches." why="Choose All, or clear the filters, to see every request." />
            @else
                <x-pos.state variant="empty" title="No requests yet." :why="$r['team'] ? 'Nothing you may see has been requested by this person.' : 'Requests you raise appear here with where each one stands.'"
                    :action="! $r['team'] && \App\Filament\Pages\NewRequest::canAccess() ? 'New request' : null" :action-url="\App\Filament\Pages\NewRequest::getUrl()" />
            @endif
        @else
            <ul class="pos-panel pos-stream" aria-label="Requests">
                @foreach ($r['rows'] as $row)
                    <li class="pos-stream-row" data-tone="{{ $row['status']->tone() }}" data-request="{{ $row['key'] }}" wire:key="req-{{ md5($row['key']) }}">
                        <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$row['icon']" class="size-4" /></span>
                        <div class="pos-stream-body">
                            <p class="pos-stream-title">{{ $row['title'] }}</p>
                            <p class="pos-stream-meta flex flex-wrap items-center gap-x-1">
                                <x-pos.status :tone="$row['status']->tone()" :label="$row['status_label']" />
                                <span>· {{ $row['type_label'] }}</span>
                                @if ($row['detail'])<span>· {{ $row['detail'] }}</span>@endif
                                @if ($row['requested'])<span>· requested <time datetime="{{ $row['requested']->toIso8601String() }}">{{ $row['requested']->format('d M Y') }}</time></span>@endif
                                @if ($row['decided'])<span>· decided <time datetime="{{ $row['decided']->toIso8601String() }}">{{ $row['decided']->format('d M Y') }}</time></span>@endif
                                @if ($row['hidden'])<span>· hidden</span>@endif
                            </p>
                        </div>
                        <div class="pos-stream-end">
                            @if (! $r['team'] && ($row['hidden'] || $row['status']->isClosed()))
                                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="toggleHidden(@js($row['key']))">{{ $row['hidden'] ? 'Show again' : 'Hide' }}</button>
                            @endif
                            @unless ($r['team'])
                                {{-- HROne parity RQ-2: the request's trail, whom it waits with, reminders and its discussion --}}
                                <a href="{{ \App\Filament\Pages\RequestTracker::getUrl(['request' => $row['key']]) }}" class="pos-btn pos-btn-ghost pos-btn-sm" data-follow>Follow</a>
                            @endunless
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" class="pos-btn pos-btn-secondary pos-btn-sm">Open</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($r['more'])
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm mt-2" wire:click="showMore">Show more</button>
            @endif
        @endif
    </div>
</x-filament-panels::page>
