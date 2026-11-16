@php(
    $link = \App\Domain\Website\Support\SiteLink::class
)
<section class="ps-section">
    <div class="ps-wrap">
        <div class="ps-cta ps-on-ink ps-reveal">
            @if (filled($d['heading'] ?? null))<h2 class="ps-h2" style="position:relative">{{ $d['heading'] }}</h2>@endif
            @if (filled($d['text'] ?? null))<p class="ps-lead">{{ $d['text'] }}</p>@endif
            <div class="ps-hero-actions">
                @if (filled($d['primary_label'] ?? null))
                    <a class="ps-btn ps-btn-gold" href="{{ $link::href($d['primary_url'] ?? '') }}">{{ $d['primary_label'] }} {{ svg('heroicon-m-arrow-right', 'ps-icon') }}</a>
                @endif
                @if (filled($d['secondary_label'] ?? null))
                    <a class="ps-btn ps-btn-ghost" href="{{ $link::href($d['secondary_url'] ?? '') }}">{{ $d['secondary_label'] }}</a>
                @endif
            </div>
        </div>
    </div>
</section>
