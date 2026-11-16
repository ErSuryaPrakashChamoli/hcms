{{--
    Platform → Public website (App\Filament\Pages\PlatformWebsitePage). The masthead says what is live and what the draft
    changes, with Preview / Publish / Discard; the areas run down the left and the open one fills the panel beside them.
    Every edit opens a short wizard and saves to the draft; nothing reaches visitors before Publish.
--}}
<x-filament-panels::page>
    @php
        $status = $this->status();
        $draft = $this->draft();
        $areas = $this->areas();
        $live = $status['live'];
        $changed = $status['changed'];
        $menus = $draft->section('header')['menus'] ?? [];
        $pages = $draft->pages();
        $newEnquiries = $this->newEnquiries();
        $brief = $live === null
            ? 'Not live yet: "/" still opens the workspace sign-in. Edit the draft, preview it, then publish to put the website on '.url('/').'.'
            : (! $status['enabled'] ? 'Published but switched off (Site and brand): "/" opens the workspace sign-in.' : 'Live at '.url('/').' since '.$live->published_at?->format('j M Y, H:i').($live->publisher ? ' (published by '.$live->publisher->name.')' : '').'.');
    @endphp

    <x-pos.masthead icon="heroicon-m-globe-alt" eyebrow="Platform" title="Public website" :brief="$brief">
        <div class="mt-4 flex flex-wrap items-center gap-2">
            {{ $this->publishAction }}
            {{ $this->previewAction }}
            {{ $this->discardAction }}
        </div>
        @if ($status['draft'])
            <p class="pos-ws-brief mt-3 text-sm">
                <strong>Unpublished changes:</strong> {{ $changed === [] ? 'none — the draft matches the live site' : implode(', ', $changed) }}.
                Last edit {{ $status['draft']->updated_at?->diffForHumans() }}{{ $status['draft']->editor ? ' by '.$status['draft']->editor->name : '' }}.
            </p>
        @endif
        <x-slot:glance>
            <x-pos.glance label="Website at a glance" :rows="[
                ['label' => 'Status', 'value' => $live === null ? 'Not live' : ($status['enabled'] ? 'Live' : 'Off'), 'icon' => 'heroicon-m-signal', 'tone' => $live !== null && $status['enabled'] ? 'success' : 'neutral'],
                ['label' => 'Unpublished areas', 'value' => count($changed), 'icon' => 'heroicon-m-pencil-square', 'tone' => $changed === [] ? 'success' : 'info', 'meta' => $changed === [] ? 'Nothing waiting' : 'Waiting for Publish'],
                ['label' => 'Pages', 'value' => count($pages), 'icon' => 'heroicon-m-document-duplicate', 'tone' => 'neutral', 'click' => 'open(\'pages\')'],
                ['label' => 'New enquiries', 'value' => $newEnquiries, 'icon' => 'heroicon-m-inbox', 'tone' => $newEnquiries > 0 ? 'warning' : 'success', 'click' => 'open(\'enquiries\')'],
            ]" />
        </x-slot:glance>
    </x-pos.masthead>

    <div class="pos-site-editor">
        <nav class="pos-site-areas" aria-label="Website areas">
            @foreach ($areas as $key => $meta)
                <button type="button" wire:click="open('{{ $key }}')" class="pos-site-area" aria-current="{{ $area === $key ? 'true' : 'false' }}">
                    <x-filament::icon :icon="$meta['icon']" class="size-5 shrink-0" />
                    <span class="min-w-0">
                        <span class="pos-site-area-label">{{ $meta['label'] }}
                            @if ($this->areaChanged($key))<span class="pos-site-dot" title="Unpublished changes"><span class="sr-only">(unpublished changes)</span></span>@endif
                            @if ($key === 'enquiries' && $newEnquiries > 0)<span class="pos-count" data-tone="warning">{{ $newEnquiries }}</span>@endif
                        </span>
                        <span class="pos-site-area-sub">{{ $meta['sub'] }}</span>
                    </span>
                </button>
            @endforeach
        </nav>

        <section class="pos-panel pos-site-panel" aria-labelledby="site-area-title">
            <header class="pos-site-panel-head">
                <div class="min-w-0">
                    <h2 id="site-area-title" class="pos-browse-panel-title">{{ $areas[$area]['label'] }}</h2>
                    <p class="pos-browse-panel-sub">{{ $areas[$area]['sub'] }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @switch($area)
                        @case('pages') {{ $this->addPageAction }} @break
                        @case('header') {{ $this->editHeaderAction }} {{ $this->addMenuAction }} @break
                        @case('announcement') {{ $this->editAnnouncementAction }} @break
                        @case('footer') {{ $this->editFooterAction }} @break
                        @case('sign_in') {{ $this->editSignInAction }} @break
                        @case('site') {{ $this->editSiteAction }} @break
                        @case('seo') {{ $this->editSeoAction }} @break
                    @endswitch
                </div>
            </header>

            <div class="pos-site-panel-body">
                @if ($area === 'pages')
                    <ul class="pos-site-rows" role="list">
                        @foreach ($pages as $page)
                            @php($slug = trim((string) ($page['slug'] ?? ''), '/'))
                            <li class="pos-site-row" wire:key="page-{{ $slug ?: 'home' }}">
                                <div class="min-w-0 flex-1">
                                    <p class="pos-site-row-title">
                                        {{ $page['title'] ?? 'Untitled' }}
                                        @if (($page['status'] ?? 'published') === 'hidden')<x-filament::badge color="gray" size="sm">Hidden</x-filament::badge>@endif
                                        @if ($slug === '')<x-filament::badge color="primary" size="sm">Home</x-filament::badge>@endif
                                    </p>
                                    <p class="pos-site-row-meta"><span class="font-mono">/{{ $slug }}</span> · {{ $this->blockSummary($page) }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <a href="{{ $this->previewUrl($slug) }}" target="_blank" rel="noopener" class="pos-site-icon-link" title="Preview /{{ $slug }}"><x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="size-4" /><span class="sr-only">Preview {{ $page['title'] ?? '' }}</span></a>
                                    {{ ($this->editPageAction)(['slug' => $slug]) }}
                                    {{ ($this->duplicatePageAction)(['slug' => $slug]) }}
                                    @if ($slug !== ''){{ ($this->deletePageAction)(['slug' => $slug]) }}@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($area === 'header')
                    @php($header = $draft->section('header'))
                    <p class="pos-site-note">Buttons: <strong>{{ $header['sign_in_label'] ?: '— no sign-in button' }}</strong> (opens sign-in) and <strong>{{ $header['cta_label'] ?: '— no main button' }}</strong>@if (filled($header['cta_label'])) → <span class="font-mono">{{ $header['cta_url'] }}</span>@endif. Menus appear in this order; on phones they fold into the menu sheet.</p>
                    <ul class="pos-site-rows" role="list">
                        @forelse ($menus as $i => $menu)
                            @php($columns = array_values(array_filter((array) ($menu['columns'] ?? []), fn ($c) => is_array($c) && ! empty($c['links']))))
                            @php($linkCount = array_sum(array_map(fn ($c) => count((array) $c['links']), $columns)))
                            <li class="pos-site-row" wire:key="menu-{{ $i }}-{{ md5($menu['label'] ?? '') }}">
                                <div class="min-w-0 flex-1">
                                    <p class="pos-site-row-title">{{ $menu['label'] ?? '' }}</p>
                                    <p class="pos-site-row-meta">
                                        @if ($columns === []) Link to <span class="font-mono">{{ $menu['url'] ?: '—' }}</span>
                                        @else {{ count($columns) }} {{ str('column')->plural(count($columns)) }} · {{ $linkCount }} {{ str('link')->plural($linkCount) }}@if ($menu['feature']['enabled'] ?? false) · feature card “{{ $menu['feature']['title'] ?? '' }}”@endif
                                        @endif
                                    </p>
                                    @if ($columns !== [])
                                        <p class="pos-site-row-links">@foreach ($columns as $c){{ $c['heading'] ?: 'Links' }}: {{ collect($c['links'])->pluck('label')->filter()->implode(', ') }}@if (! $loop->last) <span aria-hidden="true">·</span> @endif @endforeach</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    @if ($i > 0){{ ($this->moveMenuAction)(['index' => $i, 'by' => -1]) }}@endif
                                    @if ($i < count($menus) - 1){{ ($this->moveMenuAction)(['index' => $i, 'by' => 1]) }}@endif
                                    {{ ($this->editMenuAction)(['index' => $i]) }}
                                    {{ ($this->deleteMenuAction)(['index' => $i]) }}
                                </div>
                            </li>
                        @empty
                            <li class="pos-site-note">No menus: the header shows only the wordmark and buttons. Add menu to create one.</li>
                        @endforelse
                    </ul>
                @elseif ($area === 'announcement')
                    @php($a = $draft->section('announcement'))
                    <div class="pos-site-mock-announce" @if (! ($a['enabled'] ?? false)) data-off @endif>
                        @if (filled($a['badge'] ?? null))<span class="pos-site-mock-badge">{{ $a['badge'] }}</span>@endif
                        <span>{{ $a['text'] ?: 'No message' }}</span>
                        @if (filled($a['link_label'] ?? null))<span class="pos-site-mock-link">{{ $a['link_label'] }} →</span>@endif
                    </div>
                    <p class="pos-site-note">{{ ($a['enabled'] ?? false) ? 'Shown above the header on every page. Visitors can dismiss it; a new message shows again.' : 'Hidden. Turn it on in Edit announcement.' }}</p>
                @elseif ($area === 'footer')
                    @php($f = $draft->section('footer'))
                    <p class="pos-site-note">{{ $f['blurb'] ?? '' }}</p>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ((array) ($f['columns'] ?? []) as $c)
                            <div><p class="pos-site-row-title">{{ $c['heading'] ?? '' }}</p><p class="pos-site-row-meta">{{ collect($c['links'] ?? [])->pluck('label')->filter()->implode(' · ') }}</p></div>
                        @endforeach
                    </div>
                    <p class="pos-site-note mt-4">Bottom line: {{ $draft->legal() }}@if (! empty($f['legal_links'])) · {{ collect($f['legal_links'])->pluck('label')->implode(' · ') }}@endif @if (! empty($f['social'])) · {{ count($f['social']) }} social {{ str('profile')->plural(count($f['social'])) }}@endif</p>
                @elseif ($area === 'sign_in')
                    @php($s = $draft->signIn())
                    <div class="pos-site-signin">
                        <div class="pos-site-signin-art">
                            <p class="pos-site-signin-title">{{ $s['headline'] ?? '' }}@if (filled($s['highlight'] ?? null))<br><em>{{ $s['highlight'] }}</em>@endif</p>
                            <p class="pos-site-signin-lead">{{ $s['text'] ?? '' }}</p>
                        </div>
                        <div class="pos-site-signin-form">
                            @if ($s['notice_enabled'] ?? false)<p class="pos-site-signin-notice" data-tone="{{ $s['notice_tone'] ?? 'info' }}">{{ $s['notice_text'] ?? '' }}</p>@endif
                            <p class="pos-site-row-title">Sign in to PeopleOS</p>
                            <span class="pos-site-signin-field"></span><span class="pos-site-signin-field"></span><span class="pos-site-signin-button"></span>
                            <p class="pos-site-row-meta">
                                {{ filled($s['help_url'] ?? null) ? ($s['help_label'] ?: 'Help').' → '.$s['help_url'] : 'No help link' }}
                                · {{ ($s['apps_enabled'] ?? false) ? 'App store badges on' : 'No app badges' }}
                            </p>
                        </div>
                    </div>
                    <p class="pos-site-note">This is the PeopleOS sign-in page. A browser that remembers an organisation shows that organisation's own branding instead (each organisation sets it in Setup → Core HR → Sign-in page); the notice and the help and app links show on both.</p>
                @elseif ($area === 'site')
                    @php($site = $draft->section('site'))
                    <dl class="pos-site-facts">
                        <div><dt>Website</dt><dd>{{ ($site['enabled'] ?? false) ? 'On (when published)' : 'Off: "/" goes to sign-in' }}</dd></div>
                        <div><dt>Name</dt><dd>{{ $site['name'] ?? '' }}</dd></div>
                        <div><dt>Tagline</dt><dd>{{ $site['tagline'] ?: '—' }}</dd></div>
                        <div><dt>Sales e-mail</dt><dd>{{ $site['contact_email'] ?: '—' }}</dd></div>
                        <div><dt>Sales phone</dt><dd>{{ $site['contact_phone'] ?: '—' }}</dd></div>
                    </dl>
                @elseif ($area === 'seo')
                    @php($seo = $draft->section('seo'))
                    <dl class="pos-site-facts">
                        <div><dt>Home page title</dt><dd>{{ $seo['title'] ?? '' }}</dd></div>
                        <div><dt>Description</dt><dd>{{ $seo['description'] ?: '—' }}</dd></div>
                        <div><dt>Search engines</dt><dd>{{ ($seo['allow_indexing'] ?? false) ? 'May list the published site pages (sitemap at /sitemap.xml)' : 'Kept out: every page says noindex and robots.txt disallows everything' }}</dd></div>
                    </dl>
                    <p class="pos-site-note">The workspace (/admin and everything after sign-in) is never indexed, whatever this says.</p>
                @elseif ($area === 'enquiries')
                    <div class="mb-3 flex flex-wrap gap-2" role="group" aria-label="Show enquiries">
                        @foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed', 'all' => 'All'] as $key => $label)
                            <button type="button" class="pos-chip" aria-pressed="{{ $enquiryFilter === $key ? 'true' : 'false' }}" wire:click="filterEnquiries('{{ $key }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                    @php($rows = $this->enquiries())
                    @if ($rows->isEmpty())
                        <p class="pos-site-note">No {{ $enquiryFilter === 'all' ? '' : $enquiryFilter.' ' }}enquiries. Visitors send them from the contact form (Talk to sales).</p>
                    @else
                        <ul class="pos-site-rows" role="list">
                            @foreach ($rows as $enquiry)
                                <li class="pos-site-row" wire:key="enq-{{ $enquiry->id }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="pos-site-row-title">{{ $enquiry->name }} <span class="font-normal text-gray-500">· {{ $enquiry->company ?: 'No company given' }}</span>
                                            <x-filament::badge :color="$enquiry->status->color()" size="sm">{{ $enquiry->status->label() }}</x-filament::badge></p>
                                        <p class="pos-site-row-meta">
                                            <a href="mailto:{{ $enquiry->email }}" class="underline">{{ $enquiry->email }}</a>@if ($enquiry->phone) · <a href="tel:{{ $enquiry->phone }}" class="underline">{{ $enquiry->phone }}</a>@if ($enquiry->phone_verified_at) <x-filament::badge color="success" size="sm" icon="heroicon-m-check-badge">Verified</x-filament::badge>@else <x-filament::badge color="gray" size="sm">Not verified</x-filament::badge>@endif @endif
                                            @if ($enquiry->team_size) · {{ \App\Domain\Website\Services\Enquiries::TEAM_SIZES[$enquiry->team_size] ?? $enquiry->team_size }} employees @endif
                                            · {{ $enquiry->created_at?->format('j M Y, H:i') }}@if ($enquiry->source_path) · from <span class="font-mono">{{ $enquiry->source_path }}</span>@endif
                                        </p>
                                        @if ($enquiry->message)<p class="pos-site-row-links whitespace-pre-line">{{ $enquiry->message }}</p>@endif
                                        @if ($enquiry->handler)<p class="pos-site-row-meta">{{ $enquiry->status->label() }} by {{ $enquiry->handler->name }} · {{ $enquiry->handled_at?->diffForHumans() }}</p>@endif
                                    </div>
                                    <div class="shrink-0">{{ ($this->enquiryStatusAction)(['enquiry' => $enquiry->id]) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @elseif ($area === 'history')
                    @php($versions = $this->history())
                    @if ($versions->isEmpty())
                        <p class="pos-site-note">Nothing has been published yet.</p>
                    @else
                        <ul class="pos-site-rows" role="list">
                            @foreach ($versions as $version)
                                <li class="pos-site-row" wire:key="rev-{{ $version->id }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="pos-site-row-title">{{ $version->published_at?->format('j M Y, H:i') }}
                                            <x-filament::badge :color="$version->status === \App\Domain\Website\Enums\RevisionStatus::Published ? 'success' : 'gray'" size="sm">{{ $version->status->label() }}</x-filament::badge></p>
                                        <p class="pos-site-row-meta">{{ $version->publisher?->name ?? 'Unknown' }}@if ($version->note) · “{{ $version->note }}”@endif</p>
                                    </div>
                                    <div class="shrink-0">{{ ($this->restoreAction)(['revision' => $version->id]) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        </section>
    </div>
</x-filament-panels::page>
