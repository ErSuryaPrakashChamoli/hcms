@php
    $favouriteKeys = $bar === null ? [] : array_column($bar['favourites'], 'key');
    $star = fn (array $module): array => in_array($module['key'], $favouriteKeys, true)
        ? ['on' => true, 'label' => 'Remove '.$module['label'].' from favourites']
        : ['on' => false, 'label' => 'Add '.$module['label'].' to favourites'];
    $match = fn (array $labels): string => \Illuminate\Support\Js::from(collect($labels)->map(fn ($l) => mb_strtolower((string) $l))->implode(' | '));
@endphp
{{-- Where you are, then the area in one row: this page first, your favourites, the everyday modules; the rest one step away (in flow) --}}
<div class="pos-spacebar-wrap" {!! $bar === null ? 'hidden' : '' !!} {!! ($bar['landing'] ?? false) ? 'data-pos-landing' : '' !!} x-data="{ open: false, q: '' }" x-on:keydown.escape="open = false">
    @if ($bar !== null)
        @if ($bar['landing'] && filled($bar['current_label']))
            {{-- The bar names this page, so the page header hides its title; the page keeps its heading for assistive tech --}}
            <h1 class="sr-only">{{ $bar['current_label'] }}</h1>
        @endif
        <nav class="pos-spacebar" aria-label="{{ $bar['label'] }}">
            <span class="pos-spacebar-title">{{ $bar['section'] }} <span aria-hidden="true">/</span> {{ $bar['label'] }}</span>
            <div class="pos-spacebar-scroll">
                @foreach ($bar['primary'] as $module)
                    @php($fav = $star($module))
                    @if ($bar['current'] === $module['key'])
                        <span class="pos-spacebar-current" wire:key="pos-area-current-{{ md5($module['key']) }}">
                            <a href="{{ $module['url'] }}" wire:navigate class="pos-spacebar-link" aria-current="page">{{ $module['label'] }}</a>
                            <button type="button" class="pos-spacebar-star" wire:click="toggleFavourite(@js($module['key']))" aria-pressed="{{ $fav['on'] ? 'true' : 'false' }}" aria-label="{{ $fav['label'] }}" title="{{ $fav['label'] }}">
                                <x-filament::icon :icon="$fav['on'] ? 'heroicon-s-star' : 'heroicon-o-star'" class="size-4" />
                            </button>
                        </span>
                    @else
                        <a href="{{ $module['url'] }}" wire:navigate class="pos-spacebar-link" wire:key="pos-area-link-{{ md5($module['key']) }}" @if ($fav['on']) data-pos-fav @endif>
                            @if ($fav['on'])
                                <x-filament::icon icon="heroicon-s-star" class="pos-spacebar-fav-mark size-3" />
                                <span class="sr-only">Favourite:</span>
                            @endif
                            {{ $module['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
            @if ($bar['count'] > 1)
                <div class="pos-spacebar-more-wrap">
                    <button type="button" class="pos-spacebar-more" x-on:click="open = ! open; $nextTick(() => open && $refs.find?.focus())" :aria-expanded="open.toString()" aria-controls="pos-area-all">
                        All in {{ $bar['label'] }} <span class="pos-count">{{ $bar['count'] }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="size-4" />
                    </button>
                </div>
            @endif
        </nav>
        @if ($bar['count'] > 1)
            <div id="pos-area-all" class="pos-spacebar-menu" x-show="open" x-cloak x-collapse>
                <label class="pos-search pos-spacebar-find">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                    <span class="sr-only">Find in {{ $bar['label'] }}</span>
                    <input x-ref="find" x-model="q" type="text" placeholder="Find in {{ $bar['label'] }}" autocomplete="off" />
                </label>
                {{-- Favourites first, in a row of their own; they stay listed in their groups below as well --}}
                <div class="pos-spacebar-favs" @if ($bar['favourites'] !== []) x-show="! q || {{ $match(array_column($bar['favourites'], 'label')) }}.includes(q.toLowerCase())" @else x-show="! q" @endif>
                    <p class="pos-label">
                        <x-filament::icon icon="heroicon-s-star" class="size-3.5" />
                        Favourites
                    </p>
                    @if ($bar['favourites'] === [])
                        <p class="pos-spacebar-favs-empty">Star a page to keep it at the front of this bar.</p>
                    @else
                        <ul>
                            @foreach ($bar['favourites'] as $module)
                                @php($fav = $star($module))
                                <li class="pos-spacebar-fav" wire:key="pos-area-fav-{{ md5($module['key']) }}" x-show="! q || {{ \Illuminate\Support\Js::from(mb_strtolower($module['label'])) }}.includes(q.toLowerCase())">
                                    <a href="{{ $module['url'] }}" wire:navigate @if ($bar['current'] === $module['key']) aria-current="page" @endif>{{ $module['label'] }}</a>
                                    <button type="button" class="pos-spacebar-star" wire:click="toggleFavourite(@js($module['key']))" aria-pressed="true" aria-label="{{ $fav['label'] }}" title="{{ $fav['label'] }}">
                                        <x-filament::icon icon="heroicon-s-star" class="size-4" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="pos-spacebar-groups">
                    @foreach ($bar['groups'] as $group => $modules)
                        <div class="pos-spacebar-mgroup" x-show="! q || {{ $match(array_column($modules, 'label')) }}.includes(q.toLowerCase())">
                            <p class="pos-label">{{ $group }}</p>
                            <ul>
                                @foreach ($modules as $module)
                                    @php($fav = $star($module))
                                    <li class="pos-spacebar-item" wire:key="pos-area-item-{{ md5($module['key']) }}" x-show="! q || {{ \Illuminate\Support\Js::from(mb_strtolower($module['label'])) }}.includes(q.toLowerCase())">
                                        <a href="{{ $module['url'] }}" wire:navigate class="pos-module-link" @if ($bar['current'] === $module['key']) aria-current="page" @endif>{{ $module['label'] }}</a>
                                        <button type="button" class="pos-spacebar-star" wire:click="toggleFavourite(@js($module['key']))" aria-pressed="{{ $fav['on'] ? 'true' : 'false' }}" aria-label="{{ $fav['label'] }}" title="{{ $fav['label'] }}">
                                            <x-filament::icon :icon="$fav['on'] ? 'heroicon-s-star' : 'heroicon-o-star'" class="size-4" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
