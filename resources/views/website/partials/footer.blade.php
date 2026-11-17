{{--
    The site footer, in three bands on the ink background:
      lead    the wordmark, blurb and social profiles on the left; the sales e-mail and phone as contact cards on the right
      links   the link columns across the full width (their own row, so a long e-mail address can never run into them)
      bottom  the copyright line and legal links
    Contact details come from Website → Site (contact_email, contact_phone); social profiles from Website → Footer.
--}}
@php
    use App\Domain\Website\Support\SiteLink;
    $footer = $site->section('footer');
    $contact = $site->section('site');
    $email = trim((string) ($contact['contact_email'] ?? ''));
    $phone = trim((string) ($contact['contact_phone'] ?? ''));
    $columns = array_values(array_filter((array) ($footer['columns'] ?? []), fn ($c) => is_array($c) && filled($c['heading'] ?? null)));
    $social = array_values(array_filter((array) ($footer['social'] ?? []), fn ($s) => is_array($s) && SiteLink::isAllowed($s['url'] ?? null)));
    $networks = ['linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'instagram' => 'Instagram', 'facebook' => 'Facebook'];
    $icons = [
        'linkedin' => '<path d="M4.98 3.5A2.5 2.5 0 1 1 5 8.5a2.5 2.5 0 0 1-.02-5ZM3 9.75h4V21H3V9.75Zm6.5 0h3.8v1.6h.06c.53-1 1.84-2.06 3.78-2.06 4.04 0 4.79 2.62 4.79 6.03V21h-4v-5.1c0-1.22-.02-2.78-1.7-2.78-1.7 0-1.95 1.32-1.95 2.69V21h-4V9.75Z"/>',
        'x' => '<path d="M17.53 3H20.6l-6.72 7.68L21.8 21h-6.19l-4.85-6.34L5.2 21H2.13l7.19-8.21L1.7 3h6.35l4.38 5.79L17.53 3Zm-1.08 16.18h1.7L7.65 4.73H5.83l10.62 14.45Z"/>',
        'youtube' => '<path d="M23 7.2a3 3 0 0 0-2.1-2.1C19 4.6 12 4.6 12 4.6s-7 0-8.9.5A3 3 0 0 0 1 7.2 31 31 0 0 0 .5 12a31 31 0 0 0 .5 4.8 3 3 0 0 0 2.1 2.1c1.9.5 8.9.5 8.9.5s7 0 8.9-.5a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .5-4.8 31 31 0 0 0-.5-4.8ZM9.75 15.02V8.98L15.5 12l-5.75 3.02Z"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5.2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4.1" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.4" cy="6.6" r="1.25"/>',
        'facebook' => '<path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z"/>',
    ];
@endphp
<footer class="ps-footer">
    <div class="ps-wrap">
        <div class="ps-footer-lead">
            <div class="ps-footer-about">
                @include('website.partials.brand', ['site' => $site])
                @if (filled($footer['blurb'] ?? null))<p class="ps-footer-blurb">{{ $footer['blurb'] }}</p>@endif
                @if ($social !== [])
                    <ul class="ps-social" aria-label="Follow {{ $site->name() }}">
                        @foreach ($social as $s)
                            @php($network = $networks[$s['network'] ?? ''] ?? 'Profile')
                            <li>
                                <a href="{{ SiteLink::href($s['url']) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $site->name() }} on {{ $network }}" title="{{ $network }}">
                                    <svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" aria-hidden="true" focusable="false">{!! $icons[$s['network'] ?? ''] ?? '<circle cx="12" cy="12" r="5"/>' !!}</svg>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @if ($email !== '' || $phone !== '')
                <ul class="ps-footer-contact" aria-label="Contact">
                    @if ($email !== '')
                        <li>
                            <a class="ps-footer-card" href="mailto:{{ $email }}">
                                <span class="ps-footer-card-icon">{{ svg('heroicon-o-envelope', 'ps-icon') }}</span>
                                <span class="ps-footer-card-text"><small>E-mail us</small><strong>{{ $email }}</strong></span>
                            </a>
                        </li>
                    @endif
                    @if ($phone !== '')
                        <li>
                            <a class="ps-footer-card" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">
                                <span class="ps-footer-card-icon">{{ svg('heroicon-o-phone', 'ps-icon') }}</span>
                                <span class="ps-footer-card-text"><small>Call us</small><strong>{{ $phone }}</strong></span>
                            </a>
                        </li>
                    @endif
                </ul>
            @endif
        </div>
        @if ($columns !== [])
            <div class="ps-footer-top" style="--cols: {{ count($columns) }}">
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
        @endif
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
        </div>
    </div>
    <div class="ps-footer-word" aria-hidden="true">{{ $site->name() }}</div>
</footer>
