<x-filament-panels::page>
    @php($modules = $this->modules())
    @php($current = $this->current())
    <div class="grid gap-6 lg:grid-cols-4">
        <nav aria-label="Setup modules">
            <x-filament::section compact>
                <ul class="grid gap-1">
                    @foreach ($modules as $key => $module)
                        <li>
                            <button type="button" wire:click="selectModule('{{ $key }}')"
                                @class([
                                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-sm',
                                    'bg-primary-50 font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300' => $key === $this->module,
                                    'hover:underline' => $key !== $this->module,
                                ])
                                @if ($key === $this->module) aria-current="page" @endif>
                                <x-filament::icon :icon="$module['icon'] ?? 'heroicon-o-cog-6-tooth'" class="h-5 w-5" />
                                <span>{{ $module['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        </nav>

        <div class="grid gap-6 lg:col-span-3">
            <x-filament::section :heading="$current['label'] ?? 'Setup'" :description="$current['description'] ?? null">
                @php($links = $this->links())
                @if ($links === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">No configuration screens of this module are open to you.</p>
                @else
                    <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($links as $link)
                            <li>
                                <a href="{{ $link['url'] }}" wire:navigate class="flex items-center justify-between rounded-lg px-3 py-2 text-sm ring-1 ring-gray-950/10 hover:underline dark:ring-white/10">
                                    <span>{{ $link['label'] }}</span>
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-400" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            @php($types = $this->policyTypes())
            @if ($types !== [])
                <x-filament::section heading="Policies" description="Rules that apply to groups of employees (by company, location, grade and more). Each policy is versioned and assigned by rules.">
                    <ul class="grid gap-2 sm:grid-cols-2">
                        @foreach ($types as $type)
                            <li class="flex items-center justify-between rounded-lg px-3 py-2 text-sm ring-1 ring-gray-950/10 dark:ring-white/10">
                                <span>{{ $type['label'] }}</span>
                                <span class="flex items-center gap-3">
                                    <x-filament::badge :color="$type['policies'] > 0 ? 'success' : 'gray'">{{ $type['policies'] }} {{ \Illuminate\Support\Str::plural('policy', $type['policies']) }}</x-filament::badge>
                                    @if ($type['url'])
                                        <a href="{{ $type['url'] }}" wire:navigate class="text-primary-600 hover:underline dark:text-primary-400">Open</a>
                                    @endif
                                    @if ($type['create_url'] ?? null)
                                        <a href="{{ $type['create_url'] }}" wire:navigate class="text-primary-600 hover:underline dark:text-primary-400">Add policy</a>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            @if ($this->hasSettings())
                <form wire:submit="save">
                    {{ $this->form }}
                    @if ($this->canSave())
                        <div class="mt-4"><x-filament::button type="submit">Save settings</x-filament::button></div>
                    @endif
                </form>
            @endif
        </div>
    </div>
</x-filament-panels::page>
