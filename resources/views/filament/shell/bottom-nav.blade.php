@php
    $posNavUser = auth()->user();
    $posLens = app(\App\Domain\Experience\Services\RoleLens::class);
    $posMe = $posLens->employee($posNavUser);
    $posRoute = (string) request()->route()?->getName();
    $posItems = array_values(array_filter([
        ['label' => 'Home', 'icon' => 'heroicon-o-sun', 'url' => \App\Filament\Pages\Home::getUrl(), 'active' => $posRoute === 'filament.admin.pages.home' || $posRoute === 'filament.admin.pages.dashboard', 'hint' => 'Today'],
        ['label' => 'Work', 'icon' => 'heroicon-o-inbox-stack', 'url' => \App\Filament\Pages\MyWork::getUrl(), 'active' => in_array($posRoute, ['filament.admin.pages.my-work', 'filament.admin.pages.approvals', 'filament.admin.pages.task-inbox'], true),
            'badge' => app(\App\Domain\Experience\Services\ApprovalCenter::class)->count($posNavUser)],
        ['label' => 'Actions', 'icon' => 'heroicon-m-plus', 'action' => true],
        \App\Filament\Pages\People::canAccess() ? ['label' => 'People', 'icon' => 'heroicon-o-users', 'url' => \App\Filament\Pages\People::getUrl(), 'active' => $posRoute === 'filament.admin.pages.people' || str_starts_with($posRoute, 'filament.admin.resources.employees.') || $posRoute === 'filament.admin.pages.organisation-map'] : null,
        \App\Filament\Pages\MyHr::canAccess() ? ['label' => 'Services', 'icon' => 'heroicon-o-lifebuoy', 'url' => \App\Filament\Pages\MyHr::getUrl(), 'active' => $posRoute === 'filament.admin.pages.my-hr'] : null,
    ]));
    $posGoto = array_filter([
        'h' => \App\Filament\Pages\Home::getUrl(),
        'w' => \App\Filament\Pages\MyWork::getUrl(),
        'a' => \App\Filament\Pages\Approvals::canAccess() ? \App\Filament\Pages\Approvals::getUrl() : null,
        'p' => \App\Filament\Pages\People::canAccess() ? \App\Filament\Pages\People::getUrl() : null,
        'o' => \App\Filament\Pages\OrganisationMap::canAccess() ? \App\Filament\Pages\OrganisationMap::getUrl() : null,
    ]);
@endphp
<script>window.PeopleOS = Object.assign(window.PeopleOS || {}, { goto: @js($posGoto) });</script>
{{--
    Mobile bottom navigation (UX.15): Home (today), Work, Actions, People, Services. Notifications (the bell)
    and your profile (the avatar) sit in the top bar, so the bar never mislabels whose page is open.
--}}
<nav class="pos-bottom-nav" aria-label="Primary">
    @foreach ($posItems as $item)
        @if ($item['action'] ?? false)
            <button type="button" class="pos-bottom-item pos-bottom-action" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })" aria-label="Start something: leave, requests, approvals and more">
                <span class="pos-bottom-action-icon"><x-filament::icon :icon="$item['icon']" class="size-6" /></span>
                <span>{{ $item['label'] }}</span>
            </button>
        @else
            <a href="{{ $item['url'] }}" wire:navigate class="pos-bottom-item" @if ($item['active']) aria-current="page" @endif>
                <span class="relative">
                    <x-filament::icon :icon="$item['icon']" class="size-6" />
                    @if (($item['badge'] ?? 0) > 0)<span class="pos-bottom-badge pos-num">{{ min($item['badge'], 99) }}<span class="sr-only"> waiting</span></span>@endif
                </span>
                <span>{{ $item['label'] }}</span>
            </a>
        @endif
    @endforeach
</nav>

{{-- Keyboard shortcuts (?) --}}
<div x-data="{ open: false }" x-on:pos-shortcuts.window="open = ! open" x-on:keydown.escape.window="open = false">
    <div x-show="open" x-cloak class="pos-overlay" x-on:click="open = false" aria-hidden="true"></div>
    <div x-show="open" x-cloak x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="pos-shortcuts-title" class="pos-shortcuts pos-command-enter">
        <div class="flex items-center justify-between">
            <h2 id="pos-shortcuts-title" class="pos-h3">Keyboard shortcuts</h2>
            <button type="button" class="pos-icon-btn" x-on:click="open = false" aria-label="Close"><x-filament::icon icon="heroicon-m-x-mark" class="size-5" /></button>
        </div>
        <dl class="pos-shortcut-list mt-4">
            <div><dt><span class="pos-kbd">Ctrl</span><span class="pos-kbd">K</span> or <span class="pos-kbd">/</span></dt><dd>Search and commands</dd></div>
            <div><dt><span class="pos-kbd">N</span></dt><dd>Start something new</dd></div>
            <div><dt><span class="pos-kbd">G</span> <span class="pos-kbd">H</span></dt><dd>Go to Home</dd></div>
            <div><dt><span class="pos-kbd">G</span> <span class="pos-kbd">W</span></dt><dd>Go to My work</dd></div>
            <div><dt><span class="pos-kbd">G</span> <span class="pos-kbd">A</span></dt><dd>Go to Approvals</dd></div>
            <div><dt><span class="pos-kbd">G</span> <span class="pos-kbd">P</span></dt><dd>Go to People</dd></div>
            <div><dt><span class="pos-kbd">G</span> <span class="pos-kbd">O</span></dt><dd>Go to the org map</dd></div>
            <div><dt><span class="pos-kbd">J</span> <span class="pos-kbd">K</span></dt><dd>Move through a list</dd></div>
            <div><dt><span class="pos-kbd">A</span> / <span class="pos-kbd">R</span></dt><dd>Approve / reject the selected item</dd></div>
            <div><dt><span class="pos-kbd">Esc</span></dt><dd>Close a panel or dialog</dd></div>
            <div><dt><span class="pos-kbd">?</span></dt><dd>This list</dd></div>
        </dl>
    </div>
</div>
