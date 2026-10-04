@props([
    'variant' => 'empty',          // empty | caught-up | filtered | loading | error | denied | stale | partial
    'title' => null,
    'why' => null,
    'icon' => null,
    'size' => 'block',             // block | inline
    'retry' => null,               // Livewire method to retry (error, partial, stale)
    'action' => null, 'actionUrl' => null,
])
@php
    $icon ??= [
        'empty' => 'heroicon-o-inbox', 'caught-up' => 'heroicon-o-check', 'filtered' => 'heroicon-o-funnel', 'loading' => 'heroicon-o-arrow-path',
        'error' => 'heroicon-o-exclamation-triangle', 'denied' => 'heroicon-o-lock-closed', 'stale' => 'heroicon-o-clock', 'partial' => 'heroicon-o-exclamation-triangle',
    ][$variant] ?? 'heroicon-o-inbox';
    $title ??= [
        'caught-up' => 'You’re all caught up.', 'filtered' => 'Nothing matches these filters.', 'loading' => 'Loading…',
        'error' => 'This couldn’t load.', 'denied' => 'You don’t have access to this.', 'stale' => 'This may be out of date.', 'partial' => 'Part of this page couldn’t load.',
    ][$variant] ?? 'Nothing here yet.';
@endphp
{{-- PeopleState: says what the state is, why, and the next step. Errors never blame; denials explain. --}}
<div {{ $attributes->class(['pos-state']) }} data-variant="{{ $variant }}" data-size="{{ $size }}"
    @if (in_array($variant, ['error', 'partial'], true)) role="alert" @elseif ($variant === 'loading') role="status" aria-live="polite" @endif>
    <span class="pos-state-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-5" /></span>
    <div class="pos-state-text">
        <p class="pos-state-title">{{ $title }}</p>
        @if ($why)<p class="pos-state-why">{{ $why }}</p>@endif
        @if ($retry || ($action && $actionUrl) || $slot->isNotEmpty())
            <div class="pos-state-actions">
                @if ($retry)<button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="{{ $retry }}">Try again</button>@endif
                @if ($action && $actionUrl)<a href="{{ $actionUrl }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">{{ $action }}</a>@endif
                {{ $slot }}
            </div>
        @endif
    </div>
</div>
