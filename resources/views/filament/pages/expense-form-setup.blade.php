<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        @if ($this->canEdit())
            <div class="mt-4"><x-filament::button type="submit">Save forms</x-filament::button></div>
        @endif
    </form>
</x-filament-panels::page>
