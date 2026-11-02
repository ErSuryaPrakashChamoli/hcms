@props(['groups', 'label', 'remember' => null])
{{--
    PeopleBrowse: areas and the screens in each. The areas run down the left; the open one's screens fill the space
    beside them in two or three columns. Where the space is narrow it reads as an accordion (an area opens under its own
    heading). One area is open at a time: the first, or the one last opened on this page during the visit (`remember`).
    groups: list of [key, label, sub?, icon?, items: list of [label, url, description?, count?],
    sets?: list of [label, items: list of [label, url]]] — sets are named lists shown after the items (a module's settings).
--}}
@php($groups = array_values(array_filter($groups, fn (array $g) => ($g['items'] ?? []) !== [] || ($g['sets'] ?? []) !== [])))
@php($uid = 'pos-browse-'.substr(md5($label), 0, 6))
@if ($groups !== [])
    <div class="pos-browse-wrap">
        <div {{ $attributes->class(['pos-browse']) }} style="--pos-browse-rows: {{ count($groups) }}" x-data="posBrowse(@js($remember))" x-on:keydown="move($event)" role="group" aria-label="{{ $label }}">
            @foreach ($groups as $i => $group)
                @php($key = (string) $group['key'])
                @php($id = $uid.'-'.\Illuminate\Support\Str::slug($key))
                @php($items = $group['items'] ?? [])
                @php($sets = $group['sets'] ?? [])
                @php($setCount = array_sum(array_map(fn (array $set) => count($set['items']), $sets)))
                <button type="button" id="{{ $id }}-head" class="pos-browse-head" data-browse-key="{{ $key }}" aria-controls="{{ $id }}"
                    aria-expanded="{{ $i === 0 ? 'true' : 'false' }}" x-bind:aria-expanded="(open === @js($key)).toString()" x-on:click="toggle(@js($key))">
                    @if (! empty($group['icon']))<span class="pos-browse-icon" aria-hidden="true"><x-filament::icon :icon="$group['icon']" class="size-4" /></span>@endif
                    <span class="pos-browse-label">{{ $group['label'] }}</span>
                    <span class="pos-browse-count">{{ count($items) + $setCount }}</span>
                    <x-filament::icon icon="heroicon-m-chevron-right" class="pos-browse-chevron size-4" />
                </button>
                <section id="{{ $id }}" class="pos-panel pos-browse-panel" aria-labelledby="{{ $id }}-title" x-show="open === @js($key)" @if ($i > 0) x-cloak @endif>
                    <header class="pos-browse-panel-head">
                        @if (! empty($group['icon']))<span class="pos-browse-panel-icon" aria-hidden="true"><x-filament::icon :icon="$group['icon']" class="size-5" /></span>@endif
                        <div class="min-w-0">
                            <h3 id="{{ $id }}-title" class="pos-browse-panel-title">{{ $group['label'] }}</h3>
                            @if (! empty($group['sub']))<p class="pos-browse-panel-sub">{{ $group['sub'] }}</p>@endif
                        </div>
                    </header>
                    <div class="pos-browse-panel-body">
                        @if ($items !== [])
                            <div class="pos-browse-zone">
                                @if ($sets !== [])<h4 class="pos-browse-zone-title">Screens <span>{{ count($items) }}</span></h4>@endif
                                <ul class="pos-browse-items">
                                    @foreach ($items as $item)
                                        <li>
                                            <a href="{{ $item['url'] }}" wire:navigate class="pos-browse-link">
                                                <span class="pos-browse-link-body">
                                                    <span class="pos-browse-link-label">{{ $item['label'] }}</span>
                                                    @if (! empty($item['description']))<span class="pos-browse-link-meta">{{ $item['description'] }}</span>@endif
                                                </span>
                                                @if (($item['count'] ?? null) !== null)<span class="pos-browse-link-count">{{ $item['count'] }}<span class="sr-only"> open</span></span>@endif
                                                <x-filament::icon icon="heroicon-m-arrow-right" class="pos-browse-link-go size-4" />
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($sets !== [])
                            <div class="pos-browse-zone">
                                <h4 class="pos-browse-zone-title">Settings <span>{{ $setCount }}</span></h4>
                                <div class="pos-browse-sets">
                                    @foreach ($sets as $set)
                                        <section class="pos-browse-set" aria-label="{{ $set['label'] }}">
                                            <h5 class="pos-browse-set-title">{{ $set['label'] }}</h5>
                                            <ul>
                                                @foreach ($set['items'] as $item)
                                                    <li><a href="{{ $item['url'] }}" wire:navigate class="pos-browse-setlink">{{ $item['label'] }}</a></li>
                                                @endforeach
                                            </ul>
                                        </section>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
            @endforeach
            <p class="pos-browse-none" x-show="open === null" x-cloak>Choose an area to see what is in it.</p>
        </div>
    </div>
@endif
