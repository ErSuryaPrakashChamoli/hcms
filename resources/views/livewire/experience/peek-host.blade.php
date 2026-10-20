{{-- PeoplePeek: drawn by posPeek (resources/js/peopleos.js) from PeekHost::peek(); supplementary to the drawer. --}}
<div x-data="posPeek($wire)" x-on:pos-peek-hide.window="hide()" class="contents">
    <div x-show="open" x-cloak id="pos-peek" role="tooltip" class="pos-peek pos-peek-in" :style="style"
        x-on:mouseenter="keep()" x-on:mouseleave="leave()">
        <template x-if="data">
            <div>
                <div class="pos-peek-head">
                    <span class="pos-avatar pos-avatar-lg" :data-tone="data.tone" x-text="data.initials" aria-hidden="true"></span>
                    <div class="min-w-0">
                        <p class="pos-peek-name" x-text="data.name"></p>
                        <p class="pos-peek-role" x-text="[data.title, data.team].filter(Boolean).join(' · ') || ' '"></p>
                    </div>
                </div>
                <dl class="pos-peek-facts">
                    <div x-show="data.location"><dt>Location</dt><dd x-text="data.location"></dd></div>
                    <div x-show="data.manager"><dt>Reports to</dt><dd x-text="data.manager"></dd></div>
                    <div x-show="data.status"><dt>Status</dt><dd x-text="data.status"></dd></div>
                </dl>
                <div class="pos-peek-actions">
                    <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" x-on:click="drawer()">Preview</button>
                    <a x-show="data.profile" :href="data.profile" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open profile</a>
                </div>
            </div>
        </template>
        <template x-if="! data && ! loading">
            <p class="pos-peek-role">Not available in your directory view.</p>
        </template>
        <template x-if="loading && ! data">
            <div class="flex items-center gap-3"><span class="pos-skeleton" style="width: 52px; height: 52px; border-radius: var(--pos-radius-pill)"></span><span class="pos-skeleton" style="height: 14px; flex: 1"></span></div>
        </template>
    </div>
</div>
