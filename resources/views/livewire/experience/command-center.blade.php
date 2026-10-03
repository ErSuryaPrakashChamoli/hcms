<div x-data="posCommand(@js($mode))" x-on:pos-command-open.window="open($event.detail)" x-on:keydown.window="globalKey($event)" class="pos-command-root">
    <div x-show="isOpen" x-cloak class="pos-overlay" x-on:click="close()" x-transition.opacity.duration.150ms aria-hidden="true"></div>

    <div x-show="isOpen" x-cloak x-trap.noscroll.inert="isOpen" role="dialog" aria-modal="true" aria-labelledby="pos-command-title"
        class="pos-command pos-command-enter" x-on:keydown.escape.prevent.stop="close()" x-transition:enter="pos-command-in" x-transition:leave="pos-command-out">
        <h2 id="pos-command-title" class="sr-only">Command center</h2>
        <div class="pos-command-head">
            <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5 shrink-0 pos-muted" />
            <input x-ref="input" type="text" wire:model.live.debounce.120ms="query" autocomplete="off" spellcheck="false"
                role="combobox" aria-expanded="true" aria-controls="pos-command-list" aria-autocomplete="list" :aria-activedescendant="activeId"
                class="pos-command-input" placeholder="{{ $mode === 'actions' ? 'What do you want to do?' : ($mode === 'people' ? 'Find a person by name, ID or email' : 'Search people, requests, policies… or type what you want to do') }}"
                x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.arrow-right="actionMove($event, 1)" x-on:keydown.arrow-left="actionMove($event, -1)"
                x-on:keydown.enter.prevent="choose(null, $event.metaKey || $event.ctrlKey)" x-on:keydown.tab="cycleMode($event)" />
            <span wire:loading.delay.short wire:target="query" class="pos-spinner" aria-hidden="true"></span>
        </div>
        <div class="pos-command-modes" role="tablist" aria-label="Search scope">
            @foreach (['all' => 'Everything', 'actions' => 'Actions', 'people' => 'People'] as $key => $label)
                <button type="button" role="tab" class="pos-chip" aria-selected="{{ $mode === $key ? 'true' : 'false' }}" wire:click="setMode('{{ $key }}')" x-on:click="$nextTick(() => $refs.input.focus())">{{ $label }}</button>
            @endforeach
        </div>

        <div id="pos-command-list" role="listbox" aria-label="Results" class="pos-command-list" x-ref="list">
            @php($groups = $this->groups)
            @forelse ($groups as $group)
                <div role="group" aria-labelledby="cmd-g-{{ $group['key'] }}" class="pos-command-section">
                    <p id="cmd-g-{{ $group['key'] }}" class="pos-command-group">{{ $group['label'] }}</p>
                    @foreach ($group['items'] as $item)
                        @php($domId = 'cmd-'.md5($group['key'].$item['id']))
                        <div role="option" id="{{ $domId }}" class="pos-command-item" wire:key="{{ $domId }}"
                            data-id="{{ $item['id'] }}" data-url="{{ $item['url'] ?? '' }}" data-drawer='@json($item['drawer'] ?? null)'
                            :aria-selected="(activeId === '{{ $domId }}').toString()" x-on:mousemove="activate('{{ $domId }}')" x-on:click="choose($el, $event.metaKey || $event.ctrlKey)">
                            @if (isset($item['avatar']))
                                <x-pos.avatar :name="$item['avatar']" size="sm" />
                            @else
                                <span class="pos-command-icon" data-type="{{ $item['type'] }}" aria-hidden="true"><x-filament::icon :icon="$item['icon'] ?? 'heroicon-m-arrow-right'" class="size-4" /></span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="pos-command-title">{{ $item['title'] }}@if (($item['verb'] ?? null)) <span class="pos-command-verb">{{ $item['verb'] }}</span>@endif</p>
                                @if ($item['subtitle'] ?? null)<p class="pos-command-sub">{{ $item['subtitle'] }}</p>@endif
                                @if (($item['rows'] ?? []) !== [])
                                    <ul class="pos-command-answer">
                                        @foreach ($item['rows'] as $row)
                                            <li><span class="font-medium">{{ $row['label'] }}</span>@if ($row['meta']) <span class="pos-muted">· {{ $row['meta'] }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            @if (($item['actions'] ?? []) !== [])
                                <div class="pos-command-actions" x-show="activeId === '{{ $domId }}'" role="group" aria-label="Actions for {{ $item['title'] }}">
                                    @foreach ($item['actions'] as $i => $action)
                                        <button type="button" tabindex="-1" class="pos-command-action" :data-active="(actionIndex === {{ $i }}).toString()"
                                            data-url="{{ $action['url'] ?? '' }}" data-drawer='@json($action['drawer'] ?? null)' x-on:click.stop="runAction($el, '{{ $item['id'] }}')">{{ $action['label'] }}</button>
                                    @endforeach
                                </div>
                            @else
                                <span class="pos-command-enter-hint" x-show="activeId === '{{ $domId }}'" aria-hidden="true">↵</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="pos-command-empty" role="status">
                    @if (trim($query) === '')
                        <p class="pos-body-sm pos-muted">Start typing to search.</p>
                    @else
                        <p class="pos-body font-medium">No results for “{{ $query }}”</p>
                        <p class="pos-body-sm pos-muted mt-1">Try a person’s name, a request number, a policy word, or an action such as “leave”, “payslip” or “approve”. Results only include what you have access to.</p>
                    @endif
                </div>
            @endforelse
        </div>
        <footer class="pos-command-hint" aria-hidden="true">
            <span><span class="pos-kbd"><x-filament::icon icon="heroicon-m-arrow-up" class="size-3" /></span><span class="pos-kbd"><x-filament::icon icon="heroicon-m-arrow-down" class="size-3" /></span> move</span>
            <span><span class="pos-kbd">↵</span> open</span>
            <span><span class="pos-kbd">⌘↵</span> preview</span>
            <span><span class="pos-kbd"><x-filament::icon icon="heroicon-m-arrow-left" class="size-3" /></span><span class="pos-kbd"><x-filament::icon icon="heroicon-m-arrow-right" class="size-3" /></span> row actions</span>
            <span><span class="pos-kbd">Tab</span> scope</span>
            <span class="ms-auto"><span class="pos-kbd">Esc</span> close</span>
        </footer>
    </div>
</div>
