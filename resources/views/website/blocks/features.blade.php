@php
    use App\Domain\Website\Support\SiteIcons;
    use App\Domain\Website\Support\SiteLink;
    $items = array_values(array_filter((array) ($d['items'] ?? []), fn ($i) => is_array($i) && filled($i['title'] ?? null)));
    $cols = in_array((string) ($d['columns'] ?? '3'), ['2', '3', '4'], true) ? (int) $d['columns'] : 3;
    $tone = in_array($d['tone'] ?? 'light', ['light', 'tint', 'ink'], true) ? ($d['tone'] ?? 'light') : 'light';
@endphp
<section @class(['ps-section', 'is-tint' => $tone === 'tint', 'is-ink ps-on-ink' => $tone === 'ink'])>
    <div class="ps-wrap">
        @include('website.blocks._head', ['d' => $d, 'center' => true])
        <div class="ps-grid" style="--cols: {{ $cols }}">
            @foreach ($items as $n => $item)
                @php($href = filled($item['url'] ?? null) ? SiteLink::href($item['url']) : null)
                <{{ $href ? 'a' : 'div' }} class="ps-card ps-reveal" data-hue="{{ SiteIcons::hue($item['hue'] ?? null) }}" @if ($href) href="{{ $href }}" @endif style="transition-delay: {{ min($n, 8) * 40 }}ms">
                    @if (filled($item['icon'] ?? null))<span class="ps-tile">{{ SiteIcons::render($item['icon']) }}</span>@endif
                    <h3>{{ $item['title'] }}</h3>
                    @if (filled($item['text'] ?? null))<p>{{ $item['text'] }}</p>@endif
                    @if ($href)<span class="ps-link">Learn more <span class="ps-arrow" aria-hidden="true">→</span></span>@endif
                </{{ $href ? 'a' : 'div' }}>
            @endforeach
        </div>
    </div>
</section>
