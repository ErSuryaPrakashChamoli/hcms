@php
    use App\Domain\Website\Support\SiteIcons;
    use App\Domain\Website\Support\SiteLink;
    $bullets = array_values(array_filter((array) ($d['bullets'] ?? []), fn ($b) => is_array($b) && filled($b['text'] ?? null)));
    $visual = $d['visual'] ?? 'workspace';
    $hasVisual = $visual !== 'none' && array_key_exists((string) $visual, \App\Domain\Website\Support\SiteDefaults::VISUALS);
@endphp
<section class="ps-section">
    <div class="ps-wrap ps-spot {{ ($d['reverse'] ?? false) ? 'is-reverse' : '' }}" data-hue="{{ SiteIcons::hue($d['hue'] ?? null) }}">
        <div class="ps-spot-copy ps-reveal">
            @if (filled($d['eyebrow'] ?? null))<p class="ps-eyebrow">{{ $d['eyebrow'] }}</p>@endif
            @if (filled($d['heading'] ?? null))<h2 class="ps-h2">{{ $d['heading'] }}</h2>@endif
            @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
            @if ($bullets !== [])
                <ul class="ps-checks">
                    @foreach ($bullets as $b)<li>{{ svg('heroicon-m-check') }}<span>{{ $b['text'] }}</span></li>@endforeach
                </ul>
            @endif
            @if (filled($d['cta_label'] ?? null))
                <p style="margin:6px 0 0"><a class="ps-link" href="{{ SiteLink::href($d['cta_url'] ?? '') }}">{{ $d['cta_label'] }} <span class="ps-arrow" aria-hidden="true">→</span></a></p>
            @endif
        </div>
        @if ($hasVisual)
            <div class="ps-stage ps-reveal ps-on-light" aria-hidden="true">@include('website.visuals.'.$visual, ['onInk' => false])</div>
        @endif
    </div>
</section>
