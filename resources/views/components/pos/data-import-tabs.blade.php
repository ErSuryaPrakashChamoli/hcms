@props(['current'])
{{-- Data import: the Import and Recent uploads tabs (HROne's Import | Recent Imports), shared by both pages. --}}
<nav class="pos-tabs" aria-label="Data import">
    @if (\App\Filament\Pages\DataImport::canAccess())
        <a href="{{ \App\Filament\Pages\DataImport::getUrl() }}" wire:navigate class="pos-tab" @if ($current === 'import') aria-current="page" @endif>Import</a>
    @endif
    @if (\App\Filament\Pages\RecentUploads::canAccess())
        <a href="{{ \App\Filament\Pages\RecentUploads::getUrl() }}" wire:navigate class="pos-tab" @if ($current === 'recent') aria-current="page" @endif>Recent uploads</a>
    @endif
</nav>
