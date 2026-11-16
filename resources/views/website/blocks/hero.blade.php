@php
    use App\Domain\Website\Support\SiteLink;
    $tone = ($d['tone'] ?? 'ink') === 'light' ? 'light' : 'ink';
    $visual = $d['visual'] ?? 'none';
    $hasVisual = $visual !== 'none' && array_key_exists((string) $visual, \App\Domain\Website\Support\SiteDefaults::VISUALS);
    $chips = array_values(array_filter((array) ($d['chips'] ?? []), fn ($c) => is_array($c) && filled($c['text'] ?? null)));
    $tag = $index === 0 ? 'h1' : 'h2';
@endphp
<section @class(['ps-hero', 'is-ink ps-on-ink' => $tone === 'ink', 'is-light ps-on-light' => $tone === 'light', 'is-solo' => ! $hasVisual])>
    <div class="ps-wrap ps-hero-grid">
        <div class="ps-hero-copy">
            @if (filled($d['eyebrow'] ?? null))
                <p class="ps-hero-pill"><i>{{ svg('heroicon-m-sparkles') }}</i>{{ $d['eyebrow'] }}</p>
            @endif
            <{{ $tag }} class="ps-h1">{{ $d['title'] ?? '' }}@if (filled($d['highlight'] ?? null)) <em>{{ $d['highlight'] }}</em>@endif</{{ $tag }}>
            @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
            @if (filled($d['primary_label'] ?? null) || filled($d['secondary_label'] ?? null))
                <div class="ps-hero-actions">
                    @if (filled($d['primary_label'] ?? null))
                        <a class="ps-btn {{ $tone === 'ink' ? 'ps-btn-gold' : 'ps-btn-primary' }}" href="{{ SiteLink::href($d['primary_url'] ?? '') }}">{{ $d['primary_label'] }} {{ svg('heroicon-m-arrow-right', 'ps-icon') }}</a>
                    @endif
                    @if (filled($d['secondary_label'] ?? null))
                        <a class="ps-btn ps-btn-ghost" href="{{ SiteLink::href($d['secondary_url'] ?? '') }}">{{ $d['secondary_label'] }}</a>
                    @endif
                </div>
            @endif
            @if ($chips !== [])
                <ul class="ps-chips">
                    @foreach ($chips as $chip)<li>{{ svg('heroicon-m-check-circle') }} {{ $chip['text'] }}</li>@endforeach
                </ul>
            @endif
        </div>
        @if ($hasVisual)
            <div class="ps-hero-visual" aria-hidden="true">@include('website.visuals.'.$visual, ['onInk' => $tone === 'ink'])</div>
        @endif
    </div>
</section>
