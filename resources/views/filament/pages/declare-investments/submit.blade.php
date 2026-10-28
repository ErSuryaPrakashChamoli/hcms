@if ($page->editable())
    <div class="flex flex-wrap gap-3">
        <x-filament::button type="button" color="gray" wire:click="saveDraft">Save as draft</x-filament::button>
        <x-filament::button type="submit" icon="heroicon-m-paper-airplane">Submit to payroll</x-filament::button>
    </div>
@endif
