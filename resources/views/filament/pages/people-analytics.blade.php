<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">As of <input type="date" wire:model.live="asOf" class="fi-input rounded-lg border-gray-300 text-sm dark:bg-gray-900"></label>
    </div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getAreas() as $area)
            <x-filament::section :heading="$area['label']" compact>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                    @foreach ($area['facts'] as $label => $value)
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="text-right font-semibold">{{ $value === null ? '—' : $value }}</dd>
                    @endforeach
                </dl>
                @if ($area['page']::canAccess())
                    <div class="mt-2 text-xs"><a href="{{ $area['page']::getUrl() }}" class="text-primary-600 hover:underline">Open {{ strtolower($area['label']) }} detail →</a></div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
    <p class="text-xs text-gray-500">Each area appears only with that domain's analytics permission. Counts below {{ $this->getMinGroup() }} people are suppressed. Facts only — no individual scores, rankings or predictions.</p>
</x-filament-panels::page>
