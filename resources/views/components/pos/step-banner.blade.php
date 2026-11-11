@props([
    'tone' => 'idle',   // idle (ready, indigo) | clear (done, green) | warning (amber) | blocked (red) | pending (waiting on someone, blue)
    'title',
    'sub' => null,
    'icon' => null,
])
@php
    $icon ??= [
        'clear' => 'heroicon-o-check-circle', 'warning' => 'heroicon-o-exclamation-triangle', 'blocked' => 'heroicon-o-x-circle',
        'pending' => 'heroicon-o-clock', 'idle' => 'heroicon-o-play-circle',
    ][$tone] ?? 'heroicon-o-information-circle';
@endphp
{{-- A step's status, said once at the top of the step: an icon in its tone, one headline, one line under it; the slot holds
     a short list (reasons, what is left); `aside` holds the step's main action or a jump to the step it waits on. --}}
<div {{ $attributes->class('pos-step-banner pos-step-tone') }} data-tone="{{ $tone }}" @if (in_array($tone, ['warning', 'blocked'], true)) role="status" @endif>
    <span class="pos-step-banner-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="pos-step-banner-svg" /></span>
    <div class="pos-step-banner-body">
        <p class="pos-step-banner-title">{{ $title }}</p>
        @if (filled($sub))<p class="pos-step-banner-sub">{{ $sub }}</p>@endif
        {{ $slot }}
    </div>
    @isset($aside)
        <div class="pos-step-banner-aside">{{ $aside }}</div>
    @endisset
</div>
