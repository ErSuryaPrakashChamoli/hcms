<x-filament-panels::page>
    @php
        $counts = $this->counts;
        $listed = $this->listed;
        $facets = $this->facets;
        $current = $this->current;
        $position = $this->position;
        $options = $this->options;
        $insight = $this->insight;
        $people = $this->insightPeople;
        $read = collect($this->readIds)->flip();
        $visible = $listed->take($this->shown);
        $remaining = $listed->count() - $visible->count();
        $bulk = collect($this->bulkIds())->flip();
        $archivable = collect($this->archivableIds())->flip();
        $pendingView = ! in_array($this->show, ['missed', 'archived', 'not_relevant'], true);
        $applied = collect($this->filters)->filter(fn ($v) => $v !== '')->count();
        $views = ['all' => 'All', 'unread' => 'Unread', 'overdue' => 'Overdue', 'due_today' => 'Due today', 'due_tomorrow' => 'Due tomorrow'];
        $quick = ['overdue' => 'Overdue', 'due_today' => 'Due today', 'due_tomorrow' => 'Due tomorrow', 'missed' => 'Missed by me', 'relieved' => 'Relieved employees'];
        $allLabel = \App\Domain\Experience\Services\UnifiedInbox::SHOWS[$this->show] ?? 'All';
        $canIgnore = auth()->user()->hasPermission('task.ignore');
        // HROne setup parity P3: the discussions of the open item, read once.
        if ($current?->isDecision()) {
            app(\App\Domain\Workflow\Services\TaskComments::class)->prime(collect([$current->item]));
        }
    @endphp

    <div class="pos-ws" data-unified-inbox x-data="{ wide: window.matchMedia('(min-width: 1280px)').matches, picked: [], bulkNote: '' }"
        x-on:resize.window.debounce.150ms="wide = window.matchMedia('(min-width: 1280px)').matches"
        x-on:pos-inbox-bulk-done.window="picked = []; bulkNote = ''">

        {{-- §20.2.1 #7: the approver's own responsiveness (decided, on time, delayed) --}}
        <section class="pos-panel pos-panel-pad" aria-label="Insight" data-inbox-insight>
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="pos-label">Insight</h2>
                <select wire:model.live="insightDays" class="pos-select" aria-label="Period">
                    @foreach (\App\Domain\Experience\Services\InboxInsight::PERIODS as $days => $label)<option value="{{ $days }}">{{ $label }}</option>@endforeach
                </select>
                @if (count($people) > 1)
                    <select wire:model.live="insightUser" class="pos-select" aria-label="Whose insight">
                        @foreach ($people as $id => $name)<option value="{{ (int) $id === (int) auth()->id() ? '' : $id }}">{{ $name }}</option>@endforeach
                    </select>
                @endif
                <span class="pos-body-sm" data-insight-decided>Decided <strong class="pos-num">{{ $insight['decided'] }}</strong></span>
                <span class="pos-body-sm" data-insight-on-time>On time <strong class="pos-num">{{ $insight['on_time_pct'] === null ? '—' : $insight['on_time_pct'].'%' }}</strong></span>
                <span class="pos-body-sm" data-insight-delayed>Delayed <strong class="pos-num">{{ $insight['delayed_pct'] === null ? '—' : $insight['delayed_pct'].'%' }}</strong></span>
            </div>
            <p class="pos-caption pos-muted mt-2">{{ $insight['person'] }}: workflow steps and Approval Center decisions{{ $insight['timed'] < $insight['decided'] ? ' ('.$insight['timed'].' of them had a due time)' : '' }}. Decisions taken on a module's own screen are not counted.</p>
        </section>

        {{-- §20.2.1 #9 / #12: the "All messages" views and the quick filters; Archive and Not relevant --}}
        <nav class="pos-lens pos-lens-scroll" aria-label="Show">
            <label class="sr-only" for="pos-inbox-view">Show</label>
            <select id="pos-inbox-view" class="pos-select" wire:change="setShow($event.target.value)" aria-label="Messages">
                @foreach ($views as $key => $label)<option value="{{ $key }}" @selected($this->show === $key)>{{ $label }} ({{ $counts[$key] }})</option>@endforeach
                @if (! isset($views[$this->show]))<option value="{{ $this->show }}" selected>{{ $allLabel }}</option>@endif
            </select>
            @foreach ($quick as $key => $label)
                <button type="button" class="pos-chip" aria-pressed="{{ $this->show === $key ? 'true' : 'false' }}" wire:click="setShow(@js($this->show === $key ? 'all' : $key))" data-show="{{ $key }}">
                    {{ $label }}@if ($counts[$key] > 0)<span class="pos-count">{{ $counts[$key] }}</span>@endif
                </button>
            @endforeach
            @if ($counts['archived'] > 0 || $this->show === 'archived')
                <button type="button" class="pos-chip" aria-pressed="{{ $this->show === 'archived' ? 'true' : 'false' }}" wire:click="setShow(@js($this->show === 'archived' ? 'all' : 'archived'))" data-show="archived">Archive<span class="pos-count">{{ $counts['archived'] }}</span></button>
            @endif
            @if ($counts['not_relevant'] > 0 || $canIgnore)
                <button type="button" class="pos-chip" aria-pressed="{{ $this->show === 'not_relevant' ? 'true' : 'false' }}" wire:click="setShow(@js($this->show === 'not_relevant' ? 'all' : 'not_relevant'))" data-show="not_relevant">Not relevant @if ($counts['not_relevant'] > 0)<span class="pos-count">{{ $counts['not_relevant'] }}</span>@endif</button>
            @endif
            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm ms-auto" wire:click="setFocus(@js(! $this->focus))" aria-pressed="{{ $this->focus ? 'true' : 'false' }}" data-focus-toggle>{{ $this->focus ? 'Back to the list' : 'One at a time' }}</button>
        </nav>

        <div class="grid gap-4 lg:grid-cols-4">
            {{-- §20.2.1 #4 / #5: request types with counts, and a search over their names --}}
            <aside class="pos-panel pos-panel-pad" aria-label="Request types" x-data="{ q: '' }" data-inbox-facets>
                <label class="pos-search">
                    <span class="sr-only">Search by name</span>
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                    <input type="search" x-model="q" placeholder="Search by name" autocomplete="off" />
                </label>
                <ul class="mt-3 space-y-1" role="list">
                    <li>
                        <button type="button" class="pos-chip w-full justify-between" aria-pressed="{{ $this->facet === '' ? 'true' : 'false' }}" wire:click="setFacet('')" data-facet="">
                            {{ $allLabel }}<span class="pos-count">{{ $this->rows->count() }}</span>
                        </button>
                    </li>
                    @foreach ($facets as $key => $facet)
                        <li x-show="! q || @js(\Illuminate\Support\Str::lower($facet['label'])).includes(q.toLowerCase())" wire:key="facet-{{ md5($key) }}">
                            <button type="button" class="pos-chip w-full justify-between" aria-pressed="{{ $this->facet === $key ? 'true' : 'false' }}" wire:click="setFacet(@js($key))" data-facet="{{ $key }}">
                                <span class="truncate">{{ $facet['label'] }}</span><span class="pos-count">{{ $facet['count'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </aside>

            <div class="lg:col-span-3 min-w-0 space-y-2">
                {{-- §20.2.1 #10 / #11, R.3.2 #1: keyword search and the filter panel (the request type's own filters when it has them) --}}
                <div class="pos-toolbar" x-data="{ filters: false }">
                    <label class="pos-search">
                        <span class="sr-only">Search tasks</span>
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search tasks" autocomplete="off" />
                    </label>
                    <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" x-on:click="filters = ! filters" :aria-expanded="filters.toString()" aria-expanded="false" aria-controls="pos-inbox-filters">
                        <x-filament::icon icon="heroicon-m-funnel" class="size-4" /> Filters @if ($applied > 0)<span class="pos-count" data-filters-applied>{{ $applied }}</span>@endif
                    </button>
                    @if ($applied > 0 || $this->search !== '')
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="clearFilters">Clear all</button>
                    @endif
                    <div id="pos-inbox-filters" class="pos-toolbar-filters" x-show="filters" x-cloak :data-open="filters.toString()" data-inbox-filters>
                        <input type="text" wire:model.live.debounce.400ms="filters.employee" class="pos-select" placeholder="Employee code or name" aria-label="Employee code or name">
                        <input type="date" wire:model.live="filters.from" class="pos-select" aria-label="Requested from" title="Requested from">
                        <input type="date" wire:model.live="filters.until" class="pos-select" aria-label="Requested until" title="Requested until">
                        @if ($options['leave_type'] !== [] || $this->filters['leave_type'] !== '')
                            <select wire:model.live="filters.leave_type" class="pos-select" aria-label="Leave type" data-filter="leave_type">
                                <option value="">Any leave type</option>
                                @foreach ($options['leave_type'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        @endif
                        @if ($options['ticket_category'] !== [] || $this->filters['ticket_category'] !== '')
                            <select wire:model.live="filters.ticket_category" class="pos-select" aria-label="Ticket category" data-filter="ticket_category">
                                <option value="">Any category</option>
                                @foreach ($options['ticket_category'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        @endif
                        @if ($options['travel'] || $this->filters['travel'] !== '')
                            <input type="text" wire:model.live.debounce.400ms="filters.travel" class="pos-select" placeholder="Travel request (number or purpose)" aria-label="Travel request" data-filter="travel">
                        @endif
                        @if ($options['unit'] !== [] || $this->filters['unit'] !== '')
                            <select wire:model.live="filters.unit" class="pos-select" aria-label="Organisation unit" data-filter="unit">
                                <option value="">Any organisation unit</option>
                                @foreach ($options['unit'] as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
                            </select>
                        @endif
                    </div>
                </div>

                @if ($listed->isEmpty())
                    @if ($this->search !== '' || $applied > 0)
                        <x-pos.state variant="filtered" title="We couldn’t find anything." why="Check your spelling or try another keyword, or clear the filters." />
                    @elseif ($pendingView && $this->show === 'all' && $this->facet === '')
                        <x-pos.state variant="caught-up" title="You’re all caught up." why="Decisions and tasks that need you appear here, by request type." />
                    @else
                        <x-pos.state variant="filtered" title="Nothing here." why="Choose All to see everything waiting for you." />
                    @endif
                @else
                    @if (! $this->focus && ($bulk->isNotEmpty() || $archivable->isNotEmpty()))
                        {{-- §20.2.1 #33 (and P3 bulk approval): tick items, then approve or archive them together --}}
                        <div class="pos-panel pos-panel-pad flex flex-wrap items-center gap-2" role="group" aria-label="Act on several at once" data-inbox-bulk>
                            <span class="pos-meta" x-text="picked.length ? picked.length + ' selected' : 'Tick items to act on them together'"></span>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-show="picked.length" x-on:click="picked = []">Clear</button>
                            @if ($bulk->isNotEmpty())
                                <label class="sr-only" for="pos-inbox-bulk-note">Note for the approvals (optional)</label>
                                <textarea id="pos-inbox-bulk-note" x-show="picked.length" x-model="bulkNote" rows="1" maxlength="2000" class="pos-textarea" placeholder="Note (optional)"></textarea>
                                <button type="button" class="pos-btn pos-btn-success pos-btn-sm ms-auto" x-bind:disabled="! picked.length" x-on:click="$wire.approveSelected(picked.filter(id => @js($bulk->keys()->values()->all()).includes(id)), bulkNote)"
                                    wire:loading.attr="disabled" wire:target="approveSelected">Approve selected</button>
                            @endif
                            @if ($archivable->isNotEmpty())
                                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm{{ $bulk->isEmpty() ? ' ms-auto' : '' }}" x-bind:disabled="! picked.length" x-on:click="$wire.archiveSelected(picked.filter(id => @js($archivable->keys()->values()->all()).includes(id)))"
                                    wire:loading.attr="disabled" wire:target="archiveSelected" data-archive-selected>Move to archive</button>
                            @endif
                        </div>
                    @endif

                    <div class="{{ $this->focus ? '' : 'pos-decide-ws' }}">
                        @unless ($this->focus)
                            {{-- The list (§20.2.1 #13 / #14): one line per item with its type, when it was raised and how much time is left --}}
                            <div class="pos-queue">
                                <ul class="pos-panel pos-stream pos-queue-list" role="list" aria-label="Inbox" data-inbox-list>
                                    @foreach ($visible as $entry)
                                        @php
                                            $due = $entry->dueLabel();
                                        @endphp
                                        <li class="pos-queue-item" wire:key="row-{{ md5($entry->source.$entry->id) }}">
                                            <div class="flex items-center">
                                                @if ($bulk->has($entry->id) || $archivable->has($entry->id))
                                                    <label class="shrink-0 p-3"><span class="sr-only">Select {{ $entry->title }}</span><input type="checkbox" value="{{ $entry->id }}" x-model="picked"></label>
                                                @endif
                                                <button type="button" class="pos-stream-row pos-queue-row min-w-0 flex-1" data-inbox-id="{{ $entry->id }}" data-tone="{{ $due['tone'] ?? 'info' }}"
                                                    aria-current="{{ $current?->id === $entry->id ? 'true' : 'false' }}" x-on:click="$wire.open(@js($entry->id), ! wide)">
                                                    <span class="pos-stream-mark" aria-hidden="true"></span>
                                                    <span class="pos-stream-body">
                                                        <span class="pos-stream-title">{{ $entry->subject ? $entry->subject.' · ' : '' }}{{ $entry->title }}</span>
                                                        <span class="pos-stream-meta flex flex-wrap items-center gap-x-1">
                                                            <span>{{ $entry->facetLabel }}</span>
                                                            @if ($entry->requestedAt)<span>· <time datetime="{{ $entry->requestedAt->toIso8601String() }}">{{ $entry->requestedAt->format('d M, H:i') }}</time></span>@endif
                                                            @if ($due)<x-pos.status :tone="$due['tone']" :label="$due['label']" data-due-label />@endif
                                                            @if ($pendingView && ! $read->has($entry->id))<span class="pos-meta" data-unread>· new</span>@endif
                                                        </span>
                                                    </span>
                                                </button>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($remaining > 0)
                                    <button type="button" class="pos-btn pos-btn-secondary w-full mt-2" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore">
                                        Show {{ min($remaining, \App\Filament\Pages\Inbox::PAGE) }} more <span class="pos-meta">· {{ $remaining }} not shown yet</span>
                                    </button>
                                @endif
                            </div>
                        @endunless

                        {{-- The open item with its context (§20.2.1 #1 / #16 / #32) --}}
                        <div class="{{ $this->focus ? 'space-y-2' : 'pos-decision-pane' }}" aria-live="polite" data-inbox-detail>
                            @if ($this->focus)
                                <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Move through the list" data-inbox-pager>
                                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="setFocus(false)">← List</button>
                                    <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="previous" @disabled($position['previous'] === null) data-previous>Previous</button>
                                    <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="next" @disabled($position['next'] === null) data-next>Next</button>
                                    <span class="pos-meta ms-auto">{{ $position['index'] }} of {{ $position['total'] }}</span>
                                </div>
                            @endif
                            @if ($current !== null)
                                @include('filament.pages.experience.inbox-detail', ['entry' => $current, 'peek' => $this->peek, 'more' => $this->more, 'ignorable' => $this->mayIgnore($current),
                                    'restorable' => $this->mayRestoreTask($current), 'trail' => $current->isDecision() ? $this->trailFor($current->item) : null])
                            @elseif ($this->focus)
                                <x-pos.state variant="filtered" title="Choose an item." why="Pick one from the list, or use Next." />
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
