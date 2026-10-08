@php
    $posGoto = array_filter([
        'h' => \App\Filament\Pages\Home::getUrl(),
        'w' => \App\Filament\Pages\MyWork::getUrl(),
        'a' => \App\Filament\Pages\Approvals::canAccess() ? \App\Filament\Pages\Approvals::getUrl() : null,
        'p' => \App\Filament\Pages\People::canAccess() ? \App\Filament\Pages\People::getUrl() : null,
        'o' => \App\Filament\Pages\OrganisationMap::canAccess() ? \App\Filament\Pages\OrganisationMap::getUrl() : null,
    ]);
@endphp
<script>window.PeopleOS = Object.assign(window.PeopleOS || {}, { goto: @js($posGoto) });</script>
{{-- The phone and tablet bar (UX.17): role-aware, re-rendered when the person switches views --}}
@livewire(\App\Livewire\Experience\BottomNav::class)

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
