@php(
    $show = ($announcement['enabled'] ?? false) && filled($announcement['text'] ?? null)
)
@if ($show)
    <div class="ps-announce" data-ps-announce="{{ substr(md5(($announcement['text'] ?? '').($announcement['link_url'] ?? '')), 0, 10) }}" role="region" aria-label="Announcement">
        <div class="ps-announce-inner">
            @if (filled($announcement['badge'] ?? null))<span class="ps-announce-badge">{{ $announcement['badge'] }}</span>@endif
            <span>{{ $announcement['text'] }}</span>
            @if (filled($announcement['link_label'] ?? null) && filled($announcement['link_url'] ?? null))
                <a href="{{ \App\Domain\Website\Support\SiteLink::href($announcement['link_url']) }}">{{ $announcement['link_label'] }} <span aria-hidden="true">→</span></a>
            @endif
        </div>
        <button type="button" class="ps-announce-close" data-ps-announce-close aria-label="Dismiss announcement">
            {{ svg('heroicon-m-x-mark', 'ps-icon') }}
        </button>
    </div>
@endif
