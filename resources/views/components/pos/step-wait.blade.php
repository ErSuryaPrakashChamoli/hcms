@props([
    'title',
    'text' => null,
    'icon' => 'heroicon-o-lock-closed',
    'go' => null,        // the step key this one waits on (the page's goTo method)
    'goLabel' => null,
])
{{-- A step that cannot be used yet: what it waits on, why, and a jump back to that step. --}}
<div {{ $attributes->class('pos-step-wait') }}>
    <span class="pos-step-wait-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="pos-step-wait-svg" /></span>
    <p class="pos-step-wait-title">{{ $title }}</p>
    @if (filled($text))<p class="pos-step-wait-text">{{ $text }}</p>@endif
    @if ($go || $slot->isNotEmpty())
        <div class="pos-step-wait-actions">
            @if ($go)
                <x-filament::button color="gray" size="sm" icon="heroicon-m-arrow-left" wire:click="goTo('{{ $go }}')">{{ $goLabel ?? 'Go back' }}</x-filament::button>
            @endif
            {{ $slot }}
        </div>
    @endif
</div>
