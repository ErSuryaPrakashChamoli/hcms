{{--
    UX.16 administrator: what needs configuration, governance or system attention. Each item is shown only to someone who
    may open the screen it summarises, and leads to that control. Platform-wide figures need a platform administrator.
--}}
@php($g = $h['governance'] ?? ['attention' => [], 'facts' => []])
@include('filament.pages.home.signals', [
    'items' => $g['attention'], 'title' => 'Governance', 'verb' => 'Review',
    'link' => \App\Filament\Pages\AdminCentre::canAccess() ? \App\Filament\Pages\AdminCentre::getUrl() : null, 'linkLabel' => 'Admin Centre',
    'emptyTitle' => 'Nothing needs governance attention.', 'emptyWhy' => 'Configuration awaiting approval, access gaps, security settings and integration, delivery or workflow failures appear here.',
])
@if ($g['facts'] !== [])
    {{-- Each fact as a tile: its own icon and hue (wayfinding only); the sign-in policy is a setting, so its value says
         whether it is required in words, coloured by what that means --}}
    @php($look = [
        'config_week' => ['heroicon-o-adjustments-horizontal', 'gold'], 'active_users' => ['heroicon-o-users', 'iris'], 'roles' => ['heroicon-o-shield-check', 'plum'],
        'mfa' => ['heroicon-o-finger-print', 'green'], 'audit_week' => ['heroicon-o-document-magnifying-glass', 'teal'],
    ])
    <x-pos.section title="At a glance">
        <div class="pos-home-glance"><div class="pos-home-tiles pos-home-tiles-standalone">
            @foreach ($g['facts'] as $f)
                @include('filament.pages.home.tile', ['f' => $f + [
                    'icon' => $look[$f['key']][0] ?? 'heroicon-o-chart-bar',
                    'hue' => $f['key'] === 'mfa' && $f['value'] !== 'Required' ? 'coral' : ($look[$f['key']][1] ?? 'slate'),
                    'meaning' => $f['key'] === 'mfa' ? ($f['value'] === 'Required' ? 'good' : 'warning') : null,
                ]])
            @endforeach
        </div></div>
    </x-pos.section>
@endif
