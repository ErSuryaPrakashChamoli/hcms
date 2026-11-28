{{--
    Employee 360 → Documents card (Employee360Drawers::documents, data from Employee360Folders::documents): every document
    type by name, under its category, with the person's current files of it or "Not uploaded", and Upload on the drawer,
    each category and each type (the upload dialog opens over the drawer, pre-filled). Verify / reject / delete stay in
    the full list.
--}}
@php($sum = $folder['summary'])
<div class="pos-folder" x-data="{ only: null, q: '' }">
    <dl class="pos-folder-sum">
        <div><dt>Documents</dt><dd class="pos-num">{{ $sum['documents'] }}</dd></div>
        <div @if ($sum['missing'] > 0) data-tone="danger" @endif><dt>Required, missing</dt><dd class="pos-num">{{ $sum['missing'] }}</dd></div>
        <div @if ($sum['expiring'] > 0) data-tone="warning" @endif><dt>Expiring in 30 days</dt><dd class="pos-num">{{ $sum['expiring'] }}</dd></div>
    </dl>

    <div class="pos-folder-bar">
        @if ($folder['upload'])
            <button type="button" class="pos-btn pos-btn-primary pos-btn-sm" wire:click="mountAction('upload360Document')">
                <x-filament::icon icon="heroicon-m-arrow-up-tray" class="size-4" />Upload a document
            </button>
        @endif
        @if ($records)
            {{-- The list is on the 360 (Documents view): the drawer closes as the link opens it. --}}
            <a href="{{ $records }}" x-on:click="$wire.unmountAction()" class="pos-btn pos-btn-ghost pos-btn-sm">Full list: verify, reject, delete</a>
        @endif
    </div>

    @if ($folder['categories'] === [])
        <x-pos.state variant="empty" size="inline" title="No document types yet." why="Document types are set up in Setup → Core HR → Document types." />
    @else
        <div class="pos-folder-filter">
            <label class="sr-only" for="pos-folder-q-docs">Find a document type</label>
            <input id="pos-folder-q-docs" type="search" class="pos-folder-search" placeholder="Find a document type…" x-model.debounce.150ms="q" autocomplete="off">
            <div class="pos-folder-chips" role="group" aria-label="Categories">
                <button type="button" class="pos-chip" :aria-pressed="(only === null).toString()" x-on:click="only = null">All</button>
                @foreach ($folder['categories'] as $c)
                    <button type="button" class="pos-chip" :aria-pressed="(only === @js($c['key'])).toString()" x-on:click="only = only === @js($c['key']) ? null : @js($c['key'])">
                        {{ $c['label'] }}<span class="pos-folder-chip-count">{{ $c['with'] }}/{{ count($c['types']) }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        @foreach ($folder['categories'] as $c)
            <section class="pos-folder-group" aria-labelledby="pos-folder-cat-{{ $c['key'] }}"
                x-show="(only === null || only === @js($c['key'])) && (! q || [...$el.querySelectorAll('[data-name]')].some((e) => e.dataset.name.includes(q.toLowerCase())))">
                <header class="pos-folder-head">
                    <div class="min-w-0">
                        <h3 id="pos-folder-cat-{{ $c['key'] }}" class="pos-folder-title">{{ $c['label'] }}</h3>
                        <p class="pos-caption">{{ $c['with'] }} of {{ count($c['types']) }} {{ count($c['types']) === 1 ? 'type' : 'types' }} on file @if ($c['missing'] > 0) · <span class="pos-folder-missing">{{ $c['missing'] }} required missing</span>@endif</p>
                    </div>
                    @if ($c['upload'])
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction('upload360Document', @js(['category' => $c['key']]))">
                            <x-filament::icon icon="heroicon-m-arrow-up-tray" class="size-4" />Upload<span class="sr-only"> to {{ $c['label'] }}</span>
                        </button>
                    @endif
                </header>
                <ul class="pos-folder-list">
                    @foreach ($c['types'] as $t)
                        <li class="pos-folder-type" data-name="{{ mb_strtolower($t['name']) }}" x-show="! q || $el.dataset.name.includes(q.toLowerCase())">
                            <div class="pos-folder-type-head">
                                <x-filament::icon :icon="$t['documents'] === [] ? 'heroicon-o-document' : 'heroicon-s-document-text'" @class(['pos-folder-icon size-5', 'is-filled' => $t['documents'] !== []]) />
                                <div class="pos-folder-type-text">
                                    <span class="pos-folder-type-name">{{ $t['name'] }}</span>
                                    @if ($t['missing'])
                                        <span class="pos-status pos-status-danger">Required · missing</span>
                                    @elseif ($t['mandatory'])
                                        <span class="pos-status pos-status-neutral">Required</span>
                                    @endif
                                    @if ($t['documents'] === [])
                                        <span class="pos-caption">Not uploaded</span>
                                    @endif
                                </div>
                                @if ($t['upload'])
                                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="mountAction('upload360Document', @js(['type' => $t['id']]))">
                                        <x-filament::icon icon="heroicon-m-arrow-up-tray" class="size-4" />{{ $t['documents'] === [] ? 'Upload' : 'New version' }}<span class="sr-only">: {{ $t['name'] }}</span>
                                    </button>
                                @endif
                            </div>
                            @foreach ($t['documents'] as $d)
                                <div class="pos-folder-file">
                                    <div class="min-w-0">
                                        <a href="{{ $d['url'] }}" target="_blank" rel="noopener" class="pos-folder-file-name">{{ $d['title'] }}</a>
                                        <p class="pos-caption">v{{ $d['version'] }}@if ($d['file']) · {{ $d['file'] }}@endif @if ($d['uploaded']) · uploaded {{ $d['uploaded'] }}@endif
                                            @if ($d['expires']) · <span @class(['pos-folder-missing' => $d['expired'], 'pos-folder-soon' => $d['expiring']])>{{ $d['expired'] ? 'expired' : 'expires' }} {{ $d['expires'] }}</span>@endif</p>
                                        @if ($d['note'])
                                            <p class="pos-caption">HR’s note: {{ $d['note'] }}</p>
                                        @endif
                                    </div>
                                    <span class="pos-status pos-status-{{ $d['tone'] }}">{{ $d['status'] }}</span>
                                </div>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <p class="pos-caption pos-folder-nomatch" x-cloak x-show="q && ! [...$root.querySelectorAll('[data-name]')].some((e) => e.dataset.name.includes(q.toLowerCase()))">No document type matches “<span x-text="q"></span>”.</p>
    @endif
</div>
