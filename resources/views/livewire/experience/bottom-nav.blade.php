{{--
    Phone and tablet bar (UX.17): Home, the role's most frequent places, and Actions in the centre, composed per
    experience by MobileNavigation; every destination is the person's own (canAccess). Notifications (the bell),
    search and your profile (the avatar) sit in the top bar. Decisions are counted on Approvals or Work.
--}}
<nav class="pos-bottom-nav" aria-label="Primary" data-experience="{{ $experience }}" style="--pos-bar-items: {{ count($items) }}">
    @foreach ($items as $item)
        @if ($item['action'])
            <button type="button" class="pos-bottom-item pos-bottom-action" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })" aria-label="Start something: leave, requests, approvals and more">
                <span class="pos-bottom-action-icon"><x-filament::icon :icon="$item['icon']" class="size-6" /></span>
                <span>{{ $item['label'] }}</span>
            </button>
        @else
            <a href="{{ $item['url'] }}" wire:navigate class="pos-bottom-item" data-bar="{{ $item['key'] }}" @if ($item['active']) aria-current="page" @endif>
                <span class="relative">
                    <x-filament::icon :icon="$item['icon']" class="size-6" />
                    @if ($item['badge'] > 0)<span class="pos-bottom-badge pos-num">{{ min($item['badge'], 99) }}<span class="sr-only"> waiting</span></span>@endif
                </span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endif
    @endforeach
</nav>
