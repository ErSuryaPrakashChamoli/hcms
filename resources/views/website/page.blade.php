{{--
    Public website page (App\Http\Controllers\Website\SiteController). $site: SiteContent, $page: the page's array,
    $preview: platform operators looking at the draft. Every text is escaped; links go through SiteLink::href.
    Styles: resources/css/site.css (ps-*); behaviour: resources/js/site.js.
--}}
@php
    use App\Domain\Website\Support\SiteLink;
    $seo = $site->section('seo');
    $siteName = $site->name();
    $isHome = $slug === '';
    $title = filled($page['seo_title'] ?? null) ? $page['seo_title'] : ($isHome ? ($seo['title'] ?? $siteName) : ($page['title'] ?? $siteName));
    $fullTitle = $isHome ? $title : $title.' · '.$siteName;
    $description = filled($page['seo_description'] ?? null) ? $page['seo_description'] : ($seo['description'] ?? '');
    $blocks = array_values(array_filter((array) ($page['blocks'] ?? []), fn ($b) => is_array($b) && isset($b['type'])));
    $first = $blocks[0] ?? null;
    $tone = ($first['type'] ?? null) === 'hero' && (($first['data']['tone'] ?? 'ink') === 'ink') ? 'ink' : 'light';
    $indexable = ! $preview && $site->allowsIndexing();
    $canonical = url($isHome ? '/' : '/'.$slug);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $fullTitle }}</title>
    @if (filled($description))<meta name="description" content="{{ $description }}">@endif
    <meta name="robots" content="{{ $indexable ? 'index, follow' : 'noindex, nofollow' }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $fullTitle }}">
    @if (filled($description))<meta property="og:description" content="{{ $description }}">@endif
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#0e1130">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700|instrument-serif:400,400i&display=swap">
    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body class="ps-body" data-tone="{{ $tone }}">
    <a class="ps-skip" href="#ps-main">Skip to content</a>

    @if ($preview)
        <div class="ps-preview" role="status">
            <span><strong>Preview</strong> — this is the {{ app(\App\Domain\Website\Services\Website::class)->draftRevision() ? 'unpublished draft' : 'live site' }}{{ ($page['status'] ?? 'published') === 'hidden' ? ' (this page is hidden from visitors)' : '' }}. Visitors do not see these changes until you publish.</span>
            <a href="{{ \App\Filament\Pages\PlatformWebsitePage::getUrl() }}">Back to the editor</a>
        </div>
    @endif

    @include('website.partials.announce', ['announcement' => $site->section('announcement')])
    @include('website.partials.header', ['site' => $site, 'slug' => $slug, 'preview' => $preview])

    <main id="ps-main" tabindex="-1">
        @forelse ($blocks as $i => $block)
            @if (array_key_exists($block['type'], \App\Domain\Website\Support\SiteDefaults::BLOCKS))
                @include('website.blocks.'.$block['type'], ['d' => (array) ($block['data'] ?? []), 'index' => $i, 'site' => $site])
            @endif
        @empty
            <section class="ps-section"><div class="ps-wrap ps-section-head"><h1 class="ps-h2">{{ $page['title'] ?? '' }}</h1></div></section>
        @endforelse
    </main>

    @include('website.partials.footer', ['site' => $site])
</body>
</html>
