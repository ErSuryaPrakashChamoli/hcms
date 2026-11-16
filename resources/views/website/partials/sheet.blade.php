{{-- The phone menu: every menu as an accordion, then sign-in and the call to action pinned at the bottom. --}}
@php
    use App\Domain\Website\Support\SiteIcons;
    use App\Domain\Website\Support\SiteLink;
@endphp
<div class="ps-sheet" id="ps-sheet" data-ps-sheet role="dialog" aria-modal="true" aria-label="Menu" aria-hidden="true">
    <div class="ps-sheet-head">
        @include('website.partials.brand', ['site' => $site])
        <button type="button" class="ps-burger" style="display:grid" data-ps-sheet-close aria-label="Close menu">
            {{ svg('heroicon-o-x-mark') }}
        </button>
    </div>
    <nav class="ps-sheet-body" aria-label="Main">
        @foreach ($menus as $menu)
            @php($columns = array_values(array_filter((array) ($menu['columns'] ?? []), fn ($c) => is_array($c) && ! empty($c['links']))))
            @if ($columns === [])
                @if (filled($menu['url'] ?? null))
                    <a class="ps-sheet-link" href="{{ SiteLink::href($menu['url']) }}">{{ $menu['label'] }}</a>
                @endif
            @else
                <details>
                    <summary>{{ $menu['label'] }} {{ svg('heroicon-m-chevron-down', 'ps-chev') }}</summary>
                    <div class="ps-sheet-group">
                        @foreach ($columns as $column)
                            @if (filled($column['heading'] ?? null))<h3>{{ $column['heading'] }}</h3>@endif
                            @foreach ((array) $column['links'] as $link)
                                @continue(! is_array($link) || blank($link['label'] ?? null))
                                <a href="{{ SiteLink::href($link['url'] ?? '') }}" data-hue="{{ SiteIcons::hue($link['hue'] ?? null) }}">
                                    @if (filled($link['icon'] ?? null))<span class="ps-tile" style="width:32px;height:32px">{{ SiteIcons::render($link['icon'], 'ps-icon') }}</span>@endif
                                    <span>{{ $link['label'] }}</span>
                                </a>
                            @endforeach
                        @endforeach
                        @if (filled($menu['url'] ?? null))
                            <a class="ps-link" href="{{ SiteLink::href($menu['url']) }}">All of {{ mb_strtolower($menu['label']) }} <span class="ps-arrow" aria-hidden="true">→</span></a>
                        @endif
                    </div>
                </details>
            @endif
        @endforeach
    </nav>
    <div class="ps-sheet-foot">
        @if (filled($header['cta_label'] ?? null))
            <a class="ps-btn ps-btn-primary" href="{{ SiteLink::href($header['cta_url'] ?? '') }}">{{ $header['cta_label'] }}</a>
        @endif
        @if (filled($header['sign_in_label'] ?? null))
            <a class="ps-btn ps-btn-ghost" href="{{ url('/admin/login') }}">{{ $header['sign_in_label'] }}</a>
        @endif
    </div>
</div>
