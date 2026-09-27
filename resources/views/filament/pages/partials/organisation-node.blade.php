@php($inactive = $node->status === \App\Domain\Organisation\Enums\ActiveStatus::Inactive)
<li x-data="{ open: true }" wire:key="node-{{ $node->id }}">
    <div @class(['pos-node', 'is-inactive' => $inactive, 'is-match' => (bool) ($node->matches_search ?? false)])>
        <span class="pos-toggle" x-on:click="open = !open" x-text="open ? '▾' : '▸'" style="{{ $node->children->isEmpty() ? 'visibility:hidden' : '' }}"></span>
        <x-filament::badge color="gray" size="sm">{{ $node->typeLabel() }}</x-filament::badge>
        <span class="pos-name">{{ $node->nodeable?->name ?? '—' }}</span>
        <span class="pos-code">{{ $node->nodeable?->code }}</span>
        @if ($inactive)
            <x-filament::badge color="danger" size="sm">Inactive</x-filament::badge>
        @endif
        <div class="pos-actions">
            <x-filament::icon-button icon="heroicon-m-plus" label="Add child" size="sm" color="gray" wire:click="mountAction('addChild', { node: {{ $node->id }} })" />
            <x-filament::icon-button icon="heroicon-m-pencil-square" label="Rename" size="sm" color="gray" wire:click="mountAction('rename', { node: {{ $node->id }} })" />
            <x-filament::icon-button icon="heroicon-m-arrows-right-left" label="Move" size="sm" color="gray" wire:click="mountAction('move', { node: {{ $node->id }} })" />
            <x-filament::icon-button icon="heroicon-m-chevron-up" label="Move up" size="sm" color="gray" wire:click="mountAction('moveUp', { node: {{ $node->id }} })" />
            <x-filament::icon-button icon="heroicon-m-chevron-down" label="Move down" size="sm" color="gray" wire:click="mountAction('moveDown', { node: {{ $node->id }} })" />
            @if ($inactive)
                <x-filament::icon-button icon="heroicon-m-play-circle" label="Reactivate" size="sm" color="success" wire:click="mountAction('reactivate', { node: {{ $node->id }} })" />
            @else
                <x-filament::icon-button icon="heroicon-m-pause-circle" label="Deactivate" size="sm" color="danger" wire:click="mountAction('deactivate', { node: {{ $node->id }} })" />
            @endif
            @if ($node->children->isEmpty())
                <x-filament::icon-button icon="heroicon-m-x-mark" label="Remove from tree" size="sm" color="danger" wire:click="mountAction('remove', { node: {{ $node->id }} })" />
            @endif
        </div>
    </div>
    @if ($node->children->isNotEmpty())
        <ul x-show="open" x-collapse>
            @foreach ($node->children as $child)
                @include('filament.pages.partials.organisation-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
