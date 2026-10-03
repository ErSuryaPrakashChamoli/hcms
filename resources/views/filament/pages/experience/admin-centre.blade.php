<x-filament-panels::page>
    <div x-data="{ q: '' }">
        <label class="pos-search max-w-md">
            <span class="sr-only">Filter modules</span>
            <x-filament::icon icon="heroicon-m-funnel" class="size-4 pos-muted" />
            <input type="search" x-model="q" placeholder="Filter modules" autocomplete="off" />
        </label>
        <div class="mt-6 space-y-8">
            @foreach ($this->sections as $section => $groups)
                <section aria-labelledby="ac-{{ \Illuminate\Support\Str::slug($section) }}" x-show="! q || $el.innerText.toLowerCase().includes(q.toLowerCase())">
                    <h2 id="ac-{{ \Illuminate\Support\Str::slug($section) }}" class="pos-h2">{{ $section }}</h2>
                    <div class="pos-module-groups mt-3">
                        @foreach ($groups as $group => $modules)
                            <div class="pos-card" x-show="! q || $el.innerText.toLowerCase().includes(q.toLowerCase())">
                                <p class="pos-label">{{ $group }}</p>
                                <ul class="mt-2 space-y-0.5">
                                    @foreach ($modules as $m)
                                        <li x-show="! q || @js(mb_strtolower($m['label'].' '.$group)).includes(q.toLowerCase())">
                                            <a href="{{ $m['url'] }}" wire:navigate class="pos-module-link">
                                                @if (is_string($m['icon']) || $m['icon'] instanceof \BackedEnum)<x-filament::icon :icon="$m['icon']" class="size-4 pos-muted" />@endif
                                                <span>{{ $m['label'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
