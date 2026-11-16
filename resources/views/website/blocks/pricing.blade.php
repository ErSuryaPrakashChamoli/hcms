@php
    use App\Domain\Website\Support\SiteLink;
    $plans = array_values(array_filter((array) ($d['plans'] ?? []), fn ($p) => is_array($p) && filled($p['name'] ?? null)));
@endphp
<section class="ps-section" id="plans">
    <div class="ps-wrap">
        @include('website.blocks._head', ['d' => $d, 'center' => true])
        <div class="ps-plans" style="--cols: {{ min(4, max(1, count($plans))) }}">
            @foreach ($plans as $n => $plan)
                @php($featured = (bool) ($plan['highlighted'] ?? false))
                @php($features = array_values(array_filter((array) ($plan['features'] ?? []), fn ($f) => is_array($f) && filled($f['text'] ?? null))))
                <article @class(['ps-plan ps-reveal', 'is-featured ps-on-ink' => $featured]) style="transition-delay: {{ $n * 60 }}ms">
                    @if (filled($plan['badge'] ?? null))<span class="ps-plan-badge">{{ $plan['badge'] }}</span>@endif
                    <h3>{{ $plan['name'] }}</h3>
                    @if (filled($plan['description'] ?? null))<p class="ps-plan-desc">{{ $plan['description'] }}</p>@endif
                    <div class="ps-price">
                        @if (filled($plan['price'] ?? null))
                            <b @class(['is-text' => ! preg_match('/\d/', (string) $plan['price'])])>{{ $plan['price'] }}</b>
                            @if (filled($plan['period'] ?? null) && preg_match('/\d/', (string) $plan['price']))<span>{{ $plan['period'] }}</span>@endif
                        @else
                            <b class="is-text">Ask us</b>
                        @endif
                    </div>
                    @if (filled($plan['note'] ?? null))<p class="ps-plan-note">{{ $plan['note'] }}</p>@endif
                    @if ($features !== [])
                        <ul class="ps-checks">
                            @foreach ($features as $f)<li>{{ svg('heroicon-m-check') }}<span>{{ $f['text'] }}</span></li>@endforeach
                        </ul>
                    @endif
                    @if (filled($plan['cta_label'] ?? null))
                        <a class="ps-btn {{ $featured ? 'ps-btn-gold' : 'ps-btn-ghost' }}" href="{{ SiteLink::href($plan['cta_url'] ?? '') }}">{{ $plan['cta_label'] }}</a>
                    @endif
                </article>
            @endforeach
        </div>
        @if (filled($d['footnote'] ?? null))<p class="ps-footnote">{{ $d['footnote'] }}</p>@endif
    </div>
</section>
