{{-- UX.16 manager: what needs you about your team, each leading to the place to act. Your current reports only.
     UX.19: decisions are listed once, in Decisions above; this block carries the team's other exceptions. --}}
@include('filament.pages.home.signals', [
    'items' => $h['team_signals'], 'title' => 'Your team', 'verb' => 'Open',
    'link' => \App\Filament\Pages\MyTeam::canAccess() ? \App\Filament\Pages\MyTeam::getUrl() : null, 'linkLabel' => 'My team',
    'emptyTitle' => 'Nothing else about your team needs you.', 'emptyWhy' => 'Attendance exceptions, probation, reviews and team changes appear here. Decisions are listed under Decisions.',
])
