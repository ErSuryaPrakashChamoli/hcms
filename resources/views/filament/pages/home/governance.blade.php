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
    <x-pos.section title="At a glance">
        <div class="pos-panel pos-panel-pad pos-figures">
            @foreach ($g['facts'] as $f)
                <x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url']" />
            @endforeach
        </div>
    </x-pos.section>
@endif
