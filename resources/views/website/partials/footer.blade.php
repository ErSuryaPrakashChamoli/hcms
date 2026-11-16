@php
    use App\Domain\Website\Support\SiteLink;
    $footer = $site->section('footer');
    $columns = array_values(array_filter((array) ($footer['columns'] ?? []), fn ($c) => is_array($c) && filled($c['heading'] ?? null)));
    $social = array_values(array_filter((array) ($footer['social'] ?? []), fn ($s) => is_array($s) && SiteLink::isAllowed($s['url'] ?? null)));
    $icons = [
        'linkedin' => '<path d="M4.98 3.5A2.5 2.5 0 1 1 5 8.5a2.5 2.5 0 0 1-.02-5ZM3 9.75h4V21H3V9.75Zm6.5 0h3.8v1.6h.06c.53-1 1.84-2.06 3.78-2.06 4.04 0 4.79 2.62 4.79 6.03V21h-4v-5.1c0-1.22-.02-2.78-1.7-2.78-1.7 0-1.95 1.32-1.95 2.69V21h-4V9.75Z"/>',
        'x' => '<path d="M17.53 3H20.6l-6.72 7.68L21.8 21h-6.19l-4.85-6.34L5.2 21H2.13l7.19-8.21L1.7 3h6.35l4.38 5.79L17.53 3Zm-1.08 16.18h1.7L7.65 4.73H5.83l10.62 14.45Z"/>',
        'youtube' => '<path d="M23 7.2a3 3 0 0 0-2.1-2.1C19 4.6 12 4.6 12 4.6s-7 0-8.9.5A3 3 0 0 0 1 7.2 31 31 0 0 0 .5 12a31 31 0 0 0 .5 4.8 3 3 0 0 0 2.1 2.1c1.9.5 8.9.5 8.9.5s7 0 8.9-.5a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .5-4.8 31 31 0 0 0-.5-4.8ZM9.75 15.02V8.98L15.5 12l-5.75 3.02Z"/>',
        'instagram' => '<path d="M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4Zm0 7.75a3.05 3.05 0 1 1 0-6.1 3.05 3.05 0 0 1 0 6.1Zm6-7.94a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0ZM21.4 8.2c-.06-1.33-.36-2.5-1.34-3.47C19.1 3.76 17.93 3.46 16.6 3.4c-1.37-.08-5.48-.08-6.85 0-1.33.06-2.5.36-3.47 1.33C5.3 5.7 5 6.87 4.94 8.2c-.08 1.37-.08 5.48 0 6.85.06 1.33.36 2.5 1.34 3.47.97.97 2.14 1.27 3.47 1.34 1.37.08 5.48.08 6.85 0 1.33-.06 2.5-.36 3.47-1.34.97-.97 1.27-2.14 1.34-3.47.08-1.37.08-5.48 0-6.85Z"/>',
        'facebook' => '<path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z"/>',
    ];
@endphp
<footer class="ps-footer">
    <div class="ps-wrap">
        <div class="ps-footer-top" style="--cols: {{ max(1, count($columns)) }}">
            <div>
                @include('website.partials.brand', ['site' => $site])
                @if (filled($footer['blurb'] ?? null))<p class="ps-footer-blurb">{{ $footer['blurb'] }}</p>@endif
                @php($site_ = $site->section('site'))
                @if (filled($site_['contact_email'] ?? null) || filled($site_['contact_phone'] ?? null))
                    <ul style="margin-top:18px">
                        @if (filled($site_['contact_email'] ?? null))<li><a href="mailto:{{ $site_['contact_email'] }}">{{ $site_['contact_email'] }}</a></li>@endif
                        @if (filled($site_['contact_phone'] ?? null))<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $site_['contact_phone']) }}">{{ $site_['contact_phone'] }}</a></li>@endif
                    </ul>
                @endif
            </div>
            @foreach ($columns as $column)
                <nav aria-label="{{ $column['heading'] }}">
                    <h3>{{ $column['heading'] }}</h3>
                    <ul>
                        @foreach ((array) ($column['links'] ?? []) as $link)
                            @continue(! is_array($link) || blank($link['label'] ?? null))
                            <li><a href="{{ SiteLink::href($link['url'] ?? '') }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>
        <div class="ps-footer-bottom">
            <span>{{ $site->legal() }}</span>
            @if (! empty($footer['legal_links']))
                <ul>
                    @foreach ((array) $footer['legal_links'] as $link)
                        @continue(! is_array($link) || blank($link['label'] ?? null))
                        <li><a href="{{ SiteLink::href($link['url'] ?? '') }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            @endif
            @if ($social !== [])
                <div class="ps-social">
                    @foreach ($social as $s)
                        <a href="{{ SiteLink::href($s['url']) }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($s['network'] ?? 'Link') }}">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">{!! $icons[$s['network'] ?? ''] ?? '<circle cx="12" cy="12" r="5"/>' !!}</svg>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <div class="ps-footer-word" aria-hidden="true">{{ $site->name() }}</div>
</footer>
