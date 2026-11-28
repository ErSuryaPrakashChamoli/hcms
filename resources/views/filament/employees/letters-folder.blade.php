{{--
    Employee 360 → Letters card (Employee360Drawers::letters, data from Employee360Folders::letters): every letter type by
    name (Offer letter, Appointment letter, Appraisal letter, …) with the person's letters of it — generated in PeopleOS or
    uploaded against the type — and Generate / Upload on the drawer and on each type (dialogs open over the drawer).
--}}
@php($sum = $folder['summary'])
<div class="pos-folder" x-data="{ q: '', only: 'all' }">
    <dl class="pos-folder-sum">
        <div><dt>Letters</dt><dd class="pos-num">{{ $sum['letters'] }}</dd></div>
        <div><dt>Issued</dt><dd class="pos-num">{{ $sum['issued'] }}</dd></div>
        <div @if ($sum['pending'] > 0) data-tone="warning" @endif><dt>Awaiting approval or issue</dt><dd class="pos-num">{{ $sum['pending'] }}</dd></div>
    </dl>

    <div class="pos-folder-bar">
        @if ($folder['generate'])
            <button type="button" class="pos-btn pos-btn-primary pos-btn-sm" wire:click="mountAction('generate360Letter')">
                <x-filament::icon icon="heroicon-m-document-plus" class="size-4" />Generate a letter
            </button>
        @endif
        @if ($folder['upload'])
            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('upload360Letter')">
                <x-filament::icon icon="heroicon-m-arrow-up-tray" class="size-4" />Upload a letter
            </button>
        @endif
        @if ($records)
            <a href="{{ $records }}" x-on:click="$wire.unmountAction()" class="pos-btn pos-btn-ghost pos-btn-sm">Full list</a>
        @endif
    </div>

    @if ($folder['types'] === [])
        <x-pos.state variant="empty" size="inline" title="No letters yet." why="Letters issued to this person appear here, by letter type." />
    @else
        <div class="pos-folder-filter">
            <label class="sr-only" for="pos-folder-q-letters">Find a letter type</label>
            <input id="pos-folder-q-letters" type="search" class="pos-folder-search" placeholder="Find a letter type…" x-model.debounce.150ms="q" autocomplete="off">
            <div class="pos-folder-chips" role="group" aria-label="Show">
                <button type="button" class="pos-chip" :aria-pressed="(only === 'all').toString()" x-on:click="only = 'all'">Every letter type</button>
                <button type="button" class="pos-chip" :aria-pressed="(only === 'held').toString()" x-on:click="only = 'held'">On file</button>
            </div>
        </div>

        <ul class="pos-folder-list pos-folder-group">
            @foreach ($folder['types'] as $t)
                <li class="pos-folder-type" data-name="{{ mb_strtolower($t['label']) }}" data-held="{{ $t['letters'] === [] ? '0' : '1' }}"
                    x-show="(! q || $el.dataset.name.includes(q.toLowerCase())) && (only === 'all' || $el.dataset.held === '1')">
                    <div class="pos-folder-type-head">
                        <x-filament::icon :icon="$t['letters'] === [] ? 'heroicon-o-envelope' : 'heroicon-s-envelope-open'" @class(['pos-folder-icon size-5', 'is-filled' => $t['letters'] !== []]) />
                        <div class="pos-folder-type-text">
                            <span class="pos-folder-type-name">{{ $t['label'] }}</span>
                            <span class="pos-caption">{{ $t['letters'] === [] ? 'None on file' : count($t['letters']).' on file' }}</span>
                        </div>
                        <div class="pos-folder-type-actions">
                            @if ($t['generate'])
                                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="mountAction('generate360Letter', @js(['type' => $t['key']]))">
                                    <x-filament::icon icon="heroicon-m-document-plus" class="size-4" />Generate<span class="sr-only">: {{ $t['label'] }}</span>
                                </button>
                            @endif
                            @if ($t['upload'])
                                <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="mountAction('upload360Letter', @js(['type' => $t['key']]))">
                                    <x-filament::icon icon="heroicon-m-arrow-up-tray" class="size-4" />Upload<span class="sr-only">: {{ $t['label'] }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                    @foreach ($t['letters'] as $l)
                        <div class="pos-folder-file">
                            <div class="min-w-0">
                                @php($href = $l['kind'] === 'letter' ? ($open[$l['id']] ?? $l['download']) : $l['download'])
                                @if ($href)
                                    <a href="{{ $href }}" @if ($href === $l['download']) target="_blank" rel="noopener" @endif class="pos-folder-file-name">{{ $l['number'] }}</a>
                                @else
                                    <span class="pos-folder-file-name">{{ $l['number'] }}</span>
                                @endif
                                <p class="pos-caption">{{ $l['kind'] === 'upload' ? 'Uploaded file' : 'Generated' }}@if ($l['template']) · {{ $l['template'] }}@endif @if ($l['date']) · {{ mb_strtolower($l['dated']) }} {{ $l['date'] }}@endif</p>
                            </div>
                            <div class="pos-folder-file-end">
                                <span class="pos-status pos-status-{{ $l['tone'] }}">{{ $l['status'] }}</span>
                                @if ($l['kind'] === 'letter' && $l['download'] && isset($open[$l['id']]))
                                    <a href="{{ $l['download'] }}" target="_blank" rel="noopener" class="pos-folder-dl" title="Download">
                                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="size-4" /><span class="sr-only">Download {{ $l['number'] }}</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </li>
            @endforeach
        </ul>
        <p class="pos-caption pos-folder-nomatch" x-cloak x-show="! [...$root.querySelectorAll('[data-name]')].some((e) => (! q || e.dataset.name.includes(q.toLowerCase())) && (only === 'all' || e.dataset.held === '1'))">
            <span x-show="q">No letter type matches “<span x-text="q"></span>”.</span><span x-show="! q">No letters on file yet.</span>
        </p>
    @endif
</div>
