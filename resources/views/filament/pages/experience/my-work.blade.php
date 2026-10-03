<x-filament-panels::page>
    @php($active = $this->activeTab())
    <div x-data="posListNav('.pos-work-row')" x-on:keydown.window="handle($event)">
        <nav class="pos-tabs" role="tablist" aria-label="My work">
            @foreach (\App\Filament\Pages\MyWork::TABS as $key => $label)
                @php($n = $this->inbox[$key]->count())
                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $active === $key ? 'true' : 'false' }}" class="pos-tab" wire:click="setTab('{{ $key }}')">
                    {{ $label }} @if ($n > 0)<span class="pos-count" data-tone="{{ $key === 'needs_attention' ? 'danger' : 'neutral' }}">{{ $n }}</span>@endif
                </button>
            @endforeach
        </nav>

        <section id="panel-{{ $active }}" role="tabpanel" aria-labelledby="tab-{{ $active }}" class="mt-4">
            @php($rows = $this->inbox[$active])
            @if ($rows->isEmpty())
                @php($empty = [
                    'needs_attention' => ['Nothing needs attention', 'Overdue tasks, urgent approvals and reminders that block you will show here.'],
                    'today' => ['Nothing due today', 'Things due today, and decisions starting this week, will show here.'],
                    'upcoming' => ['Nothing upcoming', 'Tasks and decisions further out will show here.'],
                    'waiting' => ['Nothing waiting on others', 'Your own requests (leave, corrections, HR requests, letters) show here while someone else has them.'],
                    'completed' => ['No recent decisions', 'What you decided in the last two weeks shows here.'],
                ][$active])
                <x-pos.empty icon="heroicon-o-inbox" :title="$empty[0]" :why="$empty[1]" />
            @else
                <ul class="pos-card pos-list" aria-label="{{ \App\Filament\Pages\MyWork::TABS[$active] }}">
                    @foreach ($rows as $row)
                        <li class="pos-list-row pos-work-row pos-attention" data-severity="{{ $row['severity'] }}" tabindex="-1" wire:key="work-{{ $row['key'] }}">
                            <span class="pos-severity" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="pos-body font-medium">{{ $row['title'] }}@if (($row['count'] ?? 0) > 1) <span class="pos-count">{{ $row['count'] }}</span>@endif</p>
                                <p class="pos-caption pos-muted">{{ $row['domain'] }}@if ($row['detail']) · {{ $row['detail'] }}@endif</p>
                            </div>
                            @if ($row['due'])
                                <time class="pos-caption {{ $row['due']->isPast() && $active !== 'completed' ? 'text-pos-danger' : 'pos-muted' }} hidden whitespace-nowrap sm:block" datetime="{{ $row['due']->toIso8601String() }}">
                                    {{ $active === 'completed' ? $row['due']->diffForHumans() : ($row['due']->isPast() ? 'Overdue '.$row['due']->diffForHumans(null, true) : 'Due '.$row['due']->diffForHumans()) }}
                                </time>
                            @endif
                            @if ($row['approval_id'])
                                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" data-open x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($row['approval_id']) })">Review</button>
                            @elseif ($row['url'])
                                <a href="{{ $row['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm" data-open>Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-panels::page>
