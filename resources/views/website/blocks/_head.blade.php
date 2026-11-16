{{-- A section's heading group: eyebrow, heading (h2), lead. --}}
@if (filled($d['eyebrow'] ?? null) || filled($d['heading'] ?? null) || filled($d['text'] ?? null))
    <div @class(['ps-section-head ps-reveal', 'is-center' => $center ?? false])>
        @if (filled($d['eyebrow'] ?? null))<p class="ps-eyebrow">{{ $d['eyebrow'] }}</p>@endif
        @if (filled($d['heading'] ?? null))<h2 class="ps-h2">{{ $d['heading'] }}</h2>@endif
        @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
    </div>
@endif
