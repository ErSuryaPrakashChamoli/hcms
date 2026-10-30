<x-filament-panels::page>
    @php
        $groups = $this->groups;
        $pending = collect(['urgent', 'today', 'upcoming'])->flatMap(fn ($k) => $groups[$k])->values();
        // Rendered in pages, most urgent first: the queue rows and their decision cards for the first $shown.
        $visible = $pending->take($this->shown);
        $onPage = $visible->pluck('id')->flip();
        $remaining = $pending->count() - $visible->count();
        $labels = ['urgent' => ['Urgent', 'Overdue, starting within a day, or flagged'], 'today' => ['This week', 'Due soon or waiting two days'], 'upcoming' => ['Later', 'Later this month and beyond']];
        $posAi = app(\App\Domain\Ai\Services\AiGateway::class)->assistantsFor(auth()->user());
        // HROne setup parity P3: items that may be approved together, and the discussions of the listed items in one read.
        $bulk = collect($this->bulkIds())->flip();
        app(\App\Domain\Workflow\Services\TaskComments::class)->prime($visible);
    @endphp

    <div class="pos-ws" x-data="{ selected: @js($pending->first()?->id), picked: [], bulkNote: '', wide: window.matchMedia('(min-width: 1280px)').matches,
            select(id, focusOnly = false) { if (this.wide || focusOnly) { this.selected = id } else { $dispatch('pos-drawer-open', { type: 'approval', id }) } } }"
        x-on:resize.window.debounce.150ms="wide = window.matchMedia('(min-width: 1280px)').matches"
        x-on:pos-approval-decided.window="$nextTick(() => { const next = document.querySelector('.pos-queue-row'); selected = next ? next.dataset.approvalId : null })"
        x-on:pos-approval-bulk-done.window="picked = []; bulkNote = ''">

        <div class="pos-lens">
            @if (isset($posAi['manager']) && $pending->isNotEmpty())
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="$dispatch('pos-ai-open', { assistant: 'manager', prompt: 'What is pending for me?' })">
                    <span class="pos-ai-orb pos-ai-orb-sm" aria-hidden="true"></span> Summarise what is pending
                </button>
            @endif
            <span class="pos-meta ms-auto hidden md:inline" aria-hidden="true"><span class="pos-kbd">J</span> <span class="pos-kbd">K</span> move · <span class="pos-kbd">A</span> approve · <span class="pos-kbd">R</span> reject · <span class="pos-kbd">Esc</span> cancel</span>
        </div>

        {{-- Keyboard list navigation (J/K move, A/R decide, Enter open) over the queue, always wired --}}
        <div class="contents" x-data="posListNav('.pos-approval')" x-on:keydown.window="handle($event)">
        @if ($pending->isEmpty())
            <x-pos.state variant="caught-up" title="You’re all caught up." why="No decisions require your attention right now. Leave, attendance corrections, pay changes, letters and workflow steps that need you will appear here, with the context to decide." />
        @else
            <div class="pos-decide-ws">
                {{-- The queue: most urgent first; selecting shows the decision beside it (a drawer on smaller screens) --}}
                <div class="pos-queue">
                    <x-pos.intelligence :intel="$this->intelligence" />
                    @if (($oldCount = $this->bulkArchiveCount()) > 0)
                        {{-- HROne setup parity P4 (Archive inbox, manual): clear what has waited longer than the archive period --}}
                        <div class="pos-panel pos-panel-pad flex flex-wrap items-center gap-2" role="group" aria-label="Archive requests waiting too long">
                            <span class="pos-meta">{{ $oldCount }} waiting more than {{ app(\App\Domain\Workflow\Services\InboxArchives::class)->afterDays() }} days</span>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm ms-auto" wire:click="archiveOld" wire:loading.attr="disabled" wire:target="archiveOld">Archive them</button>
                        </div>
                    @endif
                    @if ($bulk->isNotEmpty())
                        {{-- HROne setup parity P3 (Bulk approval): tick requests of the types switched on and approve them together --}}
                        <div class="pos-panel pos-panel-pad flex flex-wrap items-center gap-2" role="group" aria-label="Approve several at once">
                            <span class="pos-meta" x-text="picked.length ? picked.length + ' selected' : 'Tick the requests to approve together'"></span>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="picked = @js($bulk->keys()->values()->all())">Select all ({{ $bulk->count() }})</button>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-show="picked.length" x-on:click="picked = []">Clear</button>
                            <label class="sr-only" for="pos-bulk-note">Note for the approvals (optional)</label>
                            <textarea id="pos-bulk-note" x-show="picked.length" x-model="bulkNote" rows="1" maxlength="2000" class="pos-textarea" placeholder="Note (optional)"></textarea>
                            <button type="button" class="pos-btn pos-btn-success pos-btn-sm ms-auto" x-bind:disabled="! picked.length" x-on:click="$wire.approveSelected(picked, bulkNote)"
                                wire:loading.attr="disabled" wire:target="approveSelected">Approve selected</button>
                        </div>
                    @endif
                    @foreach ($labels as $key => [$label, $hint])
                        @if ($groups[$key]->isNotEmpty())
                            <section class="pos-sec" aria-labelledby="pos-q-{{ $key }}">
                                <header class="pos-sec-head">
                                    <h2 id="pos-q-{{ $key }}" class="pos-sec-title">{{ $label }}<span class="pos-sec-count">{{ $groups[$key]->count() }}</span></h2>
                                    <span class="pos-sec-sub">{{ $hint }}</span>
                                </header>
                                {{-- UX.18: a real list; each decision button sits in its own list item (role=listitem is not allowed on a button) --}}
                                <ul class="pos-panel pos-stream pos-queue-list" role="list">
                                    @foreach ($groups[$key]->filter(fn ($i) => $onPage->has($i->id)) as $item)
                                        <li class="pos-queue-item" wire:key="q-{{ md5($item->id) }}" wire:transition>
                                        @if ($bulk->has($item->id))<div class="flex items-center"><label class="shrink-0 p-3"><span class="sr-only">Select {{ $item->title }}</span><input type="checkbox" value="{{ $item->id }}" x-model="picked"></label>@endif
                                        <button type="button" class="pos-stream-row pos-queue-row pos-approval{{ $bulk->has($item->id) ? ' min-w-0 flex-1' : '' }}" data-approval-id="{{ $item->id }}"
                                            data-tone="{{ $key === 'urgent' ? 'danger' : ($key === 'today' ? 'warning' : 'info') }}"
                                            :aria-current="(selected === @js($item->id)).toString()" x-on:click="select(@js($item->id))" x-on:focus="select(@js($item->id), true)">
                                            <span class="pos-stream-mark" aria-hidden="true"></span>
                                            <span class="pos-stream-body">
                                                <span class="pos-stream-title">{{ $item->subject ? $item->subject.' · ' : '' }}{{ $item->title }}</span>
                                                <span class="pos-stream-meta">{{ $item->typeLabel }}@if ($item->effectiveOn) · from {{ $item->effectiveOn->format('D j M') }}@elseif ($item->dueAt) · due {{ $item->dueAt->diffForHumans() }}@endif</span>
                                            </span>
                                            <span data-open class="pos-muted" aria-hidden="true">→</span>
                                        </button>
                                        @if ($bulk->has($item->id))</div>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    @endforeach
                    @if ($remaining > 0)
                        <button type="button" class="pos-btn pos-btn-secondary w-full" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore">
                            Show {{ min($remaining, \App\Filament\Pages\Approvals::PAGE) }} more <span class="pos-meta">· {{ $remaining }} not shown yet</span>
                        </button>
                    @endif
                </div>

                {{-- The decision, with its context --}}
                <div class="pos-decision-pane" aria-live="polite">
                    @foreach ($visible as $item)
                        <div x-show="selected === @js($item->id)" @if (! $loop->first) x-cloak @endif wire:key="d-{{ md5($item->id) }}"
                            x-transition:enter="pos-swap-in" x-transition:leave="hidden">
                            <x-pos.approval-card :item="$item" :archivable="true" :trailable="true" :trail="$this->trailFor($item)" class="pos-approval-detail" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        </div>

        @if ($this->archivedItems->isNotEmpty())
            {{-- HROne setup parity P4: archived items still wait for a decision; restoring brings them back into the queue --}}
            <section class="pos-sec" aria-labelledby="pos-group-archived" x-data="{ open: false }">
                <header class="pos-sec-head">
                    <h2 id="pos-group-archived" class="pos-sec-title">Archived<span class="pos-sec-count">{{ $this->archivedItems->count() }}</span></h2>
                    <button type="button" class="pos-link" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="pos-archived-list"><span x-text="open ? 'Hide' : 'Show'"></span></button>
                </header>
                <ul id="pos-archived-list" class="pos-panel pos-stream" x-show="open" x-collapse>
                    @foreach ($this->archivedItems as $archived)
                        <li class="pos-stream-row" data-tone="info" wire:key="a-{{ md5($archived->type.$archived->id) }}">
                            <span class="pos-stream-mark" aria-hidden="true"></span>
                            <div class="pos-stream-body">
                                <p class="pos-stream-title">{{ $archived->subject ? $archived->subject.' · ' : '' }}{{ $archived->title }}</p>
                                <p class="pos-stream-meta">{{ $archived->typeLabel }} · still pending</p>
                            </div>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="$wire.restore(@js($archived->id), @js($archived->type))">Restore</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($groups['completed']->isNotEmpty())
            <section class="pos-sec" aria-labelledby="pos-group-completed" x-data="{ open: false }">
                <header class="pos-sec-head">
                    <h2 id="pos-group-completed" class="pos-sec-title">Completed<span class="pos-sec-count">{{ $groups['completed']->count() }}</span></h2>
                    <button type="button" class="pos-link" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="pos-completed-list"><span x-text="open ? 'Hide' : 'Show your last two weeks'"></span></button>
                </header>
                <ul id="pos-completed-list" class="pos-panel pos-stream" x-show="open" x-collapse>
                    @foreach ($groups['completed'] as $done)
                        <li class="pos-stream-row" data-tone="success">
                            <span class="pos-stream-mark" aria-hidden="true"></span>
                            <div class="pos-stream-body">
                                <p class="pos-stream-title">{{ $done['title'] }}</p>
                                <p class="pos-stream-meta">{{ $done['type'] }}@if ($done['subject']) · {{ $done['subject'] }}@endif · {{ $done['status'] }} · {{ $done['at']?->diffForHumans() }}</p>
                            </div>
                            @if ($done['url'])<a href="{{ $done['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open</a>@else<span></span>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
