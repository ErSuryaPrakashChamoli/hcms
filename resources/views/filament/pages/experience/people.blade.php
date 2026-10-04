<x-filament-panels::page>
    @php($f = $this->filters)
    <div class="pos-people">
        <div class="pos-toolbar">
            <label class="pos-search">
                <span class="sr-only">Search people</span>
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, employee ID or work email" autocomplete="off" />
            </label>
            @if ($f['show'])
                <select wire:model.live="department" class="pos-select" aria-label="Department">
                    <option value="">All departments</option>
                    @foreach ($f['departments'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select wire:model.live="location" class="pos-select" aria-label="Location">
                    <option value="">All locations</option>
                    @foreach ($f['locations'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select wire:model.live="status" class="pos-select" aria-label="Status">
                    <option value="">Any status</option>
                    @foreach ($f['statuses'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            @endif
            @if ($f['show'] && $display !== 'changed')
                <select wire:change="setGroup($event.target.value || null)" class="pos-select" aria-label="Group by">
                    <option value="" @selected(! $group)>No grouping</option>
                    <option value="department" @selected($group === 'department')>Group by department</option>
                    <option value="location" @selected($group === 'location')>Group by location</option>
                    <option value="manager" @selected($group === 'manager')>Group by manager</option>
                </select>
            @endif
            <div class="pos-segmented ms-auto" role="group" aria-label="View">
                <button type="button" wire:click="setDisplay('grid')" aria-pressed="{{ $display === 'grid' ? 'true' : 'false' }}" title="Cards"><x-filament::icon icon="heroicon-m-squares-2x2" class="size-4" /><span class="sr-only">Cards</span></button>
                <button type="button" wire:click="setDisplay('list')" aria-pressed="{{ $display === 'list' ? 'true' : 'false' }}" title="Table"><x-filament::icon icon="heroicon-m-bars-3" class="size-4" /><span class="sr-only">Table</span></button>
                <button type="button" wire:click="setDisplay('changed')" aria-pressed="{{ $display === 'changed' ? 'true' : 'false' }}" title="Recently changed"><x-filament::icon icon="heroicon-m-arrows-right-left" class="size-4" /><span class="max-md:sr-only">Recently changed</span></button>
            </div>
            @if ($url = $this->registerUrl())
                <a href="{{ $url }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Employee register</a>
            @endif
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2" aria-live="polite">
            <p class="pos-caption pos-muted">{{ number_format($this->total) }} {{ \Illuminate\Support\Str::plural('person', $this->total) }}</p>
            @if ($f['manager'])
                <span class="pos-chip" aria-pressed="true">Reporting to {{ $f['manager'] }}</span>
            @endif
            @if ($search !== '' || $department || $location || $status || $manager)
                <button type="button" class="pos-link pos-caption" wire:click="clearFilters">Clear filters</button>
            @endif
        </div>

        <div wire:loading.delay.short wire:target="search, department, location, status, manager" class="pos-people-grid mt-4" aria-hidden="true">
            @for ($i = 0; $i < 6; $i++)<div class="pos-card"><div class="pos-skeleton size-12 rounded-full"></div><div class="pos-skeleton mt-3 h-4 w-2/3"></div><div class="pos-skeleton mt-2 h-3 w-1/2"></div></div>@endfor
        </div>

        <div wire:loading.remove wire:target="search, department, location, status, manager">
            @if ($display === 'changed')
                {{-- Recently changed: people with changes you may see in the last 30 days --}}
                @if ($this->changed->isEmpty())
                    <x-pos.state class="mt-6" variant="empty" title="No recent changes you can see." why="Joiners, moves, reporting changes and exits from the last 30 days appear here." />
                @else
                    <div class="pos-panel pos-stream mt-4">
                        @foreach ($this->changed as $c)
                            <div class="pos-stream-row" data-tone="{{ $c['latest']['tone'] === 'primary' ? 'info' : $c['latest']['tone'] }}" wire:key="ch-{{ $c['person_id'] }}">
                                <x-pos.person :id="$c['person_id']" :name="$c['name']" size="sm" />
                                <span class="pos-stream-body"><span class="pos-stream-title">{{ $c['latest']['title'] }}</span>
                                    <span class="pos-stream-meta">{{ $c['latest']['label'] }} · {{ $c['latest']['at']->diffForHumans() }}@if ($c['count'] > 1) · {{ $c['count'] - 1 }} more {{ $c['count'] - 1 === 1 ? 'change' : 'changes' }}@endif</span></span>
                                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'change', id: @js($c['latest']['id']) })">What changed</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @elseif ($this->people->isEmpty())
                @if ($search !== '' || $department || $location || $status || $manager)
                    <x-pos.state class="mt-6" variant="filtered" title="No one matches these filters." why="Try fewer filters or a shorter name.">
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="clearFilters">Clear filters</button>
                    </x-pos.state>
                @else
                    <x-pos.state class="mt-6" variant="empty" title="No people to show yet." why="People you can see appear here once they are added." />
                @endif
            @else
                @foreach ($this->grouped() as $groupLabel => $members)
                    <section class="mt-4" @if ($groupLabel !== '') aria-label="{{ $groupLabel }}" @endif>
                        @if ($groupLabel !== '')
                            <h2 class="pos-sec-title mb-2">{{ $groupLabel }}<span class="pos-sec-count">{{ $members->count() }}</span></h2>
                        @endif
                        @if ($display === 'list')
                            <div class="pos-panel overflow-x-auto">
                                <table class="pos-table">
                                    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col" class="hidden md:table-cell">Department</th><th scope="col" class="hidden lg:table-cell">Location</th><th scope="col" class="hidden lg:table-cell">Manager</th><th scope="col">Status</th></tr></thead>
                                    <tbody>
                                        @foreach ($members as $e)
                                            @php($p = $e->currentPosition)
                                            @php($m = $e->currentManager?->manager)
                                            <tr wire:key="pl-{{ $e->id }}">
                                                <td><x-pos.person :id="$e->id" :name="$e->display_name" /></td>
                                                <td class="pos-secondary">{{ $p?->designation?->name ?? '—' }}</td>
                                                <td class="hidden md:table-cell pos-secondary">{{ $p?->department?->name ?? '—' }}</td>
                                                <td class="hidden lg:table-cell pos-secondary">{{ $p?->location?->name ?? '—' }}</td>
                                                <td class="hidden lg:table-cell">@if ($m)<x-pos.person :id="$m->id" :name="$m->person?->display_name" :avatar="false" />@else<span class="pos-muted">—</span>@endif</td>
                                                <td>@if ($e->lifecycle_state)<x-pos.status :tone="in_array($e->lifecycle_state->value, ['notice_period', 'suspended'], true) ? 'warning' : (in_array($e->lifecycle_state->value, ['probation', 'onboarding', 'preboarding', 'pre_employee'], true) ? 'info' : 'neutral')" :label="$e->lifecycle_state->getLabel()" />@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <ul class="pos-people-grid">
                                @foreach ($members as $e)
                                    @php($p = $e->currentPosition)
                                    <li wire:key="pg-{{ $e->id }}">
                                        <button type="button" class="pos-card pos-card-interactive pos-person-card" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $e->id }} })" aria-label="Preview {{ $e->display_name }}">
                                            <x-pos.avatar :name="$e->display_name" size="lg" />
                                            <span class="pos-body font-medium mt-3 block truncate">{{ $e->display_name }}</span>
                                            <span class="pos-caption pos-secondary block truncate">{{ $p?->designation?->name ?? $e->employee_code }}</span>
                                            <span class="pos-caption pos-muted block truncate">{{ collect([$p?->department?->name, $p?->location?->name])->filter()->implode(' · ') }}</span>
                                            @if (in_array($e->lifecycle_state?->value, ['probation', 'notice_period', 'on_leave', 'preboarding', 'pre_employee', 'onboarding'], true))
                                                <x-pos.status class="mt-2" :tone="$e->lifecycle_state->value === 'notice_period' ? 'warning' : 'info'" :label="$e->lifecycle_state->getLabel()" />
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            @endif

            @if ($display !== 'changed' && $this->people->count() < $this->total)
                <div class="mt-4 text-center"><button type="button" class="pos-btn pos-btn-secondary" wire:click="loadMore">Show more ({{ $this->total - $this->people->count() }} more)</button></div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
