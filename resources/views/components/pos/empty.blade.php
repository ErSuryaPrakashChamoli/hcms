@props(['icon' => 'heroicon-o-sparkles', 'title', 'why' => null, 'action' => null, 'actionUrl' => null])
{{-- Empty state: WHAT is empty, WHY, and the NEXT step. --}}
<div {{ $attributes->class(['pos-empty']) }}>
    <span class="pos-icon-tile" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-5" /></span>
    <p class="pos-h3">{{ $title }}</p>
    @if ($why)<p class="pos-body-sm pos-muted">{{ $why }}</p>@endif
    @if ($action && $actionUrl)
        <a href="{{ $actionUrl }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">{{ $action }}</a>
    @endif
    {{ $slot }}
</div>
