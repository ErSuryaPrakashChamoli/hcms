{{-- UX.16 manager: what needs you about your team, each leading to the place to act. Your current reports only. --}}
@include('filament.pages.home.signals', [
    'items' => $h['team_signals'], 'title' => 'Your team', 'verb' => 'Open',
    'link' => \App\Filament\Pages\MyTeam::canAccess() ? \App\Filament\Pages\MyTeam::getUrl() : null, 'linkLabel' => 'My team',
    'emptyTitle' => 'Nothing about your team needs you.', 'emptyWhy' => 'Decisions, attendance exceptions, probation, reviews and team changes appear here.',
])
