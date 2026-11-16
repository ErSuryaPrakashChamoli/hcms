{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($slugs as $slug)
    <url><loc>{{ url($slug === '' ? '/' : '/'.$slug) }}</loc>@if ($updated)<lastmod>{{ $updated }}</lastmod>@endif</url>
@endforeach
</urlset>
