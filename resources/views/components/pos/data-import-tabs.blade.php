@props(['current'])
{{-- Data import: the Import, Recent uploads and Employee documents tabs (HROne's Import | Recent Imports, and the zips of
     employees' documents), shared by those pages. --}}
<nav class="pos-tabs" aria-label="Data import">
    @if (\App\Filament\Pages\DataImport::canAccess())
        <a href="{{ \App\Filament\Pages\DataImport::getUrl() }}" wire:navigate class="pos-tab" @if ($current === 'import') aria-current="page" @endif>Import</a>
    @endif
    @if (\App\Filament\Pages\RecentUploads::canAccess())
        <a href="{{ \App\Filament\Pages\RecentUploads::getUrl() }}" wire:navigate class="pos-tab" @if ($current === 'recent') aria-current="page" @endif>Recent uploads</a>
    @endif
    @if (\App\Filament\Pages\EmployeeDocumentUpload::canAccess())
        <a href="{{ \App\Filament\Pages\EmployeeDocumentUpload::getUrl() }}" wire:navigate class="pos-tab" @if ($current === 'documents') aria-current="page" @endif>Employee documents</a>
    @endif
</nav>
