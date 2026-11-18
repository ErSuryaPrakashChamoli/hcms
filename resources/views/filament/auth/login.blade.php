{{--
    Branded sign-in (HROne-style), App\Filament\Auth\Login: a split page with the sign-in form on the right.
    A browser that remembers an organisation shows its sign-in picture (or its logo) on the left and its logo and name
    over the form. Any other browser shows the PeopleOS hero. Styled in theme.css §Q (.pos-signin).
--}}
@php
    $brand = $this->branding();
    // Platform-authored copy (Platform → Public website → Sign-in page). Never throws; every value is escaped on output.
    $website = app(\App\Domain\Website\Services\Website::class);
    $signIn = $website->signIn();
    $helpUrl = \App\Domain\Website\Support\SiteLink::isAllowed($signIn['help_url'] ?? null) ? trim((string) $signIn['help_url']) : null;
    $appUrl = fn (?string $url) => is_string($url) && preg_match('~^https?://[^\s<>"\'`]+$~i', trim($url)) ? trim($url) : null;
    $apps = ($signIn['apps_enabled'] ?? false) ? array_filter(['Google Play' => $appUrl($signIn['play_store_url'] ?? null), 'App Store' => $appUrl($signIn['app_store_url'] ?? null)]) : [];
    $siteLive = $brand === null && $website->isLiveSafely();
    $notice = ($signIn['notice_enabled'] ?? false) && filled($signIn['notice_text'] ?? null);
    $initials = $brand ? \Illuminate\Support\Str::of($brand['name'])->explode(' ')->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') : '';
@endphp
<div @class(['pos-signin', 'is-branded' => $brand !== null, 'is-peopleos' => $brand === null])>
    @if ($brand === null)
        <aside class="pos-signin-art is-peopleos" aria-hidden="true">
            @include('filament.pages.home.hero-art')
            {{-- The PeopleOS mark (as filament.shell.brand, without its gradient id: the form's logo carries that one). --}}
            <div class="pos-signin-art-brand">
                <svg viewBox="0 0 32 32" focusable="false">
                    <rect width="32" height="32" rx="9" fill="#5a4dd0" />
                    <circle cx="16" cy="12" r="4.2" fill="#ffffff" />
                    <path d="M8.5 24.5c1.1-4.1 4-6.4 7.5-6.4s6.4 2.3 7.5 6.4" fill="none" stroke="#e2c48c" stroke-width="2.4" stroke-linecap="round" />
                </svg>
                <span>People<b>OS</b></span>
            </div>
            <div class="pos-signin-art-copy">
                {{-- Platform → Public website → Sign-in page (Website::signIn(); the defaults are this wording) --}}
                <p class="pos-signin-art-title">{{ $signIn['headline'] ?? '' }}@if (filled($signIn['highlight'] ?? null))<br><em>{{ $signIn['highlight'] }}</em>@endif</p>
                @if (filled($signIn['text'] ?? null))<p class="pos-signin-art-lead">{{ $signIn['text'] }}</p>@endif
            </div>
        </aside>
    @elseif ($brand['picture'])
        <aside class="pos-signin-art is-picture" aria-hidden="true">
            <img class="pos-signin-picture" src="{{ $brand['picture'] }}" alt="" />
        </aside>
    @else
        <aside class="pos-signin-art is-logo" aria-hidden="true">
            @if ($brand['logo'])
                <img class="pos-signin-stage-logo" src="{{ $brand['logo'] }}" alt="" />
            @else
                <span class="pos-signin-stage-monogram">{{ $initials }}</span>
            @endif
        </aside>
    @endif

    <div class="pos-signin-form">
        @if ($brand === null)
            {{-- A faint vertical PeopleOS wordmark along the form panel's outer edge (organisation sign-ins keep their own brand). --}}
            <span class="pos-signin-watermark" aria-hidden="true">People<b>OS</b></span>
        @endif
        @if ($brand !== null)
            <div class="pos-signin-org">
                @if ($brand['logo'])
                    <img class="pos-signin-org-logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" />
                @else
                    <span class="pos-signin-org-monogram" aria-hidden="true">{{ $initials }}</span>
                @endif
            </div>
        @endif

        @if ($notice)
            <div class="pos-signin-notice" data-tone="{{ ($signIn['notice_tone'] ?? 'info') === 'warning' ? 'warning' : 'info' }}" role="status">
                <x-filament::icon :icon="($signIn['notice_tone'] ?? 'info') === 'warning' ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-information-circle'" class="size-5 shrink-0" />
                <span>{{ $signIn['notice_text'] }}</span>
            </div>
        @endif

        <x-filament-panels::page.simple>
            {{ $this->content }}
        </x-filament-panels::page.simple>

        @if (($helpUrl && filled($signIn['help_label'] ?? null)) || $apps !== [] || $siteLive)
            <div class="pos-signin-extras">
                @if (($helpUrl && filled($signIn['help_label'] ?? null)) || $siteLive)
                    <p class="pos-signin-help">
                        @if ($helpUrl && filled($signIn['help_label'] ?? null))
                            <a href="{{ $helpUrl }}" @if (str_starts_with($helpUrl, 'http')) target="_blank" rel="noopener" @endif>{{ $signIn['help_label'] }}</a>
                        @endif
                        @if ($siteLive)
                            <a href="{{ url('/') }}" class="pos-signin-site"><x-filament::icon icon="heroicon-m-arrow-left" class="size-4" /> Back to the website</a>
                        @endif
                    </p>
                @endif
                @if ($apps !== [])
                    <div class="pos-signin-apps" role="group" aria-label="Get the mobile app">
                        <span class="pos-signin-apps-label">Get the mobile app</span>
                        <div class="pos-signin-apps-row">
                            @foreach ($apps as $store => $url)
                                <a class="pos-signin-app" href="{{ $url }}" target="_blank" rel="noopener">
                                    <x-filament::icon icon="heroicon-m-device-phone-mobile" class="size-5" />
                                    <span><small>{{ $store === 'App Store' ? 'Download on the' : 'Get it on' }}</small>{{ $store }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if ($brand !== null)
            <div class="pos-signin-foot">
                <button type="button" class="pos-signin-switch" wire:click="forgetWorkspace">Not {{ $brand['name'] }}? Use another organisation</button>
                <span class="pos-signin-powered">Powered by <strong>PeopleOS</strong></span>
            </div>
        @endif
    </div>
</div>
