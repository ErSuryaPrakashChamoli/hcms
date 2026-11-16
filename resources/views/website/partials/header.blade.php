{{--
    Site header: wordmark, menus (a menu with links opens a panel: columns of links with icon tiles and descriptions, an
    optional feature card and a footer strip; one column without a feature card is a compact dropdown), sign-in and the
    call to action. Below 1080px the menus move into the phone sheet (website.partials.sheet).
--}}
@php
    use App\Domain\Website\Support\SiteIcons;
    use App\Domain\Website\Support\SiteLink;
    $header = $site->section('header');
    $menus = $site->menus();
    $current = '/'.$slug;
@endphp
<header class="ps-header" data-ps-header>
    <div class="ps-wrap ps-header-inner">
        @include('website.partials.brand', ['site' => $site])

        <nav class="ps-nav" aria-label="Main">
            @foreach ($menus as $m => $menu)
                @php
                    $columns = array_values(array_filter((array) ($menu['columns'] ?? []), fn ($c) => is_array($c) && ! empty($c['links'])));
                    $feature = (array) ($menu['feature'] ?? []);
                    $hasFeature = ($feature['enabled'] ?? false) && filled($feature['title'] ?? null);
                    $compact = count($columns) === 1 && ! $hasFeature;
                    $panelId = 'ps-menu-'.$m;
                @endphp
                @if ($columns === [])
                    @if (filled($menu['url'] ?? null))
                        <a class="ps-nav-link" href="{{ SiteLink::href($menu['url']) }}" @if (rtrim($menu['url'], '/') === rtrim($current, '/') && $current !== '/') aria-current="page" @endif>{{ $menu['label'] }}</a>
                    @endif
                @else
                    <div @class(['ps-nav-item', 'has-compact' => $compact]) data-ps-menu>
                        <button type="button" class="ps-nav-trigger" data-ps-trigger aria-expanded="false" aria-controls="{{ $panelId }}">
                            {{ $menu['label'] }} {{ svg('heroicon-m-chevron-down', 'ps-chev') }}
                        </button>
                        <div id="{{ $panelId }}" @class(['ps-mega', 'is-compact' => $compact]) data-ps-panel>
                            <div class="ps-mega-panel">
                                <div class="ps-mega-body">
                                    <div class="ps-mega-cols" style="--cols: {{ max(1, count($columns)) }}">
                                        @foreach ($columns as $column)
                                            <div class="ps-mega-col">
                                                @if (filled($column['heading'] ?? null))<h3>{{ $column['heading'] }}</h3>@endif
                                                <ul>
                                                    @foreach ((array) $column['links'] as $link)
                                                        @continue(! is_array($link) || blank($link['label'] ?? null))
                                                        <li>
                                                            <a class="ps-mega-link" href="{{ SiteLink::href($link['url'] ?? '') }}" data-hue="{{ SiteIcons::hue($link['hue'] ?? null) }}"
                                                                @if (SiteLink::isExternal($link['url'] ?? '')) target="_blank" rel="noopener" @endif>
                                                                @if (filled($link['icon'] ?? null))<span class="ps-tile">{{ SiteIcons::render($link['icon']) }}</span>@endif
                                                                <span class="ps-mega-text">
                                                                    <span class="ps-mega-label">{{ $link['label'] }} @if (filled($link['badge'] ?? null))<span class="ps-badge">{{ $link['badge'] }}</span>@endif</span>
                                                                    @if (filled($link['description'] ?? null))<span class="ps-mega-desc">{{ $link['description'] }}</span>@endif
                                                                </span>
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if ($hasFeature)
                                        <aside class="ps-mega-feature">
                                            @if (filled($feature['eyebrow'] ?? null))<p class="ps-eyebrow">{{ $feature['eyebrow'] }}</p>@endif
                                            <h3>{{ $feature['title'] }}</h3>
                                            @if (filled($feature['text'] ?? null))<p>{{ $feature['text'] }}</p>@endif
                                            @if (array_key_exists((string) ($feature['visual'] ?? 'none'), \App\Domain\Website\Support\SiteDefaults::VISUALS) && ($feature['visual'] ?? 'none') !== 'none')
                                                <div class="ps-mega-visual" aria-hidden="true">@include('website.visuals.'.$feature['visual'], ['onInk' => true])</div>
                                            @endif
                                            @if (filled($feature['cta_label'] ?? null))
                                                <a class="ps-link" href="{{ SiteLink::href($feature['cta_url'] ?? '') }}">{{ $feature['cta_label'] }} <span class="ps-arrow" aria-hidden="true">→</span></a>
                                            @endif
                                        </aside>
                                    @endif
                                </div>
                                @if (filled($menu['footer_text'] ?? null) || filled($menu['footer_link_label'] ?? null))
                                    <div class="ps-mega-foot">
                                        <span>{{ $menu['footer_text'] ?? '' }}</span>
                                        @if (filled($menu['footer_link_label'] ?? null))
                                            <a class="ps-link" href="{{ SiteLink::href($menu['footer_link_url'] ?? '') }}">{{ $menu['footer_link_label'] }} <span class="ps-arrow" aria-hidden="true">→</span></a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="ps-header-actions">
            @if (filled($header['sign_in_label'] ?? null))
                <a class="ps-btn ps-btn-ghost ps-btn-sm ps-hide-sm" href="{{ url('/admin/login') }}">{{ $header['sign_in_label'] }}</a>
            @endif
            @if (filled($header['cta_label'] ?? null))
                <a class="ps-btn ps-btn-primary ps-btn-sm ps-hide-sm" href="{{ SiteLink::href($header['cta_url'] ?? '') }}">{{ $header['cta_label'] }}</a>
            @endif
            <button type="button" class="ps-burger" data-ps-sheet-open aria-expanded="false" aria-controls="ps-sheet" aria-label="Open menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
            </button>
        </div>
    </div>
</header>
<div class="ps-scrim" data-ps-scrim aria-hidden="true"></div>
@include('website.partials.sheet', ['site' => $site, 'menus' => $menus, 'header' => $header])
