<span id="pos-main" tabindex="-1" class="sr-only">Main content</span>
@auth
    @php($posBar = app(\App\Domain\Experience\Services\ExperienceNavigation::class)->areaBar(auth()->user()))
    @if ($posBar)
        {{-- UX.15 area bar: where you are, the everyday modules of this area, and the rest one step away. --}}
        <nav class="pos-spacebar" aria-label="{{ $posBar['label'] }}">
            <span class="pos-spacebar-title">{{ $posBar['section'] }} <span aria-hidden="true">/</span> {{ $posBar['label'] }}</span>
            <div class="pos-spacebar-scroll">
                @foreach ($posBar['primary'] as $module)
                    <a href="{{ $module['url'] }}" wire:navigate class="pos-spacebar-link" @if ($posBar['current'] === $module['key']) aria-current="page" @endif>{{ $module['label'] }}</a>
                @endforeach
            </div>
            @if ($posBar['more'] !== [])
                <div class="pos-spacebar-more-wrap" x-data="{ open: false, q: '' }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                    <button type="button" class="pos-spacebar-more" x-on:click="open = ! open; $nextTick(() => open && $refs.find?.focus())" :aria-expanded="open.toString()" aria-controls="pos-area-all">
                        All in {{ $posBar['label'] }} <span class="pos-count">{{ $posBar['count'] }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="size-4" />
                    </button>
                    <div id="pos-area-all" class="pos-spacebar-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                        <label class="pos-search pos-spacebar-find">
                            <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 pos-muted" />
                            <span class="sr-only">Find in {{ $posBar['label'] }}</span>
                            <input x-ref="find" x-model="q" type="text" placeholder="Find in {{ $posBar['label'] }}" autocomplete="off" />
                        </label>
                        <div class="pos-spacebar-groups">
                            @foreach ($posBar['more'] as $group => $modules)
                                <div class="pos-spacebar-mgroup" x-show="! q || {{ \Illuminate\Support\Js::from(collect($modules)->pluck('label')->map(fn ($l) => mb_strtolower($l))->implode(' | ')) }}.includes(q.toLowerCase())">
                                    <p class="pos-label">{{ $group }}</p>
                                    <ul>
                                        @foreach ($modules as $module)
                                            <li x-show="! q || {{ \Illuminate\Support\Js::from(mb_strtolower($module['label'])) }}.includes(q.toLowerCase())">
                                                <a href="{{ $module['url'] }}" wire:navigate class="pos-module-link">{{ $module['label'] }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </nav>
    @endif
@endauth
