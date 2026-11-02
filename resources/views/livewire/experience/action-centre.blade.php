{{-- HROne parity AC-1: the Actions panel. The x-data stays static (server state inside it would be re-initialised on morph);
     the search filters in the browser over each action's label, words and HROne name. --}}
<div x-data="{
        isOpen: {{ $inline ? 'true' : 'false' }},
        inline: {{ $inline ? 'true' : 'false' }},
        q: '',
        open() { this.isOpen = true; if (! $wire.active) { $wire.opened(); } this.$nextTick(() => this.$refs.search?.focus()); },
        close() { if (! this.inline) { this.isOpen = false; this.q = ''; } },
        match(text) { const words = this.q.toLowerCase().trim().split(/\s+/).filter(Boolean); return words.every((w) => text.includes(w)); },
        anyIn(el) { return this.q.trim() === '' || [...el.querySelectorAll('[data-pos-action-text]')].some((i) => this.match(i.dataset.posActionText)); },
        shown() { return [...this.$root.querySelectorAll('[data-pos-action-text]')].filter((i) => this.match(i.dataset.posActionText)).length; },
        go(event, key) { if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) { return; } event.preventDefault(); $wire.run(key); },
    }"
    @unless ($inline) x-on:pos-actions-open.window="open()" x-on:keydown.escape.window="isOpen && close()" @endunless
    class="pos-actions-root" data-pos-actions="{{ $inline ? 'inline' : 'panel' }}">
    @unless ($inline)
        <div x-show="isOpen" x-cloak class="pos-overlay" x-on:click="close()" x-transition.opacity.duration.150ms aria-hidden="true"></div>
    @endunless

    @php($sections = $this->sections)
    @php($total = array_sum(array_map(fn (array $section): int => count($section['items']), $sections)))
    <section x-show="isOpen" @unless ($inline) x-cloak x-trap.noscroll.inert="isOpen" role="dialog" aria-modal="true" @endunless
        @if ($inline) aria-label="Actions" @else aria-labelledby="pos-actions-title" @endif class="pos-actions {{ $inline ? 'pos-actions-inline' : 'pos-actions-panel' }}">
        {{-- The hero band: what this is, the search, and the person's recent and pinned actions as chips. --}}
        <header class="pos-actions-hero">
            <div class="pos-actions-hero-top">
                <span class="pos-actions-hero-mark" aria-hidden="true"><x-filament::icon icon="heroicon-m-bolt" class="size-5" /></span>
                <div class="pos-actions-hero-text">
                    @unless ($inline)
                        <h2 id="pos-actions-title" class="pos-actions-title">Actions</h2>
                    @endunless
                    <p class="pos-actions-lede" x-show="q.trim() === ''">
                        @if ($total > 0)
                            {{ $total }} {{ Str::plural('action', $total) }} across {{ count($sections) }} {{ Str::plural('module', count($sections)) }}. Pin the ones you use most.
                        @else
                            Start any HR task for your people in one step.
                        @endif
                    </p>
                    <p class="pos-actions-lede" x-show="q.trim() !== ''" x-cloak aria-live="polite"
                        x-text="shown() === 1 ? '1 action matches' : shown() + ' actions match'"></p>
                </div>

                <div class="pos-actions-search">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5" />
                    <input x-ref="search" type="search" x-model="q" autocomplete="off" spellcheck="false" class="pos-actions-search-input"
                        placeholder="Search actions…" aria-label="Search actions" x-on:keydown.escape.stop="q !== '' ? (q = '') : close()" />
                    @unless ($inline)
                        <span class="pos-kbd" aria-hidden="true">Esc</span>
                    @endunless
                </div>

                @unless ($inline)
                    <button type="button" class="pos-actions-close" x-on:click="close()" aria-label="Close actions">
                        <x-filament::icon icon="heroicon-m-x-mark" class="size-5" />
                    </button>
                @endunless
            </div>

            @if ($this->recent !== [] || $this->pinned !== [])
                <div class="pos-actions-strips" x-show="q.trim() === ''">
                    @foreach (['recent' => ['Recent actions', 'heroicon-m-clock', $this->recent], 'pinned' => ['Pinned', 'heroicon-m-bookmark', $this->pinned]] as $kind => [$caption, $icon, $rows])
                        @if ($rows !== [])
                            <div class="pos-actions-strip" data-kind="{{ $kind }}">
                                <span class="pos-actions-strip-label"><x-filament::icon :icon="$icon" class="size-4" />{{ $caption }}</span>
                                <div class="pos-actions-strip-items">
                                    @foreach ($rows as $row)
                                        <a href="{{ $row['url'] }}" class="pos-actions-chip" wire:key="strip-{{ $kind }}-{{ $row['key'] }}" x-on:click="go($event, @js($row['key']))">
                                            <x-filament::icon :icon="$row['icon']" class="size-4" />{{ $row['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </header>

        <div class="pos-actions-body">
            @if (! $active)
                <div class="pos-actions-skeleton" aria-hidden="true">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="pos-actions-skeleton-col">
                            <span class="pos-skeleton pos-actions-skeleton-head"></span>
                            @for ($j = 0; $j < 5; $j++)
                                <span class="pos-skeleton pos-actions-skeleton-line"></span>
                            @endfor
                        </div>
                    @endfor
                </div>
                <p class="sr-only" role="status">Loading actions…</p>
            @elseif ($sections === [])
                <div class="pos-actions-empty" role="status">
                    <span class="pos-actions-empty-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-bolt" class="size-6" /></span>
                    <p class="pos-body font-medium">No HR actions for you yet</p>
                    <p class="pos-body-sm pos-muted">Actions appear here when your role lets you run them. Your own requests are under “New request”.</p>
                </div>
            @else
                @php($pinnedKeys = $this->pinnedKeys)
                <div class="pos-actions-grid">
                    @foreach ($sections as $section)
                        <div class="pos-actions-section" data-tone="{{ $section['key'] }}" style="--pos-i: {{ $loop->index }}" wire:key="section-{{ $section['key'] }}" x-show="anyIn($el)">
                            <h3 class="pos-actions-section-head">
                                <span class="pos-actions-section-icon" aria-hidden="true"><x-filament::icon :icon="$section['icon']" class="size-5" /></span>
                                <span class="pos-actions-section-title">{{ $section['label'] }}</span>
                                <span class="pos-actions-section-count" aria-hidden="true" x-show="q.trim() === ''">{{ count($section['items']) }}</span>
                            </h3>
                            <ul class="pos-actions-list" role="list">
                                @foreach ($section['items'] as $item)
                                    @php($pinned = in_array($item['key'], $pinnedKeys, true))
                                    <li class="pos-actions-item" wire:key="action-{{ $item['key'] }}"
                                        data-pos-action-text="{{ mb_strtolower(implode(' ', [$item['label'], $item['hrone'], $section['label'], ...$item['keywords']])) }}"
                                        x-show="q.trim() === '' || match($el.dataset.posActionText)">
                                        <a href="{{ $item['url'] }}" class="pos-actions-link" title="{{ $item['hint'] }}" x-on:click="go($event, @js($item['key']))">{{ $item['label'] }}</a>
                                        <button type="button" class="pos-actions-pin" data-pinned="{{ $pinned ? 'true' : 'false' }}" wire:click="togglePin(@js($item['key']))"
                                            aria-pressed="{{ $pinned ? 'true' : 'false' }}" aria-label="{{ $pinned ? 'Unpin' : 'Pin' }} {{ $item['label'] }}" title="{{ $pinned ? 'Unpin' : 'Pin to the top' }}">
                                            <x-filament::icon :icon="$pinned ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'" class="size-4" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
                <div class="pos-actions-none" role="status" x-cloak x-show="q.trim() !== '' && shown() === 0">
                    <span class="pos-actions-empty-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-magnifying-glass" class="size-6" /></span>
                    <p class="pos-body font-medium">No action matches “<span x-text="q"></span>”</p>
                    <p class="pos-body-sm pos-muted">Try a word such as “leave”, “challan” or “goal”.</p>
                </div>
            @endif
        </div>
    </section>
</div>
