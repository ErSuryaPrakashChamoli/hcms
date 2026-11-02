{{-- UX.16 manager: what needs you about your team, each leading to the place to act. Your current reports only.
     UX.19: decisions are listed once, in Decisions above; this block carries the team's other exceptions. --}}
@include('filament.pages.home.signals', [
    'items' => $h['team_signals'], 'title' => 'Your team', 'verb' => 'Open',
    'link' => \App\Filament\Pages\MyTeam::canAccess() ? \App\Filament\Pages\MyTeam::getUrl() : null, 'linkLabel' => 'My team',
    'emptyTitle' => 'Nothing else about your team needs you.', 'emptyWhy' => 'Attendance exceptions, probation, reviews and team changes appear here. Decisions are listed under Decisions.',
])
{{-- HROne parity RQ-4 (§20.4.2 #6): the team's planned leave, shown only when there is some. A private leave reads "Away"
     for a viewer who may not see it in full; reasons are never shown. --}}
@if (! empty($h['team']['planned'] ?? []))
    <x-pos.section title="Team planned leaves" :count="count($h['team']['planned'])" :link="$h['team']['links']['calendar'] ?? null" link-label="See more">
        <div class="pos-panel pos-stream">
            @foreach ($h['team']['planned'] as $p)
                <div class="pos-stream-row" data-tone="info" wire:key="planned-{{ $loop->index }}">
                    <span class="pos-stream-mark" aria-hidden="true"></span>
                    <div class="pos-stream-body">
                        <p class="pos-stream-title">{{ $p['name'] }}</p>
                        <p class="pos-stream-meta">{{ $p['type'] }} · {{ $p['dates'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-pos.section>
@endif
