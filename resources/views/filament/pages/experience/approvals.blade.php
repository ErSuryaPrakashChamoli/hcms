<x-filament-panels::page>
    @php($groups = $this->groups)
    <div class="pos-approvals" x-data="posListNav('.pos-approval')" x-on:keydown.window="handle($event)">
        @php($posAi = app(\App\Domain\Ai\Services\AiGateway::class)->assistantsFor(auth()->user()))
        @if (isset($posAi['manager']))
            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm mb-2" x-data x-on:click="$dispatch('pos-ai-open', { assistant: 'manager', prompt: 'What is pending for me?' })">
                <span class="pos-ai-orb pos-ai-orb-sm" aria-hidden="true"></span> Summarise what is pending
            </button>
        @endif
        <p class="pos-caption pos-muted hidden md:block" aria-hidden="true">
            <span class="pos-kbd">J</span> <span class="pos-kbd">K</span> move · <span class="pos-kbd">A</span> approve · <span class="pos-kbd">R</span> reject · <span class="pos-kbd">Esc</span> cancel
        </p>

        @if ($this->pendingCount === 0 && $groups['completed']->isEmpty())
            <x-pos.empty class="mt-6" icon="heroicon-o-check-badge" title="No approvals waiting"
                why="Leave, attendance corrections, compensation changes, letters and workflow steps that need your decision will appear here, with the context to decide." />
        @endif

        @foreach (['urgent' => ['Urgent', 'Overdue, starting soon or flagged'], 'today' => ['Today', 'Due today, starting this week or waiting two days'], 'upcoming' => ['Upcoming', 'Later this month and beyond']] as $key => [$label, $hint])
            @if ($groups[$key]->isNotEmpty())
                <section class="pos-group mt-6" aria-labelledby="pos-group-{{ $key }}">
                    <header class="pos-group-head">
                        <h2 id="pos-group-{{ $key }}" class="pos-h3">{{ $label }} <span class="pos-count">{{ $groups[$key]->count() }}</span></h2>
                        <p class="pos-caption pos-muted">{{ $hint }}</p>
                    </header>
                    <div class="pos-approval-list mt-3">
                        @foreach ($groups[$key] as $item)
                            <x-pos.approval-card :item="$item" wire:key="appr-{{ $item->id }}" wire:transition />
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach

        @if ($groups['completed']->isNotEmpty())
            <section class="pos-group mt-8" aria-labelledby="pos-group-completed" x-data="{ open: false }">
                <header class="pos-group-head">
                    <h2 id="pos-group-completed" class="pos-h3">Completed <span class="pos-count">{{ $groups['completed']->count() }}</span></h2>
                    <button type="button" class="pos-link" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="pos-completed-list"><span x-text="open ? 'Hide' : 'Show your last two weeks'"></span></button>
                </header>
                <ul id="pos-completed-list" class="pos-list pos-card mt-3" x-show="open" x-collapse>
                    @foreach ($groups['completed'] as $done)
                        <li class="pos-list-row">
                            <x-filament::icon icon="heroicon-m-check-circle" class="size-5 shrink-0 text-pos-success" />
                            <div class="min-w-0 flex-1">
                                <p class="pos-body truncate">{{ $done['title'] }}</p>
                                <p class="pos-caption pos-muted">{{ $done['type'] }}@if ($done['subject']) · {{ $done['subject'] }}@endif · {{ $done['status'] }} · {{ $done['at']?->diffForHumans() }}</p>
                            </div>
                            @if ($done['url'])<a href="{{ $done['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open</a>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
