@php
    $node = $nodes[$id] ?? null;
    $kids = $this->childrenOf($id);
    $isOpen = in_array($id, $this->expanded, true);
@endphp
@if ($node)
    <li class="pos-org-branch" role="treeitem" aria-expanded="{{ count($kids) ? ($isOpen ? 'true' : 'false') : 'undefined' }}" aria-level="{{ $level }}" wire:key="org-{{ $id }}">
        <div class="pos-org-node {{ $this->focus === $id ? 'is-focus' : '' }}" data-org-id="{{ $id }}">
            <button type="button" class="pos-org-card" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $id }} })">
                <x-pos.avatar :name="$node->display_name" size="sm" />
                <span class="min-w-0 text-start">
                    <span class="pos-body-sm font-medium block truncate">{{ $node->display_name }}</span>
                    <span class="pos-caption pos-muted block truncate">{{ $node->currentPosition?->designation?->name ?? $node->employee_code }}</span>
                    @if ($node->currentPosition?->department)<span class="pos-caption pos-muted block truncate">{{ $node->currentPosition->department->name }}</span>@endif
                </span>
            </button>
            @if (count($kids))
                <button type="button" class="pos-org-toggle" wire:click="toggle({{ $id }})" aria-label="{{ $isOpen ? 'Collapse' : 'Expand' }} {{ count($kids) }} direct {{ \Illuminate\Support\Str::plural('report', count($kids)) }}">
                    <span class="pos-num">{{ count($kids) }}</span>
                    <x-filament::icon :icon="$isOpen ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'" class="size-3.5" />
                </button>
            @endif
        </div>
        @if ($isOpen && count($kids))
            @php($limit = $this->shownFor($id))
            @php($childNodes = $this->nodes(array_slice($kids, 0, $limit)))
            <ul class="pos-org-children" role="group">
                @foreach (array_slice($kids, 0, $limit) as $kid)
                    @include('filament.pages.experience.org-node', ['id' => $kid, 'nodes' => $childNodes, 'level' => $level + 1])
                @endforeach
                @if (count($kids) > $limit)
                    <li class="pos-org-branch"><button type="button" class="pos-org-more" wire:click="showMore({{ $id }})">+ {{ count($kids) - $limit }} more</button></li>
                @endif
            </ul>
        @endif
    </li>
@endif
