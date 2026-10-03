<x-filament-panels::page>
    @php
        $counts = $this->counts;
        $rows = $this->visible();
        $groups = ['all' => 'All'] + collect(\App\Domain\Experience\Support\NotificationCategories::GROUPS)->map(fn ($g) => $g[0])->all();
        $snoozedCount = $this->items->whereNotNull('snoozed_until')->count();
    @endphp
    <div class="pos-toolbar">
        <nav class="pos-tabs" role="tablist" aria-label="Notification groups">
            @foreach ($groups as $key => $label)
                <button type="button" role="tab" class="pos-tab" aria-selected="{{ $group === $key && ! $snoozedOnly ? 'true' : 'false' }}" wire:click="setGroup('{{ $key }}')">
                    {{ $label }} @if (($counts[$key] ?? 0) > 0)<span class="pos-count" data-tone="{{ $key === 'attention' ? 'danger' : 'neutral' }}">{{ $counts[$key] }}</span>@endif
                </button>
            @endforeach
        </nav>
        <div class="ms-auto flex flex-wrap items-center gap-2">
            <button type="button" class="pos-chip" aria-pressed="{{ $unread ? 'true' : 'false' }}" wire:click="$toggle('unread')">Unread only</button>
            @if ($snoozedCount > 0)
                <button type="button" class="pos-chip" aria-pressed="{{ $snoozedOnly ? 'true' : 'false' }}" wire:click="$toggle('snoozedOnly')">Snoozed {{ $snoozedCount }}</button>
            @endif
            @if (($counts['all'] ?? 0) > 0)
                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="markAllRead">Mark all read</button>
            @endif
            @if (\App\Filament\Pages\MyHr::canAccess())
                <a href="{{ \App\Filament\Pages\MyHr::getUrl(['tab' => 'preferences']) }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Preferences</a>
            @endif
        </div>
    </div>

    @if ($rows->isEmpty())
        <x-pos.empty class="mt-6" icon="heroicon-o-bell"
            :title="$snoozedOnly ? 'Nothing snoozed' : ($unread || $group !== 'all' ? 'Nothing here with these filters' : 'No notifications yet')"
            :why="$snoozedOnly ? 'Snoozed notifications come back on their own at the time you chose.' : ($unread || $group !== 'all' ? 'Try All, or include read notifications.' : 'Approvals, reminders, announcements and updates about your work will appear here.')" />
    @else
        <ul class="pos-card pos-list mt-4 p-2" aria-label="Notifications" x-data="posListNav('.pos-notif')" x-on:keydown.window="handle($event)">
            @foreach ($rows as $n)
                <li class="pos-list-row pos-notif {{ $n['read'] ? 'is-read' : '' }}" tabindex="-1" wire:key="n-{{ $n['id'] }}">
                    <x-pos.tile-icon :tone="$n['tone']" :icon="$n['icon']" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="pos-body {{ $n['read'] ? '' : 'font-semibold' }}">@if (! $n['read'])<span class="pos-unread-dot" aria-label="Unread"></span>@endif{{ $n['title'] }}</p>
                        @if ($n['body'])<p class="pos-body-sm line-clamp-2">{{ $n['body'] }}</p>@endif
                        <p class="pos-caption mt-0.5">{{ $n['group_label'] }} · <time datetime="{{ $n['at']?->toIso8601String() }}">{{ $n['at']?->diffForHumans() }}</time>@if ($n['snoozed_until']) · snoozed until {{ \Illuminate\Support\Carbon::parse($n['snoozed_until'])->format('D H:i') }}@endif</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                        @if ($this->linkFor($n['source_type'], $n['source_id']))
                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="open('{{ $n['id'] }}')" data-open>Open</button>
                        @endif
                        <button type="button" class="pos-icon-btn" wire:click="toggleRead('{{ $n['id'] }}')" title="{{ $n['read'] ? 'Mark unread' : 'Mark read' }}" aria-label="{{ $n['read'] ? 'Mark unread' : 'Mark read' }}">
                            <x-filament::icon :icon="$n['read'] ? 'heroicon-o-envelope' : 'heroicon-o-envelope-open'" class="size-4" />
                        </button>
                        @if ($n['snoozed_until'])
                            <button type="button" class="pos-icon-btn" wire:click="unsnooze('{{ $n['id'] }}')" title="Bring back now" aria-label="Bring back now"><x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-4" /></button>
                        @else
                            <div class="relative" x-data="{ o: false }" x-on:click.outside="o = false">
                                <button type="button" class="pos-icon-btn" x-on:click="o = ! o" :aria-expanded="o.toString()" aria-label="Snooze"><x-filament::icon icon="heroicon-o-clock" class="size-4" /></button>
                                <div class="pos-popover" style="min-width: 180px; inset-inline-start: auto; inset-inline-end: 0" x-show="o" x-cloak role="menu">
                                    <button type="button" role="menuitem" class="pos-popover-item" wire:click="snooze('{{ $n['id'] }}', 'hour')">For an hour</button>
                                    <button type="button" role="menuitem" class="pos-popover-item" wire:click="snooze('{{ $n['id'] }}', 'tomorrow')">Until tomorrow 9:00</button>
                                    <button type="button" role="menuitem" class="pos-popover-item" wire:click="snooze('{{ $n['id'] }}', 'week')">For a week</button>
                                </div>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
