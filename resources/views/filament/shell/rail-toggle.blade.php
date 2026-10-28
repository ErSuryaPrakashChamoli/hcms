{{-- The rail's handle (desktop), on the brand block's edge: keeps the menu open or folds it to icons. While folded,
     resting the mouse on the rail opens it over the page until the mouse leaves (posRail in resources/js/peopleos.js). --}}
<button
    type="button"
    class="pos-rail-toggle"
    x-data="posRail()"
    x-on:click="toggle()"
    x-bind:class="{ 'pos-pinned': pinned }"
    x-bind:aria-expanded="pinned"
    x-bind:aria-label="pinned ? 'Collapse menu' : 'Expand menu'"
    x-effect="tip = { content: pinned ? 'Collapse menu' : 'Expand menu', placement: 'right', theme: $store.theme }"
    x-tooltip="tip"
    aria-controls="fi-main-sidebar"
>
    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6.25 3.75 10.5 8l-4.25 4.25" /></svg>
</button>
