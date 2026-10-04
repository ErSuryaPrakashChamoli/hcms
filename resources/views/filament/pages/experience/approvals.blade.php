<x-filament-panels::page>
    @php
        $groups = $this->groups;
        $pending = collect(['urgent', 'today', 'upcoming'])->flatMap(fn ($k) => $groups[$k])->values();
        $labels = ['urgent' => ['Urgent', 'Overdue, starting within a day, or flagged'], 'today' => ['This week', 'Due soon or waiting two days'], 'upcoming' => ['Later', 'Later this month and beyond']];
        $posAi = app(\App\Domain\Ai\Services\AiGateway::class)->assistantsFor(auth()->user());
    @endphp

    <div class="pos-ws" x-data="{ selected: @js($pending->first()?->id), wide: window.matchMedia('(min-width: 1280px)').matches,
            select(id, focusOnly = false) { if (this.wide || focusOnly) { this.selected = id } else { $dispatch('pos-drawer-open', { type: 'approval', id }) } } }"
        x-on:resize.window.debounce.150ms="wide = window.matchMedia('(min-width: 1280px)').matches"
        x-on:pos-approval-decided.window="$nextTick(() => { const next = document.querySelector('.pos-queue-row'); selected = next ? next.dataset.approvalId : null })">

        <div class="pos-lens">
            @if (isset($posAi['manager']) && $pending->isNotEmpty())
                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-on:click="$dispatch('pos-ai-open', { assistant: 'manager', prompt: 'What is pending for me?' })">
                    <span class="pos-ai-orb pos-ai-orb-sm" aria-hidden="true"></span> Summarise what is pending
                </button>
            @endif
            <span class="pos-meta ms-auto hidden md:inline" aria-hidden="true"><span class="pos-kbd">J</span> <span class="pos-kbd">K</span> move · <span class="pos-kbd">A</span> approve · <span class="pos-kbd">R</span> reject · <span class="pos-kbd">Esc</span> cancel</span>
        </div>

        @if ($pending->isEmpty())
            <x-pos.state variant="caught-up" title="You’re all caught up." why="No decisions require your attention right now. Leave, attendance corrections, pay changes, letters and workflow steps that need you will appear here, with the context to decide." />
        @else
            <div class="pos-decide-ws">
                {{-- The queue: most urgent first; selecting shows the decision beside it (a drawer on smaller screens) --}}
                <div class="pos-queue" x-data="posListNav('.pos-queue-row')" x-on:keydown.window="handle($event)">
                    @foreach ($labels as $key => [$label, $hint])
                        @if ($groups[$key]->isNotEmpty())
                            <section class="pos-sec" aria-labelledby="pos-q-{{ $key }}">
                                <header class="pos-sec-head">
                                    <h2 id="pos-q-{{ $key }}" class="pos-sec-title">{{ $label }}<span class="pos-sec-count">{{ $groups[$key]->count() }}</span></h2>
                                    <span class="pos-sec-sub">{{ $hint }}</span>
                                </header>
                                <div class="pos-panel pos-stream" role="list">
                                    @foreach ($groups[$key] as $item)
                                        <button type="button" role="listitem" class="pos-stream-row pos-queue-row" data-approval-id="{{ $item->id }}" wire:key="q-{{ md5($item->id) }}"
                                            data-tone="{{ $key === 'urgent' ? 'danger' : ($key === 'today' ? 'warning' : 'info') }}"
                                            :aria-current="(selected === @js($item->id)).toString()" x-on:click="select(@js($item->id))" x-on:focus="select(@js($item->id), true)">
                                            <span class="pos-stream-mark" aria-hidden="true"></span>
                                            <span class="pos-stream-body">
                                                <span class="pos-stream-title">{{ $item->subject ? $item->subject.' · ' : '' }}{{ $item->title }}</span>
                                                <span class="pos-stream-meta">{{ $item->typeLabel }}@if ($item->effectiveOn) · from {{ $item->effectiveOn->format('D j M') }}@elseif ($item->dueAt) · due {{ $item->dueAt->diffForHumans() }}@endif</span>
                                            </span>
                                            <span data-open class="pos-muted" aria-hidden="true">→</span>
                                        </button>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    @endforeach
                </div>

                {{-- The decision, with its context --}}
                <div class="pos-decision-pane" aria-live="polite">
                    @foreach ($pending as $item)
                        <div x-show="selected === @js($item->id)" @if (! $loop->first) x-cloak @endif wire:key="d-{{ md5($item->id) }}">
                            <x-pos.approval-card :item="$item" class="pos-approval-detail" />
                        </div>
                    @endforeach
                </div>
            </div>
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
