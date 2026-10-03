@auth
    <button type="button" class="pos-command-trigger" x-data x-on:click="$dispatch('pos-command-open')" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" data-pos-command-trigger>
        <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 shrink-0" />
        <span class="pos-command-trigger-label">Search people, requests, actions…</span>
        <span class="pos-kbd" x-text="/Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘K' : 'Ctrl K'">Ctrl K</span>
    </button>
@endauth
