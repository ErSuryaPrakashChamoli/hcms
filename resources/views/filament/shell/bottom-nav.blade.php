@php
    $posNavUser = auth()->user();
    $posLens = app(\App\Domain\Experience\Services\RoleLens::class);
    $posMe = $posLens->employee($posNavUser);
    $posRoute = (string) request()->route()?->getName();
    $posItems = array_values(array_filter([
        ['label' => 'Home', 'icon' => 'heroicon-o-home', 'url' => \App\Filament\Pages\Home::getUrl(), 'active' => $posRoute === 'filament.admin.pages.home' || $posRoute === 'filament.admin.pages.dashboard'],
        ['label' => 'Work', 'icon' => 'heroicon-o-inbox-stack', 'url' => \App\Filament\Pages\MyWork::getUrl(), 'active' => in_array($posRoute, ['filament.admin.pages.my-work', 'filament.admin.pages.approvals'], true),
            'badge' => app(\App\Domain\Experience\Services\ApprovalCenter::class)->count($posNavUser)],
        \App\Filament\Pages\People::canAccess() ? ['label' => 'People', 'icon' => 'heroicon-o-users', 'url' => \App\Filament\Pages\People::getUrl(), 'active' => $posRoute === 'filament.admin.pages.people'] : null,
        \App\Filament\Pages\MyHr::canAccess() ? ['label' => 'Services', 'icon' => 'heroicon-o-lifebuoy', 'url' => \App\Filament\Pages\MyHr::getUrl(['tab' => 'services']), 'active' => $posRoute === 'filament.admin.pages.my-hr'] : null,
        $posMe && $posNavUser->can('view', $posMe)
            ? ['label' => 'Profile', 'icon' => 'heroicon-o-user-circle', 'url' => \App\Filament\Resources\Employees\EmployeeResource::getUrl('view', ['record' => $posMe]), 'active' => str_starts_with($posRoute, 'filament.admin.resources.employees.')]
            : (\App\Filament\Pages\MyHr::canAccess() ? ['label' => 'Me', 'icon' => 'heroicon-o-user-circle', 'url' => \App\Filament\Pages\MyHr::getUrl(['tab' => 'documents']), 'active' => false] : null),
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
{{-- Mobile bottom navigation (§45): the five things people do on a phone. --}}
<nav class="pos-bottom-nav" aria-label="Primary">
    @foreach ($posItems as $item)
        <a href="{{ $item['url'] }}" wire:navigate class="pos-bottom-item" @if ($item['active']) aria-current="page" @endif>
            <span class="relative">
                <x-filament::icon :icon="$item['icon']" class="size-6" />
                @if (($item['badge'] ?? 0) > 0)<span class="pos-bottom-badge pos-num">{{ min($item['badge'], 99) }}</span>@endif
            </span>
            <span>{{ $item['label'] }}</span>
        </a>
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
